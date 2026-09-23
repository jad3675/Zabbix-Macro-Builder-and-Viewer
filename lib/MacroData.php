<?php declare(strict_types = 0);

namespace Modules\MacroMatrix\Lib;

use API,
	RuntimeException;

/**
 * Everything that talks to the API. Runs as the logged-in user, so results are already permission-filtered.
 */
class MacroData {

	public const MAX_HOSTS = 5000;
	public const MAX_COLUMNS = 150;

	/**
	 * Hosts matching the filter, with their directly linked templates.
	 *
	 * @throws RuntimeException  When more than MAX_HOSTS hosts match.
	 *
	 * @return array hostid => ['hostid', 'name', 'host', 'status', 'flags', 'parents' => [templateid, ...]]
	 */
	public static function getHosts(array $groupids, array $hostids): array {
		$options = [
			'output' => ['hostid', 'name', 'host', 'status', 'flags'],
			'selectParentTemplates' => ['templateid'],
			'sortfield' => 'name',
			'limit' => self::MAX_HOSTS + 1,
			'preservekeys' => true
		];

		if ($groupids) {
			$options['groupids'] = $groupids;
		}

		if ($hostids) {
			$options['hostids'] = $hostids;
		}

		$hosts = API::Host()->get($options);

		if ($hosts === false) {
			throw new RuntimeException(_('Cannot load hosts.'));
		}

		if (count($hosts) > self::MAX_HOSTS) {
			throw new RuntimeException(_s('More than %1$s hosts match. Narrow the host group or host filter.',
				self::MAX_HOSTS
			));
		}

		return self::withParents($hosts);
	}

	/**
	 * Templates shown as grid rows, with their directly linked templates.
	 *
	 * @param int $limit  Rows still available under MAX_HOSTS.
	 *
	 * @throws RuntimeException  When more than $limit templates match.
	 *
	 * @return array templateid => ['templateid', 'name', 'parents' => [...]]
	 */
	public static function getTemplateRows(array $groupids, array $templateids, int $limit): array {
		$options = [
			'output' => ['templateid', 'name'],
			'selectParentTemplates' => ['templateid'],
			'sortfield' => 'name',
			'limit' => $limit + 1,
			'preservekeys' => true
		];

		if ($groupids) {
			$options['groupids'] = $groupids;
		}

		if ($templateids) {
			$options['templateids'] = $templateids;
		}

		$templates = API::Template()->get($options);

		if ($templates === false) {
			throw new RuntimeException(_('Cannot load templates.'));
		}

		if (count($templates) > $limit) {
			throw new RuntimeException(_s('More than %1$s hosts and templates match. Narrow the filter.',
				self::MAX_HOSTS
			));
		}

		return self::withParents($templates);
	}

	/**
	 * How many hosts use each template, directly or through other templates. Counts cover the hosts and templates
	 * the user can read.
	 *
	 * @return array templateid => ['direct' => int, 'total' => int], only templates with at least one host.
	 */
	public static function getTemplateUsage(): array {
		$hosts = API::Host()->get([
			'output' => ['hostid'],
			'selectParentTemplates' => ['templateid'],
			'preservekeys' => true
		]) ?: [];

		$templates = API::Template()->get([
			'output' => ['templateid'],
			'selectParentTemplates' => ['templateid'],
			'preservekeys' => true
		]) ?: [];

		$parents = [];

		foreach ($templates as $templateid => $template) {
			$parents[(string) $templateid] = array_map('strval', array_column($template['parentTemplates'], 'templateid'));
		}

		$memo = [];

		// Template plus everything it links, recursively. Zabbix refuses circular linkage, but a visited set keeps
		// this safe regardless.
		$ancestors = static function (string $templateid) use (&$ancestors, &$memo, $parents): array {
			if (array_key_exists($templateid, $memo)) {
				return $memo[$templateid];
			}

			$memo[$templateid] = [$templateid => true];
			$result = [$templateid => true];

			foreach ($parents[$templateid] ?? [] as $parentid) {
				$result += $ancestors($parentid);
			}

			return $memo[$templateid] = $result;
		};

		$usage = [];

		foreach ($hosts as $host) {
			$all = [];

			foreach ($host['parentTemplates'] as $link) {
				$templateid = (string) $link['templateid'];
				$usage[$templateid]['direct'] = ($usage[$templateid]['direct'] ?? 0) + 1;
				$all += $ancestors($templateid);
			}

			foreach (array_keys($all) as $templateid) {
				$usage[(string) $templateid]['total'] = ($usage[(string) $templateid]['total'] ?? 0) + 1;
			}
		}

		foreach ($usage as &$counts) {
			$counts += ['direct' => 0, 'total' => 0];
		}
		unset($counts);

		return $usage;
	}

