<?php
/*
 * tests/bootstrap.php — off-box harness bootstrap.
 *
 * The data layer is output-free and reaches the box only through the platform
 * layer (bwd_platform.inc.php), which needs OPNsense's config.inc. Off-box we
 * stub that seam instead of loading it, so the pure functions run with no
 * firewall, no DB and no network, and BWD_BASE points at the fixture CDFs.
 *
 * Licensed under the Apache License, Version 2.0.
 */

error_reporting(E_ALL & ~E_DEPRECATED);

define('BWD_TEST_STUBBED', true);
if (!defined('BWD_BASE')) { define('BWD_BASE', __DIR__ . '/fixtures/cdf'); }
if (!defined('BWD_MODEL_PATH')) { define('BWD_MODEL_PATH', 'OPNsense/Bandwidthd'); }

/* --- the platform seam, stubbed ------------------------------------------- */

/* Settings the tests want to pretend are set; a case can override this. */
$GLOBALS['bwd_test_cfg'] = array();

function bwd_cfg($key, $default = null) {
	return array_key_exists($key, $GLOBALS['bwd_test_cfg']) ? $GLOBALS['bwd_test_cfg'][$key] : $default;
}
function bwd_cfg_on($key) { return bwd_cfg($key, 'off') === 'on'; }
function bwd_config_path($path, $default = null) { return $default; }

/* Override rows the tests inject, in the shape bwd_overrides_rows() returns. */
$GLOBALS['bwd_test_overrides'] = array();
function bwd_overrides_rows() { return $GLOBALS['bwd_test_overrides']; }
function bwd_overrides_save($rows, $reason = '') { $GLOBALS['bwd_test_overrides'] = $rows; return true; }

/* Identity resolution reaches ARP / Kea / dnsmasq on the box; off-box the tests
 * supply the maps directly. */
$GLOBALS['bwd_test_hostmap'] = array();
$GLOBALS['bwd_test_macmap'] = array();
function bwd_platform_hostmap() { return $GLOBALS['bwd_test_hostmap']; }
function bwd_platform_macmap() { return $GLOBALS['bwd_test_macmap']; }
function bwd_platform_subnets() { return array(); }
function bwd_platform_realif($if) { return $if; }
function bwd_notify($subject, $message = '', $priority = 5) { return true; }

if (!function_exists('is_ipaddrv4')) {
	function is_ipaddrv4($ip) { return (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4); }
}
if (!function_exists('is_ipaddr')) {
	function is_ipaddr($ip) { return (bool) filter_var($ip, FILTER_VALIDATE_IP); }
}

$__lib = dirname(__DIR__) . '/plugin/src/opnsense/scripts/OPNsense/Bandwidthd/lib';
$__bwd_data = $__lib . '/bwd_data.inc.php';
if (!is_file($__bwd_data)) { fwrite(STDERR, "data layer not found at $__bwd_data\n"); exit(2); }
require_once($__bwd_data);
