<?php declare(strict_types = 0);

/**
 * Template usage counting (direct vs. through linked templates) with a stubbed API.
 *
 *   php tests/UsageTest.php
 */

if (!function_exists('_')) { function _($s) { return $s; } }
function _s($s, ...$a) { return vsprintf($s, $a); }

class StubService {
	public function __construct(private array $rows) {}
	public function get(array $options) { return $this->rows; }
}

class API {
	public static array $hosts = [];
	public static array $templates = [];
	public static function Host() { return new StubService(self::$hosts); }
	public static function Template() { return new StubService(self::$templates); }
}

require __DIR__.'/../lib/MacroResolver.php';
require __DIR__.'/../lib/MacroKey.php';
require __DIR__.'/../lib/MacroData.php';

use Modules\MacroMatrix\Lib\MacroData;

$links = static fn(array $ids): array => array_map(static fn($id) => ['templateid' => (string) $id], $ids);

/*
 * T1 <- T2 <- T3   (T3 links T2, T2 links T1)
 * T4 unused, T5 linked directly and also through T3's chain? no: separate.
 * H1 -> T3          (uses T3 directly, T2 and T1 through it)
 * H2 -> T2, T1      (T1 both directly and through T2: counted once in total)
 * H3 -> T5
 */
API::$templates = [
	1 => ['templateid' => 1, 'parentTemplates' => []],
	2 => ['templateid' => 2, 'parentTemplates' => $links([1])],
	3 => ['templateid' => 3, 'parentTemplates' => $links([2])],
	4 => ['templateid' => 4, 'parentTemplates' => []],
	5 => ['templateid' => 5, 'parentTemplates' => []]
];
API::$hosts = [
	101 => ['hostid' => 101, 'parentTemplates' => $links([3])],
	102 => ['hostid' => 102, 'parentTemplates' => $links([2, 1])],
	103 => ['hostid' => 103, 'parentTemplates' => $links([5])]
];

$usage = MacroData::getTemplateUsage();
$fail = 0;

$expect = [
	'1' => ['direct' => 1, 'total' => 2],
	'2' => ['direct' => 1, 'total' => 2],
	'3' => ['direct' => 1, 'total' => 1],
	'5' => ['direct' => 1, 'total' => 1]
];

foreach ($expect as $id => $counts) {
	$got = $usage[$id] ?? null;
	$ok = $got == $counts;
	$fail += $ok ? 0 : 1;
	echo ($ok ? 'ok    ' : 'FAIL  ')."template $id: ".json_encode($got)."\n";
}

$ok = !array_key_exists('4', $usage) && !array_key_exists(4, $usage);
$fail += $ok ? 0 : 1;
echo ($ok ? 'ok    ' : 'FAIL  ')."unused template 4 absent\n";

exit($fail ? 1 : 0);
