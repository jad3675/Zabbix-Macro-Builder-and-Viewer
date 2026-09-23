<?php declare(strict_types = 0);

namespace Modules\MacroMatrix\Actions;

use API,
	CRoleHelper,
	InvalidArgumentException,
	Modules\MacroMatrix\Lib\MacroData,
	Modules\MacroMatrix\Lib\MacroKey;

/**
 * "Templates in use": templates with at least one host inheriting them, how many hosts, and how many macros they
 * define (all, or those matching the macro filter).
 */
class MacroMatrixTemplates extends MacroMatrixJsonAction {

	protected function checkInput(): bool {
		return $this->validateJson([
			'tpl_groupids' => 'array',
			'subgroups' => 'in 0,1',
			'pattern' => 'string',
			'include_unused' => 'in 0,1'
		]);
	}

	protected function doAction(): void {
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

		$groupids = self::ids($this->getInput('tpl_groupids', []));

		if ($groupids && $this->getInput('subgroups', '0') === '1') {
			$groupids = array_map('strval', getSubGroups($groupids, $ms, 'template'));
		}

		$options = [
			'output' => ['templateid', 'name'],
			'preservekeys' => true
		];

		if ($groupids) {
			$options['groupids'] = $groupids;
		}

		$templates = API::Template()->get($options) ?: [];
		$usage = MacroData::getTemplateUsage();
		$include_unused = $this->getInput('include_unused', '0') === '1';

		if (!$include_unused) {
			$templates = array_intersect_key($templates, $usage);
		}

		$counts = [];

		if ($templates) {
			$macro_options = [
				'output' => ['hostid', 'macro'],
				'hostids' => array_map('strval', array_keys($templates))
			];

			if ($patterns !== null) {
				$macro_options += [
					'search' => ['macro' => $patterns['search']],
					'searchWildcardsEnabled' => true,
					'searchByAny' => true
				];
			}

			foreach (API::UserMacro()->get($macro_options) ?: [] as $db_macro) {
				if ($patterns !== null) {
					$parsed = MacroKey::parse($db_macro['macro']);

					if ($parsed === null || !preg_match($patterns['regex'], $parsed['name'])) {
						continue;
					}
				}

				$counts[$db_macro['hostid']] = ($counts[$db_macro['hostid']] ?? 0) + 1;
			}
		}

		$editable = MacroData::getEditableTemplateids(array_keys($templates));
		$can_edit = $this->checkAccess(CRoleHelper::UI_CONFIGURATION_TEMPLATES);
		$rows = [];

		foreach ($templates as $templateid => $template) {
			$rows[] = [
				'templateid' => (string) $templateid,
				'name' => $template['name'],
				'direct' => $usage[$templateid]['direct'] ?? 0,
				'total' => $usage[$templateid]['total'] ?? 0,
				'macros' => $counts[$templateid] ?? 0,
				'editable' => $can_edit && array_key_exists($templateid, $editable)
			];
		}

		usort($rows, static fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));

		$this->respond([
			'rows' => $rows,
			'filtered' => $patterns !== null,
			'warnings' => []
		]);
	}
}