	/**
	 * Hosts linked directly to any of the given templates.
	 */
	public static function getHostsByTemplates(array $templateids): array {
		if (!$templateids) {
			return [];
		}

		$hosts = API::Host()->get([
			'output' => ['hostid', 'name', 'host', 'status', 'flags'],
			'selectParentTemplates' => ['templateid'],
			'templateids' => $templateids,
			'preservekeys' => true
		]);

		return $hosts === false ? [] : self::withParents($hosts);
	}

	/**
	 * Walks template linkage upwards from the given objects until no new templates appear.
	 *
	 * @param array $objects     objectid => ['parents' => [...]]
	 * @param array $unreadable  [OUT] templateid => true for linked templates the user cannot read.
	 *
	 * @return array templateid => ['templateid', 'name', 'parents' => [...]]
	 */
	public static function getTemplateTree(array $objects, ?array &$unreadable = null): array {
		$templates = [];
		$frontier = [];
		$unreadable = [];

		foreach ($objects as $object) {
			foreach ($object['parents'] as $templateid) {
				$frontier[$templateid] = true;
			}
		}

		while ($frontier) {
			$db_templates = API::Template()->get([
				'output' => ['templateid', 'name'],
				'selectParentTemplates' => ['templateid'],
				'templateids' => array_keys($frontier),
				'preservekeys' => true
			]);

			foreach (array_keys($frontier) as $templateid) {
				if (!is_array($db_templates) || !array_key_exists($templateid, $db_templates)) {
					$unreadable[(string) $templateid] = true;
				}
			}

			$frontier = [];

			foreach (self::withParents($db_templates ?: []) as $templateid => $template) {
				$templates[$templateid] = $template;

				foreach ($template['parents'] as $parentid) {
					if (!array_key_exists($parentid, $templates) && !array_key_exists($parentid, $unreadable)) {
						$frontier[$parentid] = true;
					}
				}
			}
		}

		return $templates;
	}

	/**
	 * Templates that link the given templates, recursively (the templates "below" them).
	 *
	 * @return array templateid => [descendant templateid, ...]
	 */
	public static function getTemplateDescendants(array $templateids): array {
		$children = [];
		$seen = array_fill_keys($templateids, true);
		$frontier = $templateids;

		while ($frontier) {
			$db_templates = API::Template()->get([
				'output' => ['templateid'],
				'selectParentTemplates' => ['templateid'],
				'parentTemplateids' => $frontier,
				'preservekeys' => true
			]);

			$next = [];

			foreach ($db_templates ?: [] as $templateid => $template) {
				foreach ($template['parentTemplates'] as $parent) {
					$children[$parent['templateid']][$templateid] = true;
				}

				if (!array_key_exists($templateid, $seen)) {
					$seen[$templateid] = true;
					$next[] = (string) $templateid;
				}
			}

			$frontier = $next;
		}

		$result = [];

		foreach ($templateids as $templateid) {
			$found = [];
			$stack = [$templateid];

			while ($stack) {
				foreach (array_keys($children[array_pop($stack)] ?? []) as $childid) {
					$childid = (string) $childid;

					if (!array_key_exists($childid, $found) && $childid !== $templateid) {
						$found[$childid] = true;
						$stack[] = $childid;
					}
				}
			}

			$result[$templateid] = array_keys($found);
		}

		return $result;
	}

