#!/usr/local/bin/php
<?php
/*
 * bandwidthd_report.php — cron-driven scheduled usage + anomaly report.
 *
 * Builds a daily or weekly traffic report from the durable, MAC-keyed rollup
 * (so figures survive CDF rotation) and emails it as a multipart HTML+text
 * message through the SMTP server bwd_smtp_config() resolves. Covers period
 * totals, top talkers, per-device usage, interface-quota status, and the alerts
 * that fired during the window.
 *
 * Cron registers this daily (00:05) for "daily" or Mondays for "weekly".
 * Flags: --dry-run (print, don't send), --window=today|yesterday|week (override
 * the reporting window for testing).
 *
 * Licensed under the Apache License, Version 2.0.
 */

require_once(__DIR__ . '/lib/bwd_platform.inc.php');
require_once(__DIR__ . '/lib/bwd_data.inc.php');

define('BWD_ROLLUP_DIR', BWD_BASE . '/rollups');
define('BWD_ROLLUP', BWD_ROLLUP_DIR . '/daily.json');
define('BWD_ALERT_STATE', BWD_ROLLUP_DIR . '/alert_state.json');
define('GB', 1073741824.0);

$DRY = in_array('--dry-run', $argv, true) || in_array('-n', $argv, true);
$winOverride = '';
foreach ($argv as $a) {
	if (strncmp($a, '--window=', 9) === 0) { $winOverride = substr($a, 9); }
}

function rcfg($k, $d = '') {
	$v = bwd_cfg($k, null);
	return ($v === null || $v === '') ? $d : $v;
}
function rload($f) { return is_file($f) ? (json_decode(@file_get_contents($f), true) ?: array()) : array(); }
function rfmt($n) {
	$u = array('B', 'KB', 'MB', 'GB', 'TB'); $i = 0;
	while ($n >= 1024 && $i < 4) { $n /= 1024; $i++; }
	return ($n >= 100 || $i == 0 ? round($n) : sprintf('%.1f', $n)) . ' ' . $u[$i];
}

/* Aggregate a set of rollup days into devices keyed by MAC (else IP). Returns
 * [devices => [key => [in,out,ip,mac]], iface => [in,out], perday => [date=>iface_total]]. */
function report_aggregate($roll, $dates) {
	/* External DB (full history, beyond the 400-day rollup cap) when enabled. */
	if (function_exists('bwd_use_db') && bwd_use_db() && $dates) {
		$from = $dates[0]; $to = end($dates);
		$dev = bwd_db_window_devices($from, $to);
		$iface = array('in' => 0, 'out' => 0); $perday = array();
		foreach (bwd_db_window_iface_perday($from, $to) as $d => $io) {
			$iface['in'] += $io['in']; $iface['out'] += $io['out'];
			$perday[$d] = $io['in'] + $io['out'];
		}
		return array('devices' => $dev, 'iface' => $iface, 'perday' => $perday);
	}
	$dev = array(); $iface = array('in' => 0, 'out' => 0); $perday = array();
	foreach ($dates as $d) {
		if (!isset($roll[$d])) { continue; }
		$dayIfaceIn = 0; $dayIfaceOut = 0;
		foreach ($roll[$d] as $ip => $v) {
			if ($ip === '0.0.0.0') {
				$iface['in'] += $v['in']; $iface['out'] += $v['out'];
				$dayIfaceIn = $v['in']; $dayIfaceOut = $v['out'];
				continue;
			}
			$mac = $v['mac'] ?? '';
			$key = $mac ? "mac:$mac" : "ip:$ip";
			if (!isset($dev[$key])) { $dev[$key] = array('in' => 0, 'out' => 0, 'ip' => $ip, 'mac' => $mac, 'maxday' => 0); }
			$dev[$key]['in'] += $v['in']; $dev[$key]['out'] += $v['out'];
			$dev[$key]['ip'] = $ip;
			$dayTot = $v['in'] + $v['out'];
			if ($dayTot > $dev[$key]['maxday']) { $dev[$key]['maxday'] = $dayTot; }
		}
		$perday[$d] = $dayIfaceIn + $dayIfaceOut;
	}
	return array('devices' => $dev, 'iface' => $iface, 'perday' => $perday);
}

