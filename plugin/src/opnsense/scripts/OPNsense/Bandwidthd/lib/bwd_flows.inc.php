<?php
/*
 * bwd_flows.inc.php — per-device destinations and applications, from ntopng.
 *
 * bandwidthd counts bytes per local IP but cannot say where they went. ntopng
 * can — nDPI names the application and records the TLS SNI / HTTP Host / DNS
 * query of every flow — but it only keeps live flows. The flows cron polls
 * ntopng's active-flow list every minute, turns each flow's cumulative counters
 * into a delta since the previous poll, and adds the deltas to hourly buckets
 * keyed by device identity (MAC, else IP), the identity the rest of the plugin
 * uses.
 *
 * The figures are SAMPLED, not accounted: a flow that opens and closes between
 * two polls is never seen, and the tail of a flow that ends after its last poll
 * is lost. The dashboard says so; the CDF totals remain the accounting source.
 *
 * Storage, owner-only like every rollup (bwd_atomic_write):
 *   h/YYYYMMDDHH.json  hourly buckets, kept BWD_FLOWS_HOURLY_DAYS days
 *   d/YYYYMMDD.json    daily buckets compacted from the hourly ones,
 *                      kept flows_retention_days
 *   state.json         each live flow's counters at the previous poll
 *   status.json        outcome of the last collection, for the dashboard
 *
 * Bucket shape:
 *   {"v":1,"hosts":{"<id>":{"ip":"…","d":{"<dest>":[in,out,"<app>"]},
 *                                    "a":{"<app>":[in,out]}}}}
 *
 * Licensed under the Apache License, Version 2.0.
 */

if (!defined('BWD_FLOWS_DIR')) { define('BWD_FLOWS_DIR', BWD_ROLLUP_DIR . '/flows'); }
/* Hour resolution is kept long enough to cover the dashboard's 7-day preset. */
if (!defined('BWD_FLOWS_HOURLY_DAYS')) { define('BWD_FLOWS_HOURLY_DAYS', 8); }
/* A gap longer than this between polls (collector off, ntopng down) makes the
 * next poll a baseline: counters accumulated during the gap cannot be placed in
 * time, so they are not counted at all rather than dumped into one hour. */
if (!defined('BWD_FLOWS_STALE')) { define('BWD_FLOWS_STALE', 600); }
/* Destinations beyond the per-device top N are folded into this one row. */
if (!defined('BWD_FLOWS_OTHER')) { define('BWD_FLOWS_OTHER', '(other)'); }

/* What to call a flow's far end. ntopng's `info` carries the SNI / Host / DNS
 * name, but for some protocols it is free text ("Desktop Sharing" on a Teams
 * STUN flow), so only a hostname-shaped value is used. Then ntopng's own name
 * for the server if it resolved one, then the bare address. */
function bwd_flows_dest_label($info, $remoteName, $remoteIp) {
	foreach (array($info, $remoteName) as $v) {
		$v = strtolower(trim((string) $v));
		if ($v !== '' && strlen($v) <= 253 && strpos($v, '.') !== false &&
			preg_match('/^[a-z0-9_-]+(\.[a-z0-9_-]+)+\.?$/', $v) &&
			!filter_var(rtrim($v, '.'), FILTER_VALIDATE_IP)) {
			return rtrim($v, '.');
		}
	}
	return (string) $remoteIp;
}

/**
 * Turn one poll of ntopng's active flows into per-device byte deltas.
 *
 * $prev     flow key => [sent, recv] from the previous poll (local side's view)
 * $prevTs   time of the previous poll, 0 if none
 * $flows    rows of rest/v2/get/flow/active_list.lua, each with an 'ifid'
 * $isLocal  fn(ip): bool — inside the monitored subnets
 * $idOf     fn(ip): string — device identity (MAC, else the IP)
 *
 * Returns [new $prev, bucket-shaped deltas]. Only flows with exactly one local
 * end count: local-to-local traffic that crosses the firewall is the firewall's
 * own services (DNS, GUI), and a flow with no local end is not a device's.
 */