	/**
	 * Host- and template-level macro definitions on the given objects whose name matches the patterns.
	 *
	 * @param array|null $objectids  null = everywhere the user can see (Find mode).
	 *
	 * @return array defid => definition (see MacroResolver), plus 'level', 'hostmacroid', 'value', 'type',
	 *               'description', 'automatic'.
	 */
	public static function getHostDefs(?array $objectids, array $patterns): array {
		if ($objectids !== null && !$objectids) {
			return [];
		}

		$options = [
			'output' => ['hostmacroid', 'hostid', 'macro', 'value', 'type', 'description', 'automatic'],
			'search' => ['macro' => $patterns['search']],
			'searchWildcardsEnabled' => true,
			'searchByAny' => true
		];

		if ($objectids !== null) {
			$options['hostids'] = $objectids;
		}

		$db_macros = API::UserMacro()->get($options);

		$defs = [];

		foreach ($db_macros ?: [] as $db_macro) {
			$def = self::makeDef('h'.$db_macro['hostmacroid'], (string) $db_macro['hostid'], $db_macro, $patterns);

			if ($def !== null) {
				$def['hostmacroid'] = $db_macro['hostmacroid'];
				$def['automatic'] = (int) $db_macro['automatic'];
				$defs[$def['id']] = $def;
			}
		}

		return $defs;
	}

	/**
	 * Global macro definitions whose name matches the patterns.
	 */
	public static function getGlobalDefs(array $patterns): array {
		$db_macros = API::UserMacro()->get([
			'output' => ['globalmacroid', 'macro', 'value', 'type', 'description'],
			'globalmacro' => true,
			'search' => ['macro' => $patterns['search']],
			'searchWildcardsEnabled' => true,
			'searchByAny' => true
		]);

		$defs = [];

		foreach ($db_macros ?: [] as $db_macro) {
			$def = self::makeDef('g'.$db_macro['globalmacroid'], MacroResolver::GLOBAL_ID, $db_macro, $patterns);

			if ($def !== null) {
				$def['globalmacroid'] = $db_macro['globalmacroid'];
				$def['automatic'] = 0;
				$defs[$def['id']] = $def;
			}
		}

		return $defs;
	}

	/**
	 * IDs among the given ones that the user may write to.
	 */
	public static function getEditableHostids(array $hostids): array {
		if (!$hostids) {
			return [];
		}

		$hosts = API::Host()->get([
			'output' => [],
			'hostids' => $hostids,
			'editable' => true,
			'preservekeys' => true
		]);

		return array_fill_keys(array_map('strval', array_keys($hosts ?: [])), true);
	}

	public static function getEditableTemplateids(array $templateids): array {
		if (!$templateids) {
			return [];
		}

		$templates = API::Template()->get([
			'output' => [],
			'templateids' => $templateids,
			'editable' => true,
			'preservekeys' => true
		]);

		return array_fill_keys(array_map('strval', array_keys($templates ?: [])), true);
	}

	/**
	 * Builds the resolver input from hosts and their template tree, and the macro definitions on all of them.
	 *
	 * @return array ['parents' => [...], 'defs' => [...]]
	 */
	public static function buildGraph(array $hosts, array $templates, array $patterns): array {
		$parents = [];

		foreach ($hosts as $hostid => $host) {
			$parents[(string) $hostid] = $host['parents'];
		}

		foreach ($templates as $templateid => $template) {
			$parents[(string) $templateid] = $template['parents'];
		}

		$defs = self::getHostDefs(array_map('strval', array_keys($parents)), $patterns);

		foreach ($defs as &$def) {
			$def['level'] = array_key_exists($def['objectid'], $templates) ? 'template' : 'host';
		}
		unset($def);

		foreach (self::getGlobalDefs($patterns) as $defid => $def) {
			$def['level'] = 'global';
			$defs[$defid] = $def;
		}

		return ['parents' => $parents, 'defs' => $defs];
	}

