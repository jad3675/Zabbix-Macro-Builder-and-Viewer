<?php declare(strict_types = 0);

namespace Modules\MacroMatrix\Actions;

use CRoleHelper,
	InvalidArgumentException,
	Modules\MacroMatrix\Lib\MacroData,
	Modules\MacroMatrix\Lib\MacroKey,
	Modules\MacroMatrix\Lib\MacroResolver,
	RuntimeException;

/**
 * Grid data: rows (templates and/or hosts) x macro columns, each cell with its winning definition and the chain.
 *
 * A template row resolves the way a host linked only to that template would see it: the template, its own templates,
 * then global macros.
 */
class MacroMatrixResolve extends MacroMatrixJsonAction {

	protected function checkInput(): bool {
		return $this->validateJson([
			'rows' => 'in hosts,templates,both',
			'groupids' => 'array',
			'hostids' => 'array',
			'tpl_groupids' => 'array',
			'templateids' => 'array',
			'subgroups' => 'in 0,1',
			'tpl_used_only' => 'in 0,1',
			'with_hosts' => 'in 0,1',
			'pattern' => 'required|string',
			'context' => 'string'
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

		$rows = $this->getInput('rows', 'hosts');
		$context = $this->getInput('context', '');
		$subgroups = $this->getInput('subgroups', '0') === '1';

		$groupids = self::ids($this->getInput('groupids', []));
		$hostids = self::ids($this->getInput('hostids', []));
		$tpl_groupids = self::ids($this->getInput('tpl_groupids', []));
		$templateids = self::ids($this->getInput('templateids', []));

		$has_host_filter = $groupids || $hostids;
		$has_template_filter = $tpl_groupids || $templateids;

		if ($rows === 'templates' && !$has_template_filter) {
			$this->respondError(_('Pick template groups or templates to load template rows.'));

			return;
		}

		if ($rows === 'both' && !$has_host_filter && !$has_template_filter) {
			$this->respondError(_('Pick host groups, hosts, template groups or templates.'));

			return;
		}

		if ($subgroups && $groupids) {
			$groupids = array_map('strval', getSubGroups($groupids));
		}

		if ($subgroups && $tpl_groupids) {
			$tpl_groupids = array_map('strval', getSubGroups($tpl_groupids, $ms, 'template'));
		}

		// With "Hosts" alone an empty filter means all hosts, as in the native host list. With "Both", each kind is
		// loaded only when something of that kind was picked.
		$load_hosts = $rows === 'hosts' || ($rows === 'both' && $has_host_filter);
		$load_templates = $rows !== 'hosts' && $has_template_filter;

		try {
			$hosts = $load_hosts ? MacroData::getHosts($groupids, $hostids) : [];
			$row_templates = $load_templates
				? MacroData::getTemplateRows($tpl_groupids, $templateids, MacroData::MAX_HOSTS - count($hosts))
				: [];
		}
		catch (RuntimeException $e) {
			$this->respondError($e->getMessage());

			return;
		}

		$warnings = [];

		if ($row_templates && $this->getInput('tpl_used_only', '0') === '1') {
			$usage = MacroData::getTemplateUsage();
			$row_templates = array_intersect_key($row_templates, $usage);

			if (!$row_templates) {
				$this->respondError(_('None of the picked templates is used by a host.'));

				return;
			}
		}

		// "Also load the hosts that use them": every host inheriting a template row, directly or through other
		// templates. Together with macros as rows this shows drift between a template and its hosts.
		if ($row_templates && $this->getInput('with_hosts', '0') === '1') {
			$row_ids = array_map('strval', array_keys($row_templates));
			$linking = $row_ids;

			foreach (MacroData::getTemplateDescendants($row_ids) as $ids) {
				$linking = array_merge($linking, $ids);
			}

			$hosts += MacroData::getHostsByTemplates(array_values(array_unique($linking)));

			if (count($hosts) + count($row_templates) > MacroData::MAX_HOSTS) {
				$this->respondError(_s('More than %1$s hosts and templates match. Narrow the filter.',
					MacroData::MAX_HOSTS
				));

				return;
			}

			uasort($hosts, static fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));
		}

		$templates = MacroData::getTemplateTree($hosts + $row_templates, $unreadable) + $row_templates;

		if ($unreadable) {
			$warnings[] = _n(
				'%1$s linked template is not readable with your permissions. Values inherited from it are not shown.',
				'%1$s linked templates are not readable with your permissions. Values inherited from them are not shown.',
				count($unreadable)
			);
		}

		$graph = MacroData::buildGraph($hosts, $templates, $patterns);
		$columns = MacroData::buildColumns($graph['defs'], $patterns, $context);

		if (count($columns) > MacroData::MAX_COLUMNS) {
			$warnings[] = _s('%1$s macro columns match; showing the first %2$s. Narrow the pattern to see the rest.',
				count($columns), MacroData::MAX_COLUMNS
			);
			$columns = array_slice($columns, 0, MacroData::MAX_COLUMNS);
		}

		$resolver = new MacroResolver($graph['parents'], $graph['defs']);
		$editable_hosts = MacroData::getEditableHostids(array_keys($hosts));
		$editable_templates = MacroData::getEditableTemplateids(array_keys($templates));
		$can_edit_templates = $this->checkAccess(CRoleHelper::UI_CONFIGURATION_TEMPLATES);

		$out_rows = [];

		foreach ($row_templates as $templateid => $template) {
			$out_rows[] = [
				'hostid' => (string) $templateid,
				'kind' => 'template',
				'name' => $template['name'],
				'host' => $template['name'],
				'parents' => $template['parents'],
				'status' => 0,
				'flags' => 0,
				'editable' => $can_edit_templates && array_key_exists($templateid, $editable_templates)
			];
		}

		foreach ($hosts as $hostid => $host) {
			$out_rows[] = [
				'hostid' => (string) $hostid,
				'kind' => 'host',
				'name' => $host['name'],
				'host' => $host['host'],
				'parents' => $host['parents'],
				'status' => (int) $host['status'],
				'flags' => (int) $host['flags'],
				'editable' => array_key_exists($hostid, $editable_hosts)
			];
		}

		$used_defids = [];
		$cells = [];

		foreach ($out_rows as $row) {
			$cells_row = [];
			$any = false;

			foreach ($columns as $column) {
				$result = $resolver->resolve($row['hostid'], $column);

				if (!$result['chain']) {
					$cells_row[] = null;
					continue;
				}

				$any = true;
				$cells_row[] = [$result['defid'], $result['fallback'] ? 1 : 0, $result['chain']];

				foreach ($result['chain'] as $defid) {
					$used_defids[$defid] = true;
				}
			}

			$cells[$row['hostid']] = $any ? $cells_row : null;
		}

		$defs = [];

		foreach (array_keys($used_defids) as $defid) {
			$defs[$defid] = MacroData::exportDef($graph['defs'][$defid]);
		}

		$out_templates = [];

		foreach ($templates as $templateid => $template) {
			// Parents let the browser draw the lookup path, including templates that do not define the macro.
			$out_templates[$templateid] = [
				'name' => $template['name'],
				'parents' => $template['parents'],
				'editable' => array_key_exists($templateid, $editable_templates)
			];
		}

		$this->respond([
			'columns' => $columns,
			'hosts' => $out_rows,
			'templates' => (object) $out_templates,
			'defs' => (object) $defs,
			'cells' => (object) $cells,
			'warnings' => $warnings
		]);
	}
}
