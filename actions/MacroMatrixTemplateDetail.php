<?php declare(strict_types = 0);

namespace Modules\MacroMatrix\Actions;

use API,
	InvalidArgumentException,
	Modules\MacroMatrix\Lib\MacroData,
	Modules\MacroMatrix\Lib\MacroKey;

/**
 * Details behind the numbers on the Templates in use tab: a template's own macros, or the hosts that use it.
 */
class MacroMatrixTemplateDetail extends MacroMatrixJsonAction {

	private const MAX_HOSTS = 5000;

	protected function checkInput(): bool {
		return $this->validateJson([
			'templateid' => 'required|db hosts.hostid',
			'what' => 'required|in macros,hosts',
			'pattern' => 'string'
		]);
	}

	protected function doAction(): void {
		$templateid = (string) $this->getInput('templateid');

		$templates = API::Template()->get([
			'output' => ['templateid', 'name'],
			'templateids' => [$templateid]
		]);

		if (!$templates) {
			$this->respondError(_('No permissions to referred object or it does not exist!'));

			return;
		}

		$template = $templates[0];

		if ($this->getInput('what') === 'macros') {
			$this->macros($template);
		}
		else {
			$this->hosts($template);
		}
	}

	/**
	 * The template's own macros, filtered like the macro count on the tab.
	 */
	private function macros(array $template): void {
		$pattern = trim($this->getInput('pattern', ''));
		$patterns = null;

		if ($pattern !== '') {
			try {
				$patterns = MacroKey::parsePatterns($pattern);
			}
			catch (InvalidArgumentException $e) {
				$this->respondError($e->getMessage());

				return;
			}
		}

		$db_macros = API::UserMacro()->get([
			'output' => ['hostmacroid', 'macro', 'value', 'type', 'description'],
			'hostids' => [$template['templateid']]
		]) ?: [];

		$rows = [];

		foreach ($db_macros as $db_macro) {
			$parsed = MacroKey::parse($db_macro['macro']);

			if ($parsed === null || ($patterns !== null && !preg_match($patterns['regex'], $parsed['name']))) {
				continue;
			}

			$type = (int) $db_macro['type'];

			$rows[] = [
				'hostmacroid' => $db_macro['hostmacroid'],
				'macro' => $parsed['macro'],
				'name' => $parsed['name'],
				'context' => $parsed['context'],
				'regex' => $parsed['regex'],
				'value' => $type == ZBX_MACRO_TYPE_SECRET ? null : $db_macro['value'],
				'type' => $type,
				'description' => $db_macro['description']
			];
		}

		usort($rows, static fn(array $a, array $b): int => strcmp($a['macro'], $b['macro']));

		$this->respond([
			'template' => $template,
			'filtered' => $patterns !== null,
			'macros' => $rows
		]);
	}

	/**
	 * Every host using the template: linked directly, or through templates that link it.
	 */
	private function hosts(array $template): void {
		$templateid = (string) $template['templateid'];
		$descendants = MacroData::getTemplateDescendants([$templateid])[$templateid];
		$hosts = MacroData::getHostsByTemplates(array_merge([$templateid], $descendants));

		$names = $descendants ? array_column(API::Template()->get([
			'output' => ['templateid', 'name'],
			'templateids' => $descendants
		]) ?: [], 'name', 'templateid') : [];

		$below = array_fill_keys($descendants, true);
		$rows = [];

		foreach ($hosts as $hostid => $host) {
			$direct = in_array($templateid, $host['parents'], true);
			$via = [];

			foreach ($host['parents'] as $parentid) {
				if (array_key_exists($parentid, $below)) {
					$via[] = $names[$parentid] ?? $parentid;
				}
			}

			if (!$direct && !$via) {
				continue;
			}

			$rows[] = [
				'hostid' => (string) $hostid,
				'name' => $host['name'],
				'host' => $host['host'],
				'status' => (int) $host['status'],
				'direct' => $direct,
				'via' => $via
			];
		}

		usort($rows, static fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));

		$truncated = count($rows) > self::MAX_HOSTS;

		$this->respond([
			'template' => $template,
			'hosts' => array_slice($rows, 0, self::MAX_HOSTS),
			'total' => count($rows),
			'truncated' => $truncated
		]);
	}
}
