<?php declare(strict_types = 0);

namespace Modules\MacroMatrix\Actions;

use API,
	InvalidArgumentException,
	Modules\MacroMatrix\Lib\MacroData,
	Modules\MacroMatrix\Lib\MacroKey;

/**
 * Find mode: every definition of the matching macros, on any host, template or global level the user can read.
 */
class MacroMatrixFind extends MacroMatrixJsonAction {

	private const MAX_ROWS = 5000;

	protected function checkInput(): bool {
		return $this->validateJson([
			'pattern' => 'required|string'
		]);
	}

	protected function doAction(): void {
		try {
			$patterns = MacroKey::parsePatterns($this->getInput('pattern'));
		}
		catch (InvalidArgumentException $e) {
			$this->respondError($e->getMessage());

			return;
		}

		$defs = MacroData::getHostDefs(null, $patterns);
		$objectids = array_values(array_unique(array_column($defs, 'objectid')));

		// Macros on host prototypes also come back from usermacro.get; only real hosts and templates are kept.
		$hosts = $objectids ? API::Host()->get([
			'output' => ['hostid', 'name', 'status', 'flags'],
			'hostids' => $objectids,
			'preservekeys' => true
		]) : [];

		$templates = $objectids ? API::Template()->get([
			'output' => ['templateid', 'name'],
			'templateids' => $objectids,
			'preservekeys' => true
		]) : [];

		$editable_hosts = MacroData::getEditableHostids(array_keys($hosts ?: []));
		$editable_templates = MacroData::getEditableTemplateids(array_keys($templates ?: []));

		$rows = [];

		foreach ($defs as $def) {
			$oid = $def['objectid'];

			if (is_array($templates) && array_key_exists($oid, $templates)) {
				$def['level'] = 'template';
				$object = ['name' => $templates[$oid]['name'], 'editable' => array_key_exists($oid, $editable_templates)];
			}
			elseif (is_array($hosts) && array_key_exists($oid, $hosts)) {
				$def['level'] = 'host';
				$object = [
					'name' => $hosts[$oid]['name'],
					'editable' => array_key_exists($oid, $editable_hosts),
					'status' => (int) $hosts[$oid]['status'],
					'flags' => (int) $hosts[$oid]['flags']
				];
			}
			else {
				continue;
			}

			$rows[] = MacroData::exportDef($def) + ['object' => $object];
		}

		foreach (MacroData::getGlobalDefs($patterns) as $def) {
			$def['level'] = 'global';
			$rows[] = MacroData::exportDef($def) + ['object' => ['name' => _('Global'), 'editable' => false]];
		}

		$level_rank = ['global' => 0, 'template' => 1, 'host' => 2];

		usort($rows, static function (array $a, array $b) use ($level_rank): int {
			return strcmp($a['name'], $b['name'])
				?: strcmp($a['macro'], $b['macro'])
				?: ($level_rank[$a['level']] <=> $level_rank[$b['level']])
				?: strcasecmp($a['object']['name'], $b['object']['name']);
		});

		$warnings = [];

		if (count($rows) > self::MAX_ROWS) {
			$warnings[] = _s('%1$s definitions match; showing the first %2$s. Narrow the pattern to see the rest.',
				count($rows), self::MAX_ROWS
			);
			$rows = array_slice($rows, 0, self::MAX_ROWS);
		}

		$this->respond(['rows' => $rows, 'warnings' => $warnings]);
	}
}