function bwd_flows_ingest($prev, $prevTs, $flows, $isLocal, $idOf, $now) {
	$baseline = !$prevTs || ($now - $prevTs) > BWD_FLOWS_STALE;
	$next = array();
	$add = array('v' => 1, 'hosts' => array());
	foreach ((array) $flows as $f) {
		$cli = (string) ($f['client']['ip'] ?? '');
		$srv = (string) ($f['server']['ip'] ?? '');
		$cliLocal = $cli !== '' && $isLocal($cli);
		$srvLocal = $srv !== '' && $isLocal($srv);
		if ($cliLocal === $srvLocal) { continue; }
		$local = $cliLocal ? $cli : $srv;
		$cb = (float) ($f['bytes']['cli_bytes'] ?? 0);
		$sb = (float) ($f['bytes']['srv_bytes'] ?? 0);
		$sent = $cliLocal ? $cb : $sb;
		$recv = $cliLocal ? $sb : $cb;
		$key = ($f['ifid'] ?? 0) . '|' . ($f['key'] ?? '') . '|' . (int) ($f['first_seen'] ?? 0) . '|' . $cli . '|' . $srv;
		$next[$key] = array($sent, $recv);

		if (isset($prev[$key])) {
			$dOut = $sent - $prev[$key][0];
			$dIn = $recv - $prev[$key][1];
			/* Counters only grow; a drop means ntopng reused the key for a new flow. */
			if ($dOut < 0 || $dIn < 0) { $dOut = $sent; $dIn = $recv; }
		} elseif ($baseline) {
			continue;
		} else {
			/* Started since the last poll, so all of it belongs after that poll. */
			$dOut = $sent; $dIn = $recv;
		}
		if ($dIn + $dOut <= 0) { continue; }

		$remote = $cliLocal ? ($f['server'] ?? array()) : ($f['client'] ?? array());
		$dest = bwd_flows_dest_label($f['info'] ?? '', $remote['name'] ?? '', $cliLocal ? $srv : $cli);
		$app = (string) ($f['application']['name'] ?? '');
		if ($app === '') { $app = (string) ($f['l4_proto']['name'] ?? 'Unknown'); }

		$id = (string) $idOf($local);
		if (!isset($add['hosts'][$id])) { $add['hosts'][$id] = array('ip' => $local, 'd' => array(), 'a' => array()); }
		$h = &$add['hosts'][$id];
		if (!isset($h['d'][$dest])) { $h['d'][$dest] = array(0.0, 0.0, $app); }
		$h['d'][$dest][0] += $dIn; $h['d'][$dest][1] += $dOut;
		if (!isset($h['a'][$app])) { $h['a'][$app] = array(0.0, 0.0); }
		$h['a'][$app][0] += $dIn; $h['a'][$app][1] += $dOut;
		unset($h);
	}
	return array($next, $add);
}

/* Add bucket $add into bucket $dst, then keep each device's top $topn
 * destinations and fold the rest into BWD_FLOWS_OTHER. Applications are few
 * and are never capped. */
function bwd_flows_merge(&$dst, $add, $topn) {
	if (!isset($dst['hosts']) || !is_array($dst['hosts'])) { $dst = array('v' => 1, 'hosts' => array()); }
	foreach ((array) ($add['hosts'] ?? array()) as $id => $ah) {
		if (!isset($dst['hosts'][$id])) { $dst['hosts'][$id] = array('ip' => '', 'd' => array(), 'a' => array()); }
		$h = &$dst['hosts'][$id];
		if (!empty($ah['ip'])) { $h['ip'] = $ah['ip']; }
		foreach ((array) ($ah['d'] ?? array()) as $dest => $v) {
			if (!isset($h['d'][$dest])) { $h['d'][$dest] = array(0.0, 0.0, (string) ($v[2] ?? '')); }
			$h['d'][$dest][0] += $v[0]; $h['d'][$dest][1] += $v[1];
		}
		foreach ((array) ($ah['a'] ?? array()) as $app => $v) {
			if (!isset($h['a'][$app])) { $h['a'][$app] = array(0.0, 0.0); }
			$h['a'][$app][0] += $v[0]; $h['a'][$app][1] += $v[1];
		}
		bwd_flows_cap($h['d'], $topn);
		unset($h);
	}
}

