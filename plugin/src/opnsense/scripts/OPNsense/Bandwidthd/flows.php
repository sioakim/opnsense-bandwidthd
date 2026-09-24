#!/usr/local/bin/php
<?php
/*
 * flows.php — collect per-device destinations and applications from ntopng.
 *
 * Run every minute by cron while flows_enable is on. Each run polls ntopng's
 * live flows, adds the bytes moved since the previous run to the current hour's
 * bucket, and compacts/expires old buckets. See lib/bwd_flows.inc.php.
 *
 * Usage:
 *   php flows.php               collect once
 *   php flows.php -- --dry-run  fetch and compute, print a summary, write nothing
 *
 * Licensed under the Apache License, Version 2.0.
 */

require_once(__DIR__ . '/lib/bwd_platform.inc.php');
require_once(__DIR__ . '/lib/bwd_data.inc.php');

$dry = in_array('--dry-run', $argv, true);

if (!bwd_cfg_on('flows_enable')) {
	echo "destinations collection disabled (flows_enable) — nothing to do.\n";
	exit(0);
}

/* One collector at a time: cron starts it detached every minute, and a slow
 * ntopng must not let runs overlap and both advance the same flow counters. */
if (!$dry) {
	@mkdir(BWD_FLOWS_DIR, 0700, true);
	$lockFh = @fopen(BWD_FLOWS_DIR . '/collect.lock', 'c');
	if ($lockFh === false || !@flock($lockFh, LOCK_EX | LOCK_NB)) {
		echo "another collection is already running — skipped.\n";
		exit(0);
	}
}

$now = time();
$base = (string) bwd_cfg('ntopng_url', 'http://127.0.0.1:3000');
$token = (string) bwd_cfg('ntopng_token', '');
$topn = (int) bwd_cfg('flows_topn', 100) ?: 100;
$retention = (int) bwd_cfg('flows_retention_days', 31) ?: 31;

$status = function ($ok, $error, $count) use ($dry, $now) {
	if ($dry) { return; }
	bwd_atomic_write(BWD_FLOWS_DIR . '/status.json',
		bwd_json(array('ok' => $ok, 'at' => $now, 'error' => $error, 'flows' => $count)));
};

/* Housekeeping must still run during an ntopng outage. */
if (!$dry) { bwd_flows_compact($now, $topn, $retention); }

if ($token === '') {
	$status(false, 'no ntopng API token configured', 0);
	fwrite(STDERR, "no ntopng API token configured (ntopng_token).\n");
	exit(1);
}

list($flows, $err) = bwd_ntopng_flows($base, $token);
if ($err !== '') {
	/* Keep the previous poll's counters: if ntopng is back within
	   BWD_FLOWS_STALE the next run still computes deltas from them. */
	$status(false, $err, 0);
	fwrite(STDERR, "$err\n");
	exit(1);
}

$stateFile = BWD_FLOWS_DIR . '/state.json';
$state = is_file($stateFile) ? json_decode((string) @file_get_contents($stateFile), true) : null;
/* Old keys omit ports/protocol: baseline once on upgrade rather than treating
 * their replacements as new flows and replaying lifetime counters. */
if (!is_array($state) || ($state['v'] ?? 0) !== 2) { $state = array('ts' => 0, 'flows' => array()); }

$cidrs = bwd_fp_allowed_cidrs();
$isLocal = function ($ip) use ($cidrs) {
	if (!is_ipaddrv4($ip)) { return false; }
	foreach ($cidrs as $c) { if (bwd_ip_in_cidr($ip, $c)) { return true; } }
	return false;
};
$macmap = bwd_macmap();
$idOf = function ($ip) use ($macmap) {
	$mac = strtolower((string) ($macmap[$ip] ?? ''));
	return $mac !== '' ? $mac : $ip;
};

list($next, $add) = bwd_flows_ingest((array) ($state['flows'] ?? array()), (int) ($state['ts'] ?? 0),
	$flows, $isLocal, $idOf, $now);

$bytes = 0.0; $dests = 0;
foreach ($add['hosts'] as $h) {
	$dests += count($h['d']);
	foreach ($h['a'] as $v) { $bytes += $v[0] + $v[1]; }
}
$baseline = !$state['ts'] || ($now - (int) $state['ts']) > BWD_FLOWS_STALE;
printf("%d flows, %d tracked, %d devices, %d destinations, %.1f MB%s\n", count($flows), count($next),
	count($add['hosts']), $dests, $bytes / MB, $baseline ? ' (baseline poll — nothing counted)' : '');

if ($dry) { exit(0); }

$err = bwd_flows_commit($now, $next, $add, $topn);
if ($err !== '') {
	$status(false, $err, count($flows));
	fwrite(STDERR, "$err\n");
	exit(1);
}
$status(true, '', count($flows));
