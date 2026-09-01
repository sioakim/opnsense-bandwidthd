#!/usr/local/bin/php
<?php
/*
 * bandwidthd_alerts.php — cron-driven traffic alert evaluator.
 *
 * Maintains a durable per-day rollup (survives CDF rotation) that records each
 * IP's MAC address, and evaluates usage-quota / anomaly / exfiltration /
 * new-device rules keyed by DEVICE (MAC when known, IP otherwise — so a device
 * keeps its identity and history across DHCP IP changes). Raises notices via
 * bwd_notify(), de-duplicated so each condition alerts at most once per day
 * (new devices once ever).
 *
 * Run by cron (every 15 min). Use --dry-run to print decisions without firing
 * notices or writing state.
 *
 * Licensed under the Apache License, Version 2.0.
 */

require_once(__DIR__ . '/lib/bwd_platform.inc.php');
require_once(__DIR__ . '/lib/bwd_data.inc.php');

define('BWD_ROLLUP_DIR', BWD_BASE . '/rollups');
define('BWD_ROLLUP', BWD_ROLLUP_DIR . '/daily.json');
define('BWD_ALERT_STATE', BWD_ROLLUP_DIR . '/alert_state.json');
define('GB', 1073741824.0);
define('MB', 1048576.0);

$DRY = in_array('--dry-run', $argv, true) || in_array('-n', $argv, true);

/* Each alert carries a per-type urgency on a scale where a LOWER number is MORE
 * urgent. Map it onto syslog levels so the
 * ordering survives and an operator filtering the log at >= warning still sees
 * the quota and anomaly alerts. Without this every alert logs identically and
 * severity filtering is useless. */
function bwd_alert_syslog_level($prio) {
	switch ((int) $prio) {
		case 1:  return LOG_ERR;
		case 2:  return LOG_WARNING;
		default: return LOG_NOTICE;
	}
}

/* Normalize a global checkbox to 'on'/'off'. */
function bwd_flag($k) { return bwd_cfg($k) === 'on' ? 'on' : 'off'; }
/* Resolve an effective per-device setting: a per-host override row (matched by
 * MAC, then IP) wins over the global value when set to something other than
 * 'inherit'/empty. Otherwise the global default is used. */
function dev_cfg($dev, $field, $global) {
	static $ov = null;
	if ($ov === null) { $ov = bwd_host_overrides(); }
	foreach (array(strtolower($dev['mac'] ?? ''), strtolower($dev['ip'] ?? '')) as $k) {
		if ($k !== '' && isset($ov[$k])) {
			$v = $ov[$k][$field] ?? '';
			if (is_string($v)) { $v = trim($v); }
			if ($v !== '' && $v !== 'inherit') { return $v; }
		}
	}
	return $global;
}
function jload($f) { return is_file($f) ? (json_decode(@file_get_contents($f), true) ?: array()) : array(); }
function jsave($f, $d) { return bwd_atomic_write($f, bwd_json($d)); }
/* True when a JSON state file exists and is non-empty on disk but fails to decode
 * (truncated / corrupt) — distinct from a legitimately-absent file. */
function jcorrupt($f) {
	if (!is_file($f)) { return false; }
	$raw = @file_get_contents($f);
	return (trim((string) $raw) !== '' && json_decode($raw, true) === null);
}

/* Group a day's IP-keyed rollup into devices, keyed by MAC when known. */
function day_devices($dayArr) {
	$dev = array();
	foreach ((array) $dayArr as $ip => $v) {
		if ($ip === '0.0.0.0') { continue; }
		$mac = $v['mac'] ?? '';
		$key = $mac ? "mac:$mac" : "ip:$ip";
		if (!isset($dev[$key])) { $dev[$key] = array('in' => 0, 'out' => 0, 'ip' => $ip, 'mac' => $mac, 'key' => $key); }
		$dev[$key]['in']  += $v['in'];
		$dev[$key]['out'] += $v['out'];
		$dev[$key]['ip']   = $ip;   // most-recent IP for this device
	}
	return $dev;
}

/* Trailing-N-day average (in+out) for a device key, excluding today. Reads the
 * external DB (full history) when enabled, else the JSON rollup. */
