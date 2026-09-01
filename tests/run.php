<?php
/*
 * tests/run.php — off-box unit runner for the pure data layer.
 *
 *   php tests/run.php
 *
 * Loads the bootstrap (stubs + data layer) and every tests/cases/*.php, then
 * reports pass/fail and exits non-zero on any failure (for CI).
 *
 * Licensed under the Apache License, Version 2.0.
 */

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/lib.php';

foreach (glob(__DIR__ . '/cases/*.php') as $case) {
	require $case;
}

$pass = $GLOBALS['__t_pass'];
$fail = $GLOBALS['__t_fail'];
echo "\n";
foreach ($GLOBALS['__t_fails'] as $m) { echo "FAIL: $m\n"; }
printf("%s\n%d passed, %d failed\n", str_repeat('-', 40), $pass, $fail);
exit($fail ? 1 : 0);
