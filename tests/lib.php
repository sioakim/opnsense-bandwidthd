<?php
/*
 * tests/lib.php — tiny dependency-free assertion helpers (no PHPUnit, matching
 * the repo's no-composer posture). Counters live in globals; run.php reports them.
 *
 * Licensed under the Apache License, Version 2.0.
 */

$GLOBALS['__t_pass'] = 0;
$GLOBALS['__t_fail'] = 0;
$GLOBALS['__t_fails'] = array();
$GLOBALS['__t_group'] = '';

function t_group($name) { $GLOBALS['__t_group'] = $name; }

function t_ok($cond, $msg) {
	if ($cond) {
		$GLOBALS['__t_pass']++;
	} else {
		$GLOBALS['__t_fail']++;
		$GLOBALS['__t_fails'][] = ($GLOBALS['__t_group'] ? '[' . $GLOBALS['__t_group'] . '] ' : '') . $msg;
	}
}

function t_eq($expected, $got, $msg) {
	t_ok($expected === $got, $msg . ' (expected ' . var_export($expected, true) . ', got ' . var_export($got, true) . ')');
}

function t_feq($expected, $got, $msg, $eps = 1e-6) {
	t_ok(abs((float) $expected - (float) $got) <= $eps, $msg . ' (expected ' . $expected . ', got ' . $got . ')');
}