function device_baseline($roll, $key, $days = 7) {
	if (function_exists('bwd_use_db') && bwd_use_db()) {
		$vals = bwd_db_device_baseline($key, $days);
		return $vals ? array_sum($vals) / count($vals) : null;
	}
	$today = date('Y-m-d');
	$dates = array_keys($roll); rsort($dates);
	$vals = array(); $n = 0;
	foreach ($dates as $d) {
		if ($d === $today) { continue; }
		$dev = day_devices($roll[$d] ?? array());
		if (isset($dev[$key])) { $vals[] = $dev[$key]['in'] + $dev[$key]['out']; }
		if (++$n >= $days) { break; }
	}
	return $vals ? array_sum($vals) / count($vals) : null;
}

function dev_label($dev, $names) {
	$ip = $dev['ip'];
	$name = bwd_name($ip, $dev['mac'] ?? '', $names[$ip] ?? '');
	$s = $ip;
	if ($name) { $s .= " ($name)"; }
	if (!empty($dev['mac'])) { $s .= " [{$dev['mac']}]"; }
	return $s;
}

/* ---- 1. Update the durable rollup with today's totals + MAC (always). ---- */
$today = date('Y-m-d');
$midnight = strtotime('today 00:00:00');
$macmap = bwd_macmap();
$todayData = bwd_hosts(1, $midnight, 0);
/* Refuse to touch a corrupt rollup: if daily.json is present but won't decode, a
 * blind jload() returns [] and we'd overwrite the whole multi-year history with
 * just today. Fail loud and leave the file for the operator to restore/remove. */
if (jcorrupt(BWD_ROLLUP)) {
	bwd_notify("BandwidthD", "Durable rollup " . BWD_ROLLUP . " is corrupt — refusing to overwrite it (that would wipe history). Restore from backup or delete the file to rebuild.", LOG_ERR);
	fwrite(STDERR, "rollup " . BWD_ROLLUP . " is corrupt; refusing to overwrite. Restore or remove it.\n");
	exit(1);
}
$roll = jload(BWD_ROLLUP);
$roll[$today] = array();
foreach ($todayData['hosts'] as $h) {
	$roll[$today][$h['ip']] = array('in' => round($h['in']), 'out' => round($h['out']), 'mac' => $macmap[$h['ip']] ?? '');
}
$th = $todayData['total_host'];
$roll[$today]['0.0.0.0'] = array('in' => round($th['in']), 'out' => round($th['out']), 'mac' => '');
if (count($roll) > 400) { ksort($roll); $roll = array_slice($roll, -400, null, true); }
if (!$DRY) { jsave(BWD_ROLLUP, $roll); }

/* ---- 2. Evaluate rules (device-centric) ---- */
$alerts = array();   // [key, message, priority]
$names = bwd_hostmap();

/* Global defaults; a device is "armed" if its effective alerts setting (global,
 * overridable per host) is on. This lets alerts be globally off but enabled for
 * a single host, and vice-versa. */
$gArmed = bwd_flag('alerts_enable');
$overrides = bwd_host_overrides();
$anyHostArmed = false;
foreach ($overrides as $r) { if (($r['alerts_enable'] ?? '') === 'on') { $anyHostArmed = true; break; } }