	/**
	 * Columns for the grid, in display order.
	 *
	 * @param array  $defs      All definitions in play.
	 * @param array  $patterns  From MacroKey::parsePatterns().
	 * @param string $context   Context filter value; '' for none.
	 *
	 * @return array list of ['macro', 'name', 'mode', 'context']
	 */
	public static function buildColumns(array $defs, array $patterns, string $context): array {
		$columns = [];
		$names = array_fill_keys($patterns['exact'], true);
		$base_names = $names;

		foreach ($defs as $def) {
			$names[$def['name']] = true;

			if ($def['regex'] === null && $def['context'] === null) {
				$base_names[$def['name']] = true;
			}
			elseif ($def['regex'] !== null) {
				$columns[$def['macro']] = [
					'macro' => $def['macro'],
					'name' => $def['name'],
					'mode' => MacroResolver::MODE_LITERAL,
					'context' => null,
					'regex' => $def['regex']
				];
			}
			elseif ($def['context'] !== null) {
				$columns[$def['macro']] = [
					'macro' => $def['macro'],
					'name' => $def['name'],
					'mode' => MacroResolver::MODE_CONTEXT,
					'context' => $def['context'],
					'regex' => null
				];
			}
		}

		foreach (array_keys($names) as $name) {
			$name = (string) $name;

			if (array_key_exists($name, $base_names)) {
				$base = '{$'.$name.'}';

				$columns[$base] = [
					'macro' => $base,
					'name' => $name,
					'mode' => MacroResolver::MODE_BASE,
					'context' => null,
					'regex' => null
				];
			}

			if ($context !== '' && ($parsed = MacroKey::withContext($name, $context)) !== null) {
				$columns[$parsed['macro']] = [
					'macro' => $parsed['macro'],
					'name' => $name,
					'mode' => MacroResolver::MODE_CONTEXT,
					'context' => $context,
					'regex' => null
				];
			}
		}

		$columns = array_values($columns);

		usort($columns, static function (array $a, array $b): int {
			$rank = ['base' => 0, 'context' => 1, 'literal' => 2];

			return strcmp($a['name'], $b['name'])
				?: ($rank[$a['mode']] <=> $rank[$b['mode']])
				?: strcmp((string) ($a['context'] ?? $a['regex']), (string) ($b['context'] ?? $b['regex']));
		});

		return $columns;
	}

	/**
	 * Definition fields as sent to the browser. Secret values never leave the server (the API does not return them
	 * anyway).
	 */
	public static function exportDef(array $def): array {
		$type = (int) $def['type'];

		return [
			'id' => $def['id'],
			'oid' => $def['objectid'],
			'level' => $def['level'],
			'macro' => $def['macro'],
			'name' => $def['name'],
			'context' => $def['context'],
			'regex' => $def['regex'],
			'value' => $type == ZBX_MACRO_TYPE_SECRET ? null : $def['value'],
			'type' => $type,
			'description' => $def['description'],
			'automatic' => $def['automatic'],
			'hostmacroid' => $def['hostmacroid'] ?? null
		];
	}

	private static function makeDef(string $defid, string $objectid, array $db_macro, array $patterns): ?array {
		$parsed = MacroKey::parse($db_macro['macro']);

		if ($parsed === null || !preg_match($patterns['regex'], $parsed['name'])) {
			return null;
		}

		return $parsed + [
			'id' => $defid,
			'objectid' => $objectid,
			'value' => $db_macro['value'] ?? '',
			'type' => (int) $db_macro['type'],
			'description' => $db_macro['description']
		];
	}

	private static function withParents(array $objects): array {
		$result = [];

		foreach ($objects as $id => $object) {
			$object['parents'] = array_map('strval', array_column($object['parentTemplates'], 'templateid'));
			unset($object['parentTemplates']);
			$result[(string) $id] = $object;
		}

		return $result;
	}
}
