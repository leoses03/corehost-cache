<?php
require __DIR__ . '/bootstrap.php';
$GLOBALS['chc_t'] = ['pass' => 0, 'fail' => 0, 'msgs' => []];
function t_assert(bool $cond, string $msg): void {
    if ($cond) { $GLOBALS['chc_t']['pass']++; }
    else { $GLOBALS['chc_t']['fail']++; $GLOBALS['chc_t']['msgs'][] = "FAIL: $msg"; }
}
function t_eq($got, $exp, string $msg): void {
    t_assert($got === $exp, "$msg (esperado " . var_export($exp, true) . ", got " . var_export($got, true) . ")");
}
foreach (glob(__DIR__ . '/test-*.php') as $f) { require $f; }
$r = $GLOBALS['chc_t'];
echo "\n{$r['pass']} passed, {$r['fail']} failed\n";
foreach ($r['msgs'] as $m) { echo "  $m\n"; }
exit($r['fail'] > 0 ? 1 : 0);