/* Keep the $topn largest destinations; sum the remainder into BWD_FLOWS_OTHER. */
function bwd_flows_cap(&$d, $topn) {
	$topn = max(1, (int) $topn);
	if (count($d) <= $topn) { return; }
	$other = $d[BWD_FLOWS_OTHER] ?? array(0.0, 0.0, '');
	unset($d[BWD_FLOWS_OTHER]);
	uasort($d, function ($a, $b) { return ($b[0] + $b[1]) <=> ($a[0] + $a[1]); });
	$keep = $topn - 1;                    // the (other) row takes the last slot
	foreach (array_slice($d, $keep, null, true) as $dest => $v) {
		$other[0] += $v[0]; $other[1] += $v[1];
	}
	$d = array_slice($d, 0, $keep, true);
	$other[2] = '';
	$d[BWD_FLOWS_OTHER] = $other;
}

function bwd_flows_hour_file($ts, $dir = BWD_FLOWS_DIR) { return $dir . '/h/' . date('YmdH', $ts) . '.json'; }
function bwd_flows_day_file($ts, $dir = BWD_FLOWS_DIR) { return $dir . '/d/' . date('Ymd', $ts) . '.json'; }

function bwd_flows_load($file) {
	$j = is_file($file) ? json_decode((string) @file_get_contents($file), true) : null;
	return (is_array($j) && isset($j['hosts']) && is_array($j['hosts'])) ? $j : array('v' => 1, 'hosts' => array());
}

/* Start of the hour-resolution horizon: older data is read from daily files. */
function bwd_flows_hourly_horizon($now) {
	return strtotime(date('Y-m-d', $now - BWD_FLOWS_HOURLY_DAYS * 86400) . ' 00:00:00');
}

/**
 * Housekeeping, run by the collector: compact every finished day that still has
 * only hourly files into its daily file, then expire hourly files past the
 * horizon and daily files past $retentionDays. Returns the number of days
 * compacted.
 */
function bwd_flows_compact($now, $topn, $retentionDays, $dir = BWD_FLOWS_DIR) {
	$today = date('Ymd', $now);
	$byDay = array();
	foreach ((glob($dir . '/h/*.json') ?: array()) as $f) {
		$day = substr(basename($f, '.json'), 0, 8);
		if (strlen($day) === 8) { $byDay[$day][] = $f; }
	}
	$compacted = 0;
	foreach ($byDay as $day => $files) {
		if ($day >= $today || is_file("$dir/d/$day.json")) { continue; }
		$b = array('v' => 1, 'hosts' => array());
		sort($files);
		foreach ($files as $f) { bwd_flows_merge($b, bwd_flows_load($f), $topn); }
		if (bwd_atomic_write("$dir/d/$day.json", bwd_json($b))) { $compacted++; }
	}
	$hCut = date('YmdH', bwd_flows_hourly_horizon($now));
	foreach ((glob($dir . '/h/*.json') ?: array()) as $f) {
		$h = basename($f, '.json');
		/* Only once its day has been compacted, so no hour is lost if the
		   compaction write above failed. */
		if ($h < $hCut && is_file("$dir/d/" . substr($h, 0, 8) . '.json')) { @unlink($f); }
	}
	$dCut = date('Ymd', $now - max(1, (int) $retentionDays) * 86400);
	foreach ((glob($dir . '/d/*.json') ?: array()) as $f) {
		if (basename($f, '.json') < $dCut) { @unlink($f); }
	}
	return $compacted;
}

