<?php declare(strict_types = 0);

namespace Modules\MacroMatrix\Lib;

/**
 * Resolves user macros the same way Zabbix server does (src/libs/zbxcacheconfig/user_macro.c, 7.4).
 *
 * No API or DB access in here: the caller hands in the linkage graph and the macro definitions, which keeps the
 * precedence logic testable on its own.
 *
 * Server behaviour reproduced:
 *
 *  - Lookup walks level by level: the host, then all templates linked to the objects of the previous level, sorted by
 *    template ID (numerically), then their templates, and so on. Global macros come last.
 *  - Within one object, macros of a name are ordered: no context first, then static contexts, then regex contexts
 *    (each group ordered by context/regex string). The first full match wins.
 *  - A full match is: no context wanted and a context-less macro; or a context wanted and a static context equal to
 *    it, or a regex context matching it.
 *  - When a context is wanted, the first context-less macro met during the walk is kept as fallback. A full match in
 *    global macros still beats a fallback found on the host or its templates.
 *
 * Definition array shape (keys used here):
 *   id        unique string id ("h<hostmacroid>" or "g<globalmacroid>")
 *   objectid  host or template ID, or self::GLOBAL_ID
 *   name      macro name without "{$", "}" and context, e.g. "SNMP_COMMUNITY"
 *   context   static context string or null
 *   regex     regex context string or null
 *   macro     minified full macro, e.g. {$LOW_SPACE_LIMIT:"/var"}
 */
class MacroResolver {

	public const GLOBAL_ID = 'global';

	public const MODE_BASE = 'base';			// {$NAME}, resolved without context
	public const MODE_CONTEXT = 'context';		// {$NAME:"ctx"}, resolved with context "ctx"
	public const MODE_LITERAL = 'literal';		// {$NAME:regex:"..."}, nearest definition of this exact macro

	/** @var array objectid => list of directly linked template IDs */
	private array $parents;

	/** @var array defid => definition */
	private array $defs = [];

	/** @var array objectid => name => ordered list of defids */
	private array $index = [];

	/** @var array hostid => list of levels (each a list of objectids), cached */
	private array $order_cache = [];

	/** @var array regex => bool compiled ok, cached */
	private array $regex_ok = [];

	/**
	 * @param array $parents  objectid => [templateid, ...] for every host and template in play.
	 * @param array $defs     List of macro definitions (see class comment).
	 */
	public function __construct(array $parents, array $defs) {
		$this->parents = $parents;

		foreach ($defs as $def) {
			$this->defs[$def['id']] = $def;
			$this->index[(string) $def['objectid']][$def['name']][] = $def['id'];
		}

		foreach ($this->index as &$by_name) {
			foreach ($by_name as &$defids) {
				usort($defids, fn(string $a, string $b): int => self::compareDefs($this->defs[$a], $this->defs[$b]));
			}
			unset($defids);
		}
		unset($by_name);
	}

	public function getDef(string $defid): ?array {
		return $this->defs[$defid] ?? null;
	}

	/**
	 * Server order of macros sharing a name on one object: context-less, static, regex; then by string.
	 */
	public static function compareDefs(array $a, array $b): int {
		$rank = static fn(array $d): int => $d['regex'] !== null ? 2 : ($d['context'] !== null ? 1 : 0);

		if (($r = $rank($a) <=> $rank($b)) != 0) {
			return $r;
		}

		return strcmp((string) ($a['regex'] ?? $a['context'] ?? ''), (string) ($b['regex'] ?? $b['context'] ?? ''));
	}

	/**
	 * Compares numeric ID strings without converting to int (IDs are unsigned 64-bit).
	 */
	public static function compareIds(string $a, string $b): int {
		return (strlen($a) <=> strlen($b)) ?: strcmp($a, $b);
	}

	/**
	 * Lookup order for a host: [[hostid], [level 1 templates], [level 2 templates], ...], global excluded.
	 *
	 * An object already visited is not visited again. That cannot change the result: an object that produced no full
	 * match the first time produces none the second time, and its context-less macro, if any, was already taken as
	 * fallback or preceded by an earlier one.
	 */
	public function getLookupOrder(string $hostid): array {
		if (array_key_exists($hostid, $this->order_cache)) {
			return $this->order_cache[$hostid];
		}

		$levels = [[$hostid]];
		$visited = [$hostid => true];
		$current = [$hostid];

		while ($current) {
			$next = [];

			foreach ($current as $objectid) {
				foreach ($this->parents[$objectid] ?? [] as $templateid) {
					$templateid = (string) $templateid;

					if (!array_key_exists($templateid, $visited)) {
						$visited[$templateid] = true;
						$next[] = $templateid;
					}
				}
			}

			if ($next) {
				usort($next, [self::class, 'compareIds']);
				$levels[] = $next;
			}

			$current = $next;
		}

		return $this->order_cache[$hostid] = $levels;
	}

	/**
	 * Resolves one column for one host.
	 *
	 * @param string $hostid
	 * @param array  $column  ['name' => ..., 'mode' => MODE_*, 'context' => ?string, 'macro' => minified macro]
	 *
	 * @return array [
	 *     'defid' => ?string   Winning definition, null if undefined.
	 *     'fallback' => bool   Won as the context-less fallback of a context lookup.
	 *     'chain' => array     Candidate defids in lookup order (for explaining the result).
	 * ]
	 */
	public function resolve(string $hostid, array $column): array {
		$objects = array_merge(...$this->getLookupOrder($hostid));
		$objects[] = self::GLOBAL_ID;

		$winner = null;
		$fallback = null;
		$chain = [];

		foreach ($objects as $objectid) {
			foreach ($this->index[$objectid][$column['name']] ?? [] as $defid) {
				$def = $this->defs[$defid];
				$match = $this->match($def, $column);

				if ($match === 'none') {
					continue;
				}

				$chain[] = $defid;

				if ($winner !== null) {
					continue;
				}

				if ($match === 'full') {
					$winner = $defid;
				}
				elseif ($match === 'base' && $fallback === null) {
					$fallback = $defid;
				}
			}
		}

		if ($winner !== null) {
			return ['defid' => $winner, 'fallback' => false, 'chain' => $chain];
		}

		return ['defid' => $fallback, 'fallback' => $fallback !== null, 'chain' => $chain];
	}

	/**
	 * @return string  'full', 'base' (context-less candidate for fallback) or 'none'.
	 */
	private function match(array $def, array $column): string {
		switch ($column['mode']) {
			case self::MODE_BASE:
				return ($def['context'] === null && $def['regex'] === null) ? 'full' : 'none';

			case self::MODE_LITERAL:
				return $def['macro'] === $column['macro'] ? 'full' : 'none';

			case self::MODE_CONTEXT:
				if ($def['context'] === null && $def['regex'] === null) {
					return 'base';
				}

				if ($def['context'] !== null) {
					return $def['context'] === $column['context'] ? 'full' : 'none';
				}

				return $this->regexMatches($def['regex'], (string) $column['context']) ? 'full' : 'none';
		}

		return 'none';
	}

	/**
	 * PCRE match without flags, as the server does. A regex that does not compile matches nothing.
	 */
	private function regexMatches(string $regex, string $subject): bool {
		$pattern = "\x01".$regex."\x01";

		if (!array_key_exists($regex, $this->regex_ok)) {
			$this->regex_ok[$regex] = @preg_match($pattern, '') !== false;
		}

		return $this->regex_ok[$regex] && preg_match($pattern, $subject) === 1;
	}
}