function dev_name($dev, $names) {
	$ip = $dev['ip']; $n = bwd_name($ip, $dev['mac'] ?? '', $names[$ip] ?? '');
	$s = $n ? "$n ($ip)" : $ip;
	if (!empty($dev['mac'])) { $s .= " [{$dev['mac']}]"; }
	return $s;
}

/* Make an alert_state key human-readable for the report. Keys look like
 * "type:<device-key>[:YYYY-MM-DD]" where device-key is "mac:aa:bb:.." (contains
 * colons) or "ip:1.2.3.4"; quota_iface/newdev variants may omit parts. */
function alert_human($key, $names, $macName) {
	$pos = strpos($key, ':');
	$type = ($pos === false) ? $key : substr($key, 0, $pos);
	$rest = ($pos === false) ? '' : substr($key, $pos + 1);
	$rest = preg_replace('/:?\d{4}-\d{2}-\d{2}$/', '', $rest);   // strip trailing date
	$labels = array('quota_iface' => 'Interface quota', 'quota_dev' => 'Device quota',
		'anomaly' => 'Anomaly', 'exfil' => 'Exfiltration', 'newdev' => 'New device');
	$label = $labels[$type] ?? $type;
	$who = '';
	if (strncmp($rest, 'mac:', 4) === 0) {
		$mac = substr($rest, 4);
		$who = bwd_name('', $mac, $macName[$mac] ?? $mac);
	} elseif (strncmp($rest, 'ip:', 3) === 0) {
		$ip = substr($rest, 3);
		$who = bwd_name($ip, '', $names[$ip] ?? $ip);
	}
	return trim("$label" . ($who ? " — $who" : ''));
}

/* ---- Resolve the reporting window ---- */
$freq = ($winOverride === 'week') ? 'weekly' : (($winOverride === 'today' || $winOverride === 'yesterday') ? 'daily' : rcfg('report_freq', 'daily'));
$topn = (int) rcfg('report_topn', 10); if ($topn < 1) { $topn = 10; }

if ($winOverride === 'today') {
	$dates = array(date('Y-m-d'));
	$title = 'BandwidthD daily report — ' . $dates[0];
} elseif ($freq === 'weekly') {
	$dates = array();
	for ($i = 7; $i >= 1; $i--) { $dates[] = date('Y-m-d', strtotime("-$i day")); }
	$title = 'BandwidthD weekly report — ' . $dates[0] . ' to ' . end($dates);
} else {
	$dates = array(date('Y-m-d', strtotime('yesterday')));
	$title = 'BandwidthD daily report — ' . $dates[0];
}
$winStart = strtotime($dates[0] . ' 00:00:00');
$winEnd   = strtotime(end($dates) . ' 23:59:59');

$roll  = rload(BWD_ROLLUP);
$names = bwd_hostmap();
$macName = array();                 // mac -> friendly name (for alert labels)
foreach (bwd_macmap() as $ip => $mac) { if (!empty($names[$ip])) { $macName[$mac] = $names[$ip]; } }
$agg   = report_aggregate($roll, $dates);

$devs = $agg['devices'];
uasort($devs, function($a, $b) { return ($b['in'] + $b['out']) <=> ($a['in'] + $a['out']); });
$top = array_slice($devs, 0, $topn, true);

$ifaceTotal = $agg['iface']['in'] + $agg['iface']['out'];

/* Quota status. */
$qi = (float) rcfg('quota_iface_gb', 0);
$qExceeded = array();
if ($qi > 0) {
	foreach ($agg['perday'] as $d => $tot) {
		if ($tot / GB >= $qi) { $qExceeded[$d] = $tot; }
	}
}
$qh = (float) rcfg('quota_host_gb', 0);

