<?php declare(strict_types = 0);

namespace Modules\MacroMatrix\Actions;

use API,
	InvalidArgumentException,
	Modules\MacroMatrix\Lib\MacroData,
	Modules\MacroMatrix\Lib\MacroKey,
	Modules\MacroMatrix\Lib\MacroResolver;

/**
 * For template-level macros: which hosts resolve to them, which override them, and which get the value elsewhere.
 * Covers every host that inherits the template, directly or through other templates, not only the filtered grid.
 */
class MacroMatrixReach extends MacroMatrixJsonAction {

	private const MAX_HOSTS = 20000;
	private const MAX_LISTED = 500;

	protected function checkInput(): bool {
		return $this->validateJson([
			'hostmacroids' => 'required|array'
		]);
	}

	protected function doAction(): void {
		$hostmacroids = self::ids($this->getInput('hostmacroids'));

		if (!$hostmacroids) {
			$this->respondError(_('No template macros given.'));

			return;
		}

		$db_macros = API::UserMacro()->get([
			'output' => ['hostmacroid', 'hostid', 'macro'],
			'hostmacroids' => $hostmacroids
		]) ?: [];

		$templateids = array_values(array_unique(array_map('strval', array_column($db_macros, 'hostid'))));
		$db_templates = $templateids ? API::Template()->get([
			'output' => ['templateid', 'name'],
			'templateids' => $templateids,
			'preservekeys' => true
		]) : [];

		$targets = [];

		foreach ($db_macros as $db_macro) {
			$templateid = (string) $db_macro['hostid'];
			$parsed = MacroKey::parse($db_macro['macro']);

			if ($parsed !== null && is_array($db_templates) && array_key_exists($templateid, $db_templates)) {
				$targets[] = $parsed + [
					'hostmacroid' => (string) $db_macro['hostmacroid'],
					'templateid' => $templateid,
					'template_name' => $db_templates[$templateid]['name']
				];
			}
		}

		if (!$targets) {
			$this->respondError(_('None of the given macros is a template macro you can read.'));

			return;
		}

		$target_templateids = array_values(array_unique(array_column($targets, 'templateid')));
		$descendants = MacroData::getTemplateDescendants($target_templateids);

		$linking_templateids = $target_templateids;

		foreach ($descendants as $ids) {
			$linking_templateids = array_merge($linking_templateids, $ids);
		}

		$hosts = MacroData::getHostsByTemplates(array_values(array_unique($linking_templateids)));

		if (count($hosts) > self::MAX_HOSTS) {
			$this->respondError(_s('More than %1$s hosts inherit these templates.', self::MAX_HOSTS));

			return;
		}

		$templates = MacroData::getTemplateTree($hosts, $unreadable);

		try {
			$patterns = MacroKey::parsePatterns(implode(',', array_unique(array_column($targets, 'name'))));
		}
		catch (InvalidArgumentException $e) {
			$this->respondError($e->getMessage());

			return;
		}

		$graph = MacroData::buildGraph($hosts, $templates, $patterns);
		$resolver = new MacroResolver($graph['parents'], $graph['defs']);
		$editable_hosts = MacroData::getEditableHostids(array_keys($hosts));

		$results = [];

		foreach ($targets as $target) {
			$column = self::columnFor($target);
			$defid = 'h'.$target['hostmacroid'];
			$below = array_fill_keys(array_merge([$target['templateid']], $descendants[$target['templateid']]), true);

			$result = [
				'hostmacroid' => $target['hostmacroid'],
				'macro' => $target['macro'],
				'templateid' => $target['templateid'],
				'template_name' => $target['template_name'],
				'affected_count' => 0,
				'affected' => [],
				'overridden' => [],
				'other' => [],
				'through' => [],
				'truncated' => false
			];

			$through = [];

			foreach ($hosts as $hostid => $host) {
				$hostid = (string) $hostid;

				$linked = array_values(array_intersect($host['parents'], array_keys($below)));

				if (!$linked) {
					continue;
				}

				// The template this host reaches the edited one through: the edited template itself if linked
				// directly, otherwise the first linking template (they all lead to the same place).
				$via = in_array($target['templateid'], $linked, true) ? $target['templateid'] : $linked[0];

				$resolved = $resolver->resolve($hostid, $column);
				$winner = $resolved['defid'] !== null ? $graph['defs'][$resolved['defid']] : null;
				$entry = ['hostid' => $hostid, 'name' => $host['name'], 'via' => $via];

				if ($via !== $target['templateid']) {
					$through[$via]['total'] = ($through[$via]['total'] ?? 0) + 1;
				}

				if ($resolved['defid'] === $defid) {
					$result['affected_count']++;
					$list = 'affected';
				}
				elseif ($winner !== null && $winner['objectid'] === $hostid) {
					$entry += [
						'hostmacroid' => $winner['hostmacroid'],
						'macro' => $winner['macro'],
						'type' => (int) $winner['type'],
						'value' => $winner['type'] == ZBX_MACRO_TYPE_SECRET ? null : $winner['value'],
						'description' => $winner['description'],
						'editable' => array_key_exists($hostid, $editable_hosts)
					];
					$list = 'overridden';
				}
				else {
					$entry['source'] = $winner === null
						? _('undefined')
						: ($winner['level'] === 'global'
							? _('Global')
							: ($templates[$winner['objectid']]['name'] ?? $winner['objectid']));
					$list = 'other';
				}

				// Overrides are always listed in full: each one is a revert choice in the diff.
				if ($list === 'overridden' || count($result[$list]) < self::MAX_LISTED) {
					$result[$list][] = $entry;
				}
				else {
					$result['truncated'] = true;
				}
			}

			foreach ($through as $templateid => $counts) {
				$result['through'][] = [
					'templateid' => (string) $templateid,
					'name' => $templates[$templateid]['name'] ?? (string) $templateid,
					'hosts' => $counts['total'],
					'links' => array_values(array_intersect($templates[$templateid]['parents'] ?? [],
						array_keys($below)
					))
				];
			}

			usort($result['through'], static fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));

			foreach (['affected', 'overridden', 'other'] as $list) {
				usort($result[$list], static fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));
			}

			$results[] = $result;
		}

		$warnings = [];

		if ($unreadable) {
			$warnings[] = _('Some linked templates are not readable with your permissions. Counts may be incomplete.');
		}

		$this->respond(['results' => $results, 'warnings' => $warnings]);
	}

	private static function columnFor(array $def): array {
		if ($def['regex'] !== null) {
			return ['name' => $def['name'], 'mode' => MacroResolver::MODE_LITERAL, 'context' => null,
				'macro' => $def['macro']
			];
		}

		if ($def['context'] !== null) {
			return ['name' => $def['name'], 'mode' => MacroResolver::MODE_CONTEXT, 'context' => $def['context'],
				'macro' => $def['macro']
			];
		}

		return ['name' => $def['name'], 'mode' => MacroResolver::MODE_BASE, 'context' => null,
			'macro' => $def['macro']
		];
	}
}