/* The bucket files covering [$from, $to]: hourly inside the horizon, daily
 * before it. A daily file is all-or-nothing, so a window that starts part-way
 * through an old day includes that whole day. */
function bwd_flows_files($from, $to, $now, $dir = BWD_FLOWS_DIR) {
	$horizon = bwd_flows_hourly_horizon($now);
	$files = array();
	for ($t = strtotime(date('Y-m-d', $from) . ' 00:00:00'); $t <= $to; $t = strtotime('+1 day', $t)) {
		if ($t < $horizon) {
			$f = bwd_flows_day_file($t, $dir);
			if (is_file($f)) { $files[] = $f; }
			continue;
		}
		$end = strtotime('+1 day', $t);
		for ($hh = $t; $hh < $end; $hh += 3600) {
			if ($hh + 3600 <= $from || $hh > $to) { continue; }
			$f = bwd_flows_hour_file($hh, $dir);
			if (is_file($f)) { $files[] = $f; }
		}
	}
	return $files;
}

/* The earliest time any bucket covers — "collecting since" on the dashboard. */
function bwd_flows_since($dir = BWD_FLOWS_DIR) {
	$first = 0;
	foreach (array('d', 'h') as $sub) {
		$names = array_map(function ($f) { return basename($f, '.json'); }, (glob("$dir/$sub/*.json") ?: array()));
		if (!$names) { continue; }
		sort($names);
		$n = $names[0];
		$ts = strtotime(substr($n, 0, 4) . '-' . substr($n, 4, 2) . '-' . substr($n, 6, 2) .
			(strlen($n) === 10 ? ' ' . substr($n, 8, 2) . ':00:00' : ' 00:00:00'));
		if ($ts && (!$first || $ts < $first)) { $first = $ts; }
	}
	return $first;
}

/**
 * Destinations and applications for a device over a window.
 *
 * $id is a MAC, an IP, or '0.0.0.0' for every device ($tags narrows that to the
 * devices carrying them, as everywhere else). Returns the top $limit
 * destinations by total bytes, plus every application.
 */
function bwd_destinations($id, $period, $from = 0, $to = 0, $tags = array(), $limit = 100, $dir = BWD_FLOWS_DIR, $now = 0) {
	$now = $now ?: time();
	$to2 = $to ?: $now;
	$from2 = $from ?: ($to2 - bwd_default_window($period));
	$id = strtolower(trim((string) $id));
	$out = array('id' => $id, 'from' => $from2, 'to' => $to2, 'since' => bwd_flows_since($dir),
		'dests' => array(), 'apps' => array(), 'total_in' => 0, 'total_out' => 0, 'more' => 0);

	$all = ($id === '0.0.0.0');
	$keys = array(); $ips = array();
	if ($all && $tags) {
		$scope = bwd_tag_scope($tags, $period, $from, $to);
		$all = false;
		foreach ($scope['keys'] as $k) { $keys[strtolower($k)] = true; }
		$ips = $scope['ips'];
	} elseif (!$all) {
		$keys[$id] = true;
		if (is_ipaddrv4($id)) { $ips[$id] = true; }
	}

	$d = array(); $a = array();
	foreach (bwd_flows_files($from2, $to2, $now, $dir) as $f) {
		foreach (bwd_flows_load($f)['hosts'] as $hid => $h) {
			if (!$all && !isset($keys[strtolower($hid)]) && !isset($ips[$h['ip'] ?? ''])) { continue; }
			foreach ((array) ($h['d'] ?? array()) as $dest => $v) {
				if (!isset($d[$dest])) { $d[$dest] = array(0.0, 0.0, (string) ($v[2] ?? '')); }
				$d[$dest][0] += $v[0]; $d[$dest][1] += $v[1];
			}
			foreach ((array) ($h['a'] ?? array()) as $app => $v) {
				if (!isset($a[$app])) { $a[$app] = array(0.0, 0.0); }
				$a[$app][0] += $v[0]; $a[$app][1] += $v[1];
			}
		}
	}

	$rows = array();
	foreach ($d as $dest => $v) {
		$rows[] = array('name' => (string) $dest, 'app' => $v[2], 'in' => $v[0], 'out' => $v[1], 'total' => $v[0] + $v[1],
			'other' => ($dest === BWD_FLOWS_OTHER));
		$out['total_in'] += $v[0]; $out['total_out'] += $v[1];
	}
	/* Largest first, but the (other) remainder always last whatever its size. */
	usort($rows, function ($x, $y) {
		if ($x['other'] !== $y['other']) { return $x['other'] ? 1 : -1; }
		return $y['total'] <=> $x['total'];
	});
	$limit = max(1, (int) $limit);
	$out['more'] = max(0, count($rows) - $limit);
	$out['dests'] = array_slice($rows, 0, $limit);
	foreach ($a as $app => $v) {
		$out['apps'][] = array('name' => (string) $app, 'in' => $v[0], 'out' => $v[1], 'total' => $v[0] + $v[1]);
	}
	usort($out['apps'], function ($x, $y) { return $y['total'] <=> $x['total']; });
	return $out;
}