/* Alerts that fired during the window. */
$state = rload(BWD_ALERT_STATE);
$winAlerts = array();
foreach ($state as $k => $ts) {
	if ($ts >= $winStart && $ts <= $winEnd) {
		$winAlerts[] = array('t' => $ts, 'text' => alert_human($k, $names, $macName));
	}
}
usort($winAlerts, function($a, $b) { return $b['t'] <=> $a['t']; });

/* ---- Render plain text ---- */
$days = count($dates);
$T  = "$title\n" . str_repeat('=', strlen($title)) . "\n\n";
$T .= sprintf("Window: %s (%d day%s)\n", $dates[0] . ($days > 1 ? ' .. ' . end($dates) : ''), $days, $days == 1 ? '' : 's');
$T .= sprintf("Interface total: %s  (down %s / up %s)\n\n",
	rfmt($ifaceTotal), rfmt($agg['iface']['in']), rfmt($agg['iface']['out']));

$T .= "Top devices by total\n--------------------\n";
$rank = 0;
foreach ($top as $key => $d) {
	$rank++;
	$T .= sprintf("%2d. %-44s  %s (down %s / up %s)\n", $rank,
		dev_name($d, $names), rfmt($d['in'] + $d['out']), rfmt($d['in']), rfmt($d['out']));
}
if (!$top) { $T .= "(no device data for this window)\n"; }

$T .= "\nQuota status\n------------\n";
if ($qi > 0) {
	$T .= sprintf("Interface daily quota %g GB: exceeded on %d of %d day(s).\n", $qi, count($qExceeded), $days);
	foreach ($qExceeded as $d => $tot) { $T .= sprintf("  - %s: %s\n", $d, rfmt($tot)); }
} else {
	$T .= "Interface daily quota: not set.\n";
}
if ($qh > 0) {
	$over = array();
	foreach ($devs as $key => $d) { if ($d['maxday'] / GB >= $qh) { $over[$key] = $d; } }
	$T .= sprintf("Per-device daily quota %g GB: %d device(s) exceeded it on some day.\n", $qh, count($over));
	foreach (array_slice($over, 0, 10, true) as $d) {
		$T .= sprintf("  - %s (peak day %s)\n", dev_name($d, $names), rfmt($d['maxday']));
	}
}

$T .= "\nAlerts fired in this window\n---------------------------\n";
if ($winAlerts) {
	foreach ($winAlerts as $a) { $T .= sprintf("  %s — %s\n", date('Y-m-d H:i', $a['t']), $a['text']); }
} else {
	$T .= "(none)\n";
}
$T .= "\n-- OPNsense BandwidthD\n";