if ($gArmed === 'on' || $anyHostArmed) {
	$todayDev = day_devices($roll[$today]);

	// rolling-24h per-device snapshot (anomaly / exfil)
	$h24raw = array();
	foreach (bwd_hosts(1, time() - 86400, 0)['hosts'] as $h) {
		$h24raw[$h['ip']] = array('in' => $h['in'], 'out' => $h['out'], 'mac' => $macmap[$h['ip']] ?? '');
	}
	$dev24 = day_devices($h24raw);

	$armed = function($d) use ($gArmed) {
		return dev_cfg($d, 'alerts_enable', $gArmed) === 'on';
	};

	// (1) interface daily quota — interface-wide, stays global (not per-device)
	if ($gArmed === 'on') {
		$iq = (float) bwd_cfg('quota_iface_gb', 0);
		if ($iq > 0) {
			$tot = ($roll[$today]['0.0.0.0']['in'] + $roll[$today]['0.0.0.0']['out']) / GB;
			if ($tot >= $iq) {
				$alerts[] = array("quota_iface:$today",
					sprintf("Interface daily traffic %.2f GB exceeded the %g GB quota.", $tot, $iq), 2);
			}
		}
	}
	// (1b) per-device daily quota (global default, per-host override)
	$gHq = bwd_cfg('quota_host_gb', 0);
	foreach ($todayDev as $key => $d) {
		if (!$armed($d)) { continue; }
		$hq = (float) dev_cfg($d, 'quota_host_gb', $gHq);
		if ($hq <= 0) { continue; }
		$g = ($d['in'] + $d['out']) / GB;
		if ($g >= $hq) {
			$alerts[] = array("quota_dev:$key:$today",
				sprintf("Device %s used %.2f GB today (quota %g GB).", dev_label($d, $names), $g, $hq), 2);
		}
	}
	// (2) anomaly vs trailing-7-day baseline (global default, per-host override)
	$gAnomaly = bwd_flag('anomaly_enable');
	$k = (float) bwd_cfg('anomaly_factor', 3); if ($k < 1.5) { $k = 3; }
	$floor = 500 * MB;
	foreach ($dev24 as $key => $d) {
		if (!$armed($d) || dev_cfg($d, 'anomaly_enable', $gAnomaly) !== 'on') { continue; }
		$cur = $d['in'] + $d['out'];
		if ($cur < $floor) { continue; }
		$avg = device_baseline($roll, $key, 7);
		if ($avg === null || $avg <= 0) { continue; }   // not enough history yet
		if ($cur >= $k * $avg) {
			$alerts[] = array("anomaly:$key:$today",
				sprintf("Device %s used %.2f GB in 24h — %.1f× its 7-day average (%.2f GB).",
					dev_label($d, $names), $cur / GB, $cur / $avg, $avg / GB), 2);
		}
	}
	// (3) exfiltration / upload spike (global default, per-host override)
	$gExfil = bwd_flag('exfil_enable');
	$floor = 1 * GB;
	foreach ($dev24 as $key => $d) {
		if (!$armed($d) || dev_cfg($d, 'exfil_enable', $gExfil) !== 'on') { continue; }
		if ($d['out'] >= $floor && $d['out'] >= 2 * $d['in']) {
			$alerts[] = array("exfil:$key:$today",
				sprintf("Device %s uploaded %.2f GB (downloaded %.2f GB) in 24h — outbound-dominant, possible exfiltration.",
					dev_label($d, $names), $d['out'] / GB, $d['in'] / GB), 3);
		}
	}
	// (4) new device (device key unseen on any prior day, > 50 MB today).
	// Skip entirely until we have at least one prior day of history, otherwise
	// every device looks "new" on the first run and floods notifications.
	$gNewDev = bwd_flag('newdevice_enable');
	$floor = 50 * MB;
	$priorKeys = array();
	foreach ($roll as $d => $day) {
		if ($d === $today) { continue; }
		foreach (day_devices($day) as $key => $unused) { $priorKeys[$key] = true; }
	}
	// Union the full DB history of known devices (beyond the 400-day rollup
	// cap) so a long-known device is never mis-flagged as new.
	if (function_exists('bwd_use_db') && bwd_use_db()) {
		$priorKeys += bwd_db_known_keys();
	}
	if (!empty($priorKeys)) {
		foreach ($todayDev as $key => $d) {
			if (!$armed($d) || dev_cfg($d, 'newdevice_enable', $gNewDev) !== 'on') { continue; }
			if (($d['in'] + $d['out']) < $floor) { continue; }
			if (!isset($priorKeys[$key])) {
				$alerts[] = array("newdev:$key",
					sprintf("New device %s appeared today with %.0f MB of traffic.",
						dev_label($d, $names), ($d['in'] + $d['out']) / MB), 2);
			}
		}
	}
}

/* ---- 3. De-duplicate and fire ---- */
$state = jload(BWD_ALERT_STATE);
$fired = array();
foreach ($alerts as $a) {
	list($key, $msg, $prio) = $a;
	if (isset($state[$key])) { continue; }
	$fired[] = $msg;
	if (!$DRY) {
		bwd_notify("BandwidthD", $msg, bwd_alert_syslog_level($prio));
		$state[$key] = time();
	}
}
if (count($state) > 2000) {
	// Prune the per-day dedup keys (which churn daily) but NEVER the newdev:* keys —
	// they encode the "alert once EVER" guarantee, so dropping them would let a
	// long-known device re-alert as new once state grows past the cap.
	$newdev = array(); $other = array();
	foreach ($state as $k => $v) {
		if (strpos($k, 'newdev:') === 0) { $newdev[$k] = $v; } else { $other[$k] = $v; }
	}
	$state = $newdev + array_slice($other, -1000, null, true);
}
if (!$DRY) { jsave(BWD_ALERT_STATE, $state); }

echo ($DRY ? "DRY RUN — would fire " : "fired ") . count($fired) . " alert(s)" .
	($DRY ? " (rollup/state NOT written)" : "") . ":\n";
foreach ($fired as $m) { echo " - $m\n"; }
