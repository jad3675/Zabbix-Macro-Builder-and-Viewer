<?php declare(strict_types = 0);

/**
 * Standalone tests for the resolver and macro parsing. No Zabbix install or PHPUnit needed; the parser classes are
 * loaded from a Zabbix source tree:
 *
 *   php tests/ResolverTest.php /path/to/zabbix/ui
 *
 * Expected results follow the server's lookup (src/libs/zbxcacheconfig/user_macro.c, 7.4).
 */

$ui = rtrim($argv[1] ?? '', '/');

if ($ui === '' || !is_file($ui.'/include/classes/parsers/CUserMacroParser.php')) {
	fwrite(STDERR, "usage: php tests/ResolverTest.php /path/to/zabbix/ui\n");
	exit(2);
}

if (!function_exists('_')) { function _($s) { return $s; } }
function _s($s, ...$args) { return vsprintf($s, $args); }

require $ui.'/include/classes/parsers/CParser.php';
require $ui.'/include/classes/parsers/CUserMacroParser.php';
require __DIR__.'/../lib/MacroResolver.php';
require __DIR__.'/../lib/MacroKey.php';

use Modules\MacroMatrix\Lib\MacroKey;
use Modules\MacroMatrix\Lib\MacroResolver;

$failures = 0;
$count = 0;

function check(string $name, $expected, $actual): void {
	global $failures, $count;

	$count++;

	if ($expected !== $actual) {
		$failures++;
		echo "FAIL  $name\n      expected ".var_export($expected, true)."\n      actual   ".var_export($actual, true)."\n";
	}
	else {
		echo "ok    $name\n";
	}
}

$defs = [];

function def(string $id, string $objectid, string $macro): void {
	global $defs;

	$parsed = MacroKey::parse($macro);

	if ($parsed === null) {
		throw new Exception("bad macro $macro");
	}

	$defs[] = $parsed + ['id' => $id, 'objectid' => $objectid];
}

function col(string $macro, ?string $context = null): array {
	$parsed = MacroKey::parse($macro);

	if ($context !== null) {
		return ['name' => $parsed['name'], 'mode' => 'context', 'context' => $context,
			'macro' => MacroKey::withContext($parsed['name'], $context)['macro']
		];
	}

	return $parsed['regex'] !== null
		? ['name' => $parsed['name'], 'mode' => 'literal', 'context' => null, 'macro' => $parsed['macro']]
		: ['name' => $parsed['name'], 'mode' => 'base', 'context' => null, 'macro' => $parsed['macro']];
}

/*
 * Linkage:
 *
 *   host 100 -> templates 30, 20   (level 1, visited as 20 then 30: sorted by ID, not linking order)
 *   template 20 -> 5               (level 2)
 *   template 30 -> 5, 9            (level 2: 5 once, then 9)
 *   template 9 -> 20               (diamond: 20 was already visited at level 1)
 *   host 200 -> template 1000000000000000001 and 99 (uint64 ordering: 99 before the long one)
 *   host 300 -> nothing
 */
$parents = [
	'100' => ['30', '20'],
	'20' => ['5'],
	'30' => ['5', '9'],
	'9' => ['20'],
	'5' => [],
	'200' => ['1000000000000000001', '99'],
	'99' => [],
	'1000000000000000001' => [],
	'300' => []
];

// Base macro on two level-1 templates: lower template ID wins.
def('h1', '30', '{$A}');
def('h2', '20', '{$A}');

// Host beats templates.
def('h3', '5', '{$B}');
def('h4', '100', '{$B}');

// Level 1 beats level 2 regardless of ID.
def('h5', '5', '{$C}');
def('h6', '30', '{$C}');

// Global only.
def('g1', 'global', '{$D}');

// Context: static on template, base on host. Context match anywhere in the host tree beats host base.
def('h7', '100', '{$LOW}');
def('h8', '30', '{$LOW:"/var"}');

// Regex on host beats static on template (host level comes first; the regex matches).
def('h9', '100', '{$R:regex:"^/v"}');
def('h10', '20', '{$R:"/var"}');

// Same object: static context beats regex context.
def('h11', '100', '{$S:regex:".*"}');
def('h12', '100', '{$S:"/var"}');

// Global context match beats a host-level base fallback.
def('h13', '100', '{$G}');
def('g2', 'global', '{$G:"/var"}');

// Fallback: nearest base wins, global base only when the tree has none.
def('h14', '5', '{$F}');
def('h15', '20', '{$F}');
def('g3', 'global', '{$F}');
def('g4', 'global', '{$FG}');

// Invalid regex matches nothing.
def('h16', '100', '{$X:regex:"(unclosed"}');
def('h17', '20', '{$X}');

// uint64 ordering of template IDs.
def('h18', '1000000000000000001', '{$U}');
def('h19', '99', '{$U}');

// Diamond: template 20 reachable at level 1 and again through 30 -> 9 -> 20.
def('h20', '9', '{$DIA}');
def('h21', '20', '{$DIA}');

// Among regex contexts on one object, the lexicographically first regex string is tried first.
def('h22', '100', '{$RX:regex:"^/v"}');
def('h23', '100', '{$RX:regex:"^/"}');

$r = new MacroResolver($parents, $defs);

