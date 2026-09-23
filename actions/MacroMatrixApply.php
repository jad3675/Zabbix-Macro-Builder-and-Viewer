<?php declare(strict_types = 0);

namespace Modules\MacroMatrix\Actions;

use API,
	Modules\MacroMatrix\Lib\MacroKey;

/**
 * Writes staged macro changes.
 *
 * Every update and delete carries what the browser last saw ("expect"). Before writing, the current state is read
 * back; an operation whose target changed in the meantime is reported as a conflict and skipped, never forced. With
 * dry_run=1 only that check runs, so the diff can show conflicts before anything is written.
 *
 * Order: deletes, then updates, then creates, in chunks. Each chunk is one API call and therefore one transaction;
 * a failed chunk does not roll back chunks that already succeeded, and the response says which is which.
 */
class MacroMatrixApply extends MacroMatrixJsonAction {

	private const CHUNK = 500;
	private const MAX_OPS = 20000;

	private array $ops = [];

	protected function checkInput(): bool {
		$ret = $this->validateJson([
			'ops' => 'required|array',
			'dry_run' => 'in 0,1'
		]);

		if (!$ret) {
			return false;
		}

		$errors = [];
		$raw_ops = $this->getInput('ops');

		if (count($raw_ops) > self::MAX_OPS) {
			$this->respondError(_s('Too many changes in one apply (maximum %1$s).', self::MAX_OPS));

			return false;
		}

		$creates = [];

		foreach (array_values($raw_ops) as $i => $op) {
			$op = is_array($op) ? $op : [];
			$error = $this->normalizeOp($op, $i);

			if ($error !== null) {
				$errors[] = $error;
				continue;
			}

			if ($op['action'] === 'create') {
				$key = $op['hostid'].'|'.$op['macro'];

				if (array_key_exists($key, $creates)) {
					$errors[] = _s('Change %1$s: %2$s is created twice on the same object.', $i + 1, $op['macro']);
					continue;
				}

				$creates[$key] = true;
			}

			$this->ops[] = $op;
		}

		if ($errors) {
			$this->respondError(_('Invalid changes.'), array_slice($errors, 0, 20));

			return false;
		}

		return true;
	}

	/**
	 * Validates one operation and normalizes it in place.
	 *
	 * @return string|null  Error message.
	 */
	private function normalizeOp(array &$op, int $i): ?string {
		$n = $i + 1;
		$action = $op['action'] ?? null;
		$types = [ZBX_MACRO_TYPE_TEXT, ZBX_MACRO_TYPE_SECRET, ZBX_MACRO_TYPE_VAULT];

		$clean = [
			'key' => is_scalar($op['key'] ?? null) ? (string) $op['key'] : (string) $i,
			'action' => $action
		];

		if ($action === 'create') {
			if (!self::isId($op['hostid'] ?? null)) {
				return _s('Change %1$s: invalid object.', $n);
			}

			$parsed = is_string($op['macro'] ?? null) ? MacroKey::parse($op['macro']) : null;

			if ($parsed === null) {
				return _s('Change %1$s: invalid macro.', $n);
			}

			if (!is_string($op['value'] ?? null)) {
				return _s('Change %1$s: %2$s needs a value.', $n, $parsed['macro']);
			}

			$clean += [
				'hostid' => (string) $op['hostid'],
				'macro' => $parsed['macro'],
				'value' => $op['value']
			];
		}
		elseif ($action === 'update' || $action === 'delete') {
			if (!self::isId($op['hostmacroid'] ?? null)) {
				return _s('Change %1$s: invalid macro ID.', $n);
			}

			$expect = $op['expect'] ?? null;

			if (!is_array($expect) || !is_string($expect['macro'] ?? null)
					|| !in_array((int) ($expect['type'] ?? -1), $types, true)
					|| !is_string($expect['description'] ?? null)
					|| (array_key_exists('value', $expect) && !is_string($expect['value']))) {
				return _s('Change %1$s: missing or invalid expected state.', $n);
			}

			$clean += [
				'hostmacroid' => (string) $op['hostmacroid'],
				'expect' => [
					'macro' => $expect['macro'],
					'type' => (int) $expect['type'],
					'description' => $expect['description']
				] + (array_key_exists('value', $expect) ? ['value' => $expect['value']] : [])
			];

			if ($action === 'update' && array_key_exists('value', $op)) {
				if (!is_string($op['value'])) {
					return _s('Change %1$s: invalid value.', $n);
				}

				$clean['value'] = $op['value'];
			}
		}
		else {
			return _s('Change %1$s: unknown action.', $n);
		}

		if ($action !== 'delete') {
			if (!in_array((int) ($op['type'] ?? -1), $types, true)) {
				return _s('Change %1$s: invalid type.', $n);
			}

			if (!is_string($op['description'] ?? '')) {
				return _s('Change %1$s: invalid description.', $n);
			}

			$clean['type'] = (int) $op['type'];
			$clean['description'] = $op['description'] ?? '';
		}

		$op = $clean;

		return null;
	}