/* GET a JSON document from ntopng's REST API. Returns [decoded rsp, error].
 * curl, not file_get_contents(): OPNsense's PHP runs with allow_url_fopen off,
 * and php-curl is a dependency of the core package, so it is always there. */
function bwd_ntopng_get($base, $token, $path, $timeout = 30) {
	$ch = curl_init(rtrim($base, '/') . $path);
	curl_setopt_array($ch, array(
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_HTTPHEADER => array('Authorization: Token ' . $token),
		CURLOPT_CONNECTTIMEOUT => 5,
		CURLOPT_TIMEOUT => $timeout,
		/* An expired token makes ntopng redirect to its login page; following
		   it would report "not JSON" instead of the real cause. */
		CURLOPT_FOLLOWLOCATION => false,
		CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
	));
	$body = curl_exec($ch);
	$status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
	$cerr = curl_error($ch);
	if ($body === false || $status === 0) { return array(null, 'ntopng unreachable at ' . $base . ($cerr !== '' ? " ($cerr)" : '')); }
	if ($status >= 300 && $status < 400) { return array(null, 'ntopng rejected the API token (redirected to login)'); }
	if ($status !== 200) { return array(null, "ntopng returned HTTP $status"); }
	$j = json_decode($body, true);
	if (!is_array($j) || !array_key_exists('rsp', $j)) { return array(null, 'ntopng returned a non-JSON response'); }
	if ((int) ($j['rc'] ?? 0) !== 0) { return array(null, 'ntopng error: ' . ($j['rc_str_hr'] ?? $j['rc_str'] ?? 'unknown')); }
	return array($j['rsp'], '');
}

/* Every live flow on every interface ntopng monitors, each tagged with its ifid. */
function bwd_ntopng_flows($base, $token) {
	list($ifs, $err) = bwd_ntopng_get($base, $token, '/lua/rest/v2/get/ntopng/interfaces.lua');
	if ($err !== '') { return array(null, $err); }
	$flows = array();
	foreach ((array) $ifs as $if) {
		$ifid = (int) ($if['ifid'] ?? 0);
		list($rows, $err) = bwd_ntopng_get($base, $token,
			"/lua/rest/v2/get/flow/active_list.lua?ifid=$ifid&start=0&length=1000000");
		if ($err !== '') { return array(null, $err); }
		foreach ((array) $rows as $r) { $r['ifid'] = $ifid; $flows[] = $r; }
	}
	return array($flows, '');
}

/* Outcome of the last collection, for the dashboard; small on purpose, since
 * the status endpoint reads it on every page load. */
function bwd_flows_status($dir = BWD_FLOWS_DIR) {
	$j = is_file("$dir/status.json") ? json_decode((string) @file_get_contents("$dir/status.json"), true) : null;
	return is_array($j) ? $j : array('ok' => false, 'at' => 0, 'error' => '', 'flows' => 0);
}