check('lookup order sorts levels by ID and skips revisits',
	[['100'], ['20', '30'], ['5', '9']], $r->getLookupOrder('100'));
check('uint64 IDs compare numerically', [['200'], ['99', '1000000000000000001']], $r->getLookupOrder('200'));

check('level-1 tie: lower template ID wins', 'h2', $r->resolve('100', col('{$A}'))['defid']);
check('host beats template', 'h4', $r->resolve('100', col('{$B}'))['defid']);
check('level 1 beats level 2', 'h6', $r->resolve('100', col('{$C}'))['defid']);
check('global used when nothing in tree', 'g1', $r->resolve('100', col('{$D}'))['defid']);
check('undefined', null, $r->resolve('300', col('{$A}'))['defid']);

$res = $r->resolve('100', col('{$LOW}', '/var'));
check('template context match beats host base', ['h8', false], [$res['defid'], $res['fallback']]);

$res = $r->resolve('100', col('{$LOW}', '/tmp'));
check('no context match: host base as fallback', ['h7', true], [$res['defid'], $res['fallback']]);

check('host regex beats template static', 'h9', $r->resolve('100', col('{$R}', '/var'))['defid']);
check('same object: static beats regex', 'h12', $r->resolve('100', col('{$S}', '/var'))['defid']);
check('same object: regex when static does not match', 'h11', $r->resolve('100', col('{$S}', '/tmp'))['defid']);

$res = $r->resolve('100', col('{$G}', '/var'));
check('global context match beats host base fallback', ['g2', false], [$res['defid'], $res['fallback']]);

$res = $r->resolve('100', col('{$G}', '/tmp'));
check('no match anywhere: host base fallback', ['h13', true], [$res['defid'], $res['fallback']]);

check('fallback is the nearest base', 'h15', $r->resolve('100', col('{$F}', '/x'))['defid']);
check('global base fallback only when tree has none', 'g4', $r->resolve('100', col('{$FG}', '/x'))['defid']);

check('invalid regex matches nothing, base used', 'h17', $r->resolve('100', col('{$X}', 'abc'))['defid']);
check('uint64 order: 99 before 1000000000000000001', 'h19', $r->resolve('200', col('{$U}'))['defid']);
check('diamond: nearest level wins', 'h21', $r->resolve('100', col('{$DIA}'))['defid']);
check('regex ordering by string', 'h23', $r->resolve('100', col('{$RX}', '/var'))['defid']);

check('base column ignores context macros', 'h7', $r->resolve('100', col('{$LOW}'))['defid']);
check('literal regex column finds exact definition', 'h9', $r->resolve('100', col('{$R:regex:"^/v"}'))['defid']);

check('chain lists all candidates in order', ['h4', 'h3'], $r->resolve('100', col('{$B}'))['chain']);
check('chain for context lookup', ['h7', 'h8'], $r->resolve('100', col('{$LOW}', '/var'))['chain']);

// Template rows resolve like a host linked only to that template.
check('template row lookup order', [['30'], ['5', '9'], ['20']], $r->getLookupOrder('30'));
check('template row: own macro wins', 'h6', $r->resolve('30', col('{$C}'))['defid']);
check('template row: inherited from its own template', 'h3', $r->resolve('30', col('{$B}'))['defid']);
check('template row: context on itself', 'h8', $r->resolve('30', col('{$LOW}', '/var'))['defid']);

// Parsing.
check('quoted and unquoted context minify to one key', MacroKey::parse('{$LOW:/var}')['macro'],
	MacroKey::parse('{$LOW: "/var"}')['macro']);
check('withContext matches stored spelling', MacroKey::parse('{$LOW:/var}')['macro'],
	MacroKey::withContext('LOW', '/var')['macro']);
check('parse unquoted context', '/var', MacroKey::parse('{$LOW:/var}')['context']);
check('parse regex', '^/v', MacroKey::parse('{$R:regex:"^/v"}')['regex']);
check('withContext quotes specials', '{$M:"a}b"}', MacroKey::withContext('M', 'a}b')['macro']);
check('withContext keeps quotes inside', 'say "hi"', MacroKey::withContext('M', 'say "hi"')['context']);
check('withContext leading space', ' x', MacroKey::withContext('M', ' x')['context']);

$p = MacroKey::parsePatterns('{$snmp_*}, LOW_SPACE_LIMIT  {$A:"ctx"}');
check('patterns normalized', ['SNMP_*', 'LOW_SPACE_LIMIT', 'A'], $p['tokens']);
check('exact tokens', ['LOW_SPACE_LIMIT', 'A'], $p['exact']);
check('api search patterns', ['{$SNMP_*', '{$LOW_SPACE_LIMIT*', '{$A*'], $p['search']);
check('regex matches wildcard', 1, preg_match($p['regex'], 'SNMP_COMMUNITY'));
check('regex anchored', 0, preg_match($p['regex'], 'LOW_SPACE_LIMIT_WARN'));
check('dot is literal', 0, preg_match(MacroKey::parsePatterns('A.B')['regex'], 'AXB'));

$threw = false;

try {
	MacroKey::parsePatterns('{$BAD-NAME}');
}
catch (InvalidArgumentException $e) {
	$threw = true;
}

check('invalid pattern rejected', true, $threw);

echo "\n".($count - $failures)." of $count passed\n";
exit($failures > 0 ? 1 : 0);