	protected function doAction(): void {
		$dry_run = $this->getInput('dry_run', '0') === '1';
		$results = [];
		$current = $this->loadCurrent();

		$runnable = ['delete' => [], 'update' => [], 'create' => []];

		foreach ($this->ops as $op) {
			$conflict = $this->checkConflict($op, $current);

			if ($conflict !== null) {
				$results[$op['key']] = ['key' => $op['key'], 'status' => 'conflict', 'message' => $conflict];
				continue;
			}

			if ($dry_run) {
				$results[$op['key']] = ['key' => $op['key'], 'status' => 'ok'];
				continue;
			}

			$runnable[$op['action']][] = $op;
		}

		if (!$dry_run) {
			foreach ($runnable as $action => $ops) {
				foreach (array_chunk($ops, self::CHUNK) as $chunk) {
					foreach ($this->runChunk($action, $chunk, $current) as $result) {
						$results[$result['key']] = $result;
					}
				}
			}
		}

		$counts = array_count_values(array_column($results, 'status'));

		$this->respond([
			'dry_run' => $dry_run,
			'results' => array_values($results),
			'counts' => $counts + ['ok' => 0, 'applied' => 0, 'conflict' => 0, 'failed' => 0]
		]);
	}

	/**
	 * Current state of everything the operations touch.
	 */
	private function loadCurrent(): array {
		$hostmacroids = [];
		$create_hostids = [];

		foreach ($this->ops as $op) {
			if ($op['action'] === 'create') {
				$create_hostids[$op['hostid']] = true;
			}
			else {
				$hostmacroids[$op['hostmacroid']] = true;
			}
		}

		$by_id = $hostmacroids ? API::UserMacro()->get([
			'output' => ['hostmacroid', 'hostid', 'macro', 'value', 'type', 'description', 'automatic'],
			'hostmacroids' => array_keys($hostmacroids),
			'preservekeys' => true
		]) : [];

		$existing = [];

		if ($create_hostids) {
			$db_macros = API::UserMacro()->get([
				'output' => ['hostid', 'macro'],
				'hostids' => array_keys($create_hostids)
			]) ?: [];

			foreach ($db_macros as $db_macro) {
				$parsed = MacroKey::parse($db_macro['macro']);
				$existing[$db_macro['hostid'].'|'.($parsed !== null ? $parsed['macro'] : $db_macro['macro'])] = true;
			}
		}

		return ['by_id' => $by_id ?: [], 'existing' => $existing];
	}

	/**
	 * @return string|null  Why the operation no longer applies, or null.
	 */
	private function checkConflict(array $op, array $current): ?string {
		if ($op['action'] === 'create') {
			return array_key_exists($op['hostid'].'|'.$op['macro'], $current['existing'])
				? _s('%1$s was created on this object by someone else in the meantime.', $op['macro'])
				: null;
		}

		$db = $current['by_id'][$op['hostmacroid']] ?? null;

		if ($db === null) {
			return _s('%1$s no longer exists or is not visible to you.', $op['expect']['macro']);
		}

		$parsed = MacroKey::parse($db['macro']);
		$db_macro = $parsed !== null ? $parsed['macro'] : $db['macro'];
		$changed = [];

		if ($db_macro !== $op['expect']['macro']) {
			$changed[] = _('name');
		}

		if ((int) $db['type'] !== $op['expect']['type']) {
			$changed[] = _('type');
		}

		if ($db['description'] !== $op['expect']['description']) {
			$changed[] = _('description');
		}

		if (array_key_exists('value', $op['expect']) && (int) $db['type'] != ZBX_MACRO_TYPE_SECRET
				&& ($db['value'] ?? '') !== $op['expect']['value']) {
			$changed[] = _('value');
		}

		return $changed
			? _s('%1$s was changed by someone else since it was loaded (%2$s).', $op['expect']['macro'],
				implode(', ', $changed)
			)
			: null;
	}

	private function runChunk(string $action, array $chunk, array $current): array {
		switch ($action) {
			case 'delete':
				$result = API::UserMacro()->delete(array_column($chunk, 'hostmacroid'));
				break;

			case 'update':
				$payload = [];

				foreach ($chunk as $op) {
					$item = [
						'hostmacroid' => $op['hostmacroid'],
						'type' => $op['type'],
						'description' => $op['description']
					];

					if (array_key_exists('value', $op)) {
						$item['value'] = $op['value'];
					}

					// A macro written by discovery can only be changed by turning it into a manual one.
					if ((int) ($current['by_id'][$op['hostmacroid']]['automatic'] ?? 0) == ZBX_USERMACRO_AUTOMATIC) {
						$item['automatic'] = ZBX_USERMACRO_MANUAL;
					}

					$payload[] = $item;
				}

				$result = API::UserMacro()->update($payload);
				break;

			case 'create':
				$result = API::UserMacro()->create(array_map(static fn(array $op): array => [
					'hostid' => $op['hostid'],
					'macro' => $op['macro'],
					'value' => $op['value'],
					'type' => $op['type'],
					'description' => $op['description']
				], $chunk));
				break;

			default:
				$result = false;
		}

		$messages = array_column(get_and_clear_messages(), 'message');
		$status = $result === false ? 'failed' : 'applied';
		$message = $result === false ? implode("\n", $messages ?: [_('Unknown API error.')]) : null;

		return array_map(static fn(array $op): array => [
			'key' => $op['key'],
			'status' => $status,
			'message' => $message
		], $chunk);
	}

	private static function isId($value): bool {
		return is_scalar($value) && ctype_digit((string) $value) && (string) $value !== '0';
	}
}