/* ---- Render HTML ---- */
$h = function($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
$H  = '<div style="font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#1f2733;max-width:720px">';
$H .= '<h2 style="margin:0 0 4px">' . $h($title) . '</h2>';
$H .= '<p style="color:#8a93a3;margin:0 0 14px">Window: ' . $h($dates[0] . ($days > 1 ? ' – ' . end($dates) : '')) .
	' &middot; ' . $days . ' day' . ($days == 1 ? '' : 's') . '</p>';
$H .= '<table style="border-collapse:collapse;margin-bottom:16px"><tr>' .
	'<td style="padding:8px 16px;background:#f7f8fa;border:1px solid #e5e8ee;border-radius:8px">' .
		'<div style="font-size:11px;text-transform:uppercase;color:#8a93a3;font-weight:700">Interface total</div>' .
		'<div style="font-size:20px;font-weight:700">' . $h(rfmt($ifaceTotal)) . '</div>' .
		'<div style="font-size:12px;color:#8a93a3">▼ ' . $h(rfmt($agg['iface']['in'])) . ' &middot; ▲ ' . $h(rfmt($agg['iface']['out'])) . '</div>' .
	'</td></tr></table>';

$H .= '<h3 style="margin:0 0 6px">Top devices</h3>';
$H .= '<table style="border-collapse:collapse;width:100%;font-size:13px">';
$H .= '<tr style="text-align:left;color:#8a93a3"><th style="padding:4px 8px">#</th><th style="padding:4px 8px">Device</th>' .
	'<th style="padding:4px 8px;text-align:right">Down</th><th style="padding:4px 8px;text-align:right">Up</th>' .
	'<th style="padding:4px 8px;text-align:right">Total</th></tr>';
$rank = 0;
foreach ($top as $d) {
	$rank++;
	$H .= '<tr style="border-top:1px solid #eef1f5">' .
		'<td style="padding:4px 8px;color:#8a93a3">' . $rank . '</td>' .
		'<td style="padding:4px 8px">' . $h(dev_name($d, $names)) . '</td>' .
		'<td style="padding:4px 8px;text-align:right">' . $h(rfmt($d['in'])) . '</td>' .
		'<td style="padding:4px 8px;text-align:right">' . $h(rfmt($d['out'])) . '</td>' .
		'<td style="padding:4px 8px;text-align:right;font-weight:700">' . $h(rfmt($d['in'] + $d['out'])) . '</td></tr>';
}
$H .= '</table>';

$H .= '<h3 style="margin:16px 0 6px">Quota status</h3><ul style="margin:0;padding-left:18px">';
if ($qi > 0) {
	$H .= '<li>Interface daily quota <b>' . $h($qi) . ' GB</b>: exceeded on ' . count($qExceeded) . ' of ' . $days . ' day(s).</li>';
} else {
	$H .= '<li>Interface daily quota: not set.</li>';
}
if ($qh > 0) {
	$over = 0; foreach ($devs as $d) { if ($d['maxday'] / GB >= $qh) { $over++; } }
	$H .= '<li>Per-device daily quota <b>' . $h($qh) . ' GB</b>: ' . $over . ' device(s) exceeded it on some day.</li>';
}
$H .= '</ul>';

$H .= '<h3 style="margin:16px 0 6px">Alerts fired</h3>';
if ($winAlerts) {
	$H .= '<ul style="margin:0;padding-left:18px">';
	foreach ($winAlerts as $a) { $H .= '<li>' . $h(date('Y-m-d H:i', $a['t'])) . ' — ' . $h($a['text']) . '</li>'; }
	$H .= '</ul>';
} else {
	$H .= '<p style="color:#8a93a3;margin:0">None.</p>';
}
$H .= '<p style="color:#8a93a3;font-size:11px;margin-top:18px">OPNsense BandwidthD · ' .
	'<a href="/ui/bandwidthd/dashboard">open dashboard</a></p></div>';

/* ---- Deliver ---- */
$recipients = trim(rcfg('report_recipients', ''));
/* No system-wide notification address exists on OPNsense, so the report needs
   explicit recipients; without them it falls back to the system log. */

if ($DRY) {
	echo "DRY RUN — not sending. Recipients: " . ($recipients ?: '(none configured)') . "\n";
	echo "Subject: $title\n\n";
	echo $T;
	return;
}

$status = bwd_send_report_mail($title, $T, $H, $recipients);
echo ($status === true) ? "Report sent to: $recipients\n" : "Report not emailed: $status\n";

/* Deliver the report by mail, falling back to the system log so a report is never
 * silently dropped. OPNsense has no system-wide notification SMTP settings, so
 * bwd_send_mail() borrows Monit's mail server (see bwd_smtp_config). */
function bwd_send_report_mail($subject, $text, $html, $recipients) {
	if (trim((string) $recipients) === '' || bwd_smtp_config() === null) {
		bwd_notify($subject, $text, LOG_NOTICE);
		return bwd_smtp_config() === null
			? 'no SMTP server configured (logged to syslog instead)'
			: 'no recipients configured (logged to syslog instead)';
	}
	return bwd_send_mail($subject, $text, $html, $recipients);
}
