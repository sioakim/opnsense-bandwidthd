#!/usr/local/bin/php
<?php
/*
 * bandwidthd_probe.php — CLI driver for opt-in active device fingerprinting (#11).
 *
 * Probes a device (or all ARP-known hosts) with HTTP endpoint checks + nmap (if
 * installed), matches the deterministic fingerprint library, caches the result
 * (rollups/probes.json, MAC-keyed), and prints it. The cached fingerprint feeds
 * bwd_classify() as a high-weight `probe` signal.
 *
 * Active probing is opt-in (behavior change vs passive monitoring). Running this
 * CLI is itself an explicit operator action; it warns if probe_enable is off but
 * proceeds (use the GUI button for the gated path).
 *
 * Usage:
 *   php bandwidthd_probe.php -- --ip 192.0.2.10 [--json] [--no-nmap]
 *   php bandwidthd_probe.php -- --all          [--json] [--no-nmap]
 *
 * Licensed under the Apache License, Version 2.0.
 */

require_once(__DIR__ . '/lib/bwd_platform.inc.php');
require_once(__DIR__ . '/lib/bwd_data.inc.php');
require_once(__DIR__ . '/lib/bwd_fingerprint.inc.php');

$opts = array();
foreach ($argv as $a) {
	if (strncmp($a, '--ip=', 5) === 0) { $opts['ip'] = substr($a, 5); }
	elseif ($a === '--ip') { $opts['ip_next'] = true; }
	elseif (!empty($opts['ip_next'])) { $opts['ip'] = $a; unset($opts['ip_next']); }
	elseif ($a === '--all') { $opts['all'] = true; }
	elseif ($a === '--json') { $opts['json'] = true; }
	elseif ($a === '--no-nmap') { $opts['nonmap'] = true; }
	elseif ($a === '--dhcp') { $opts['dhcp'] = true; }
	elseif ($a === '--fast') { $opts['fast'] = true; }
	elseif ($a === '--cron') { $opts['cron'] = true; }
	elseif (strncmp($a, '--iface=', 8) === 0) { $opts['iface'] = substr($a, 8); }
	elseif (strncmp($a, '--secs=', 7) === 0) { $opts['secs'] = (int) substr($a, 7); }
	elseif (strncmp($a, '--max=', 6) === 0) { $opts['max'] = (int) substr($a, 6); }
	elseif (strncmp($a, '--stale-hours=', 14) === 0) { $opts['stale'] = (int) substr($a, 14); }
}
$useNmap = empty($opts['nonmap']);

$macmap = bwd_macmap();
$names  = bwd_hostmap();

/* ---- cron enrichment pass: incremental, fast (HTTP+TLS), opt-in ---------------
 * Probe devices whose cached fingerprint is missing or stale (> stale-hours),
 * up to --max per run, so each scheduled run is cheap and the fleet is covered
 * over a few runs then kept fresh. Requires probe_enable (active probing). */
if (!empty($opts['cron'])) {
	if (!bwd_fp_enabled()) { echo "active probing disabled (probe_enable) — cron pass skipped.\n"; exit(0); }

	/* Own the pass exclusively. A cron flock around the invocation only guards that
	   one entry point; holding the lock here also covers a manual run overlapping a
	   scheduled one. Non-blocking: a second pass simply skips. */
	$lockFile = BWD_BASE . '/rollups/probe.lock';
	@mkdir(dirname($lockFile), 0755, true);
	$lockFh = @fopen($lockFile, 'c');
	if ($lockFh === false || !@flock($lockFh, LOCK_EX | LOCK_NB)) {
		echo "another probe pass is already running — skipped.\n";
		exit(0);
	}

	/* Auto-off: probing fingerprints the fleet then turns itself back off after
	 * probe_auto_off_hours (default 24) so the LAN isn't rescanned forever. */
	// Distinguish an EMPTY field (use the 24h default) from an explicit "0" (stay
	// on forever). (int)'' is 0, which would silently disable auto-off — so only an
	// explicitly-entered 0 means "never auto-off".
	$autoOffRaw = bwd_cfg('probe_auto_off_hours', '');
	$autoOff = ($autoOffRaw === '' || $autoOffRaw === null) ? 24 : (int) $autoOffRaw;
	$stateFile = BWD_BASE . '/rollups/probe_state.json';
	$st = is_file($stateFile) ? (json_decode(@file_get_contents($stateFile), true) ?: array()) : array();
	if (empty($st['enabled_at'])) { $st['enabled_at'] = time(); bwd_atomic_write($stateFile, bwd_json($st)); }
	if ($autoOff > 0 && (time() - (int) $st['enabled_at']) >= $autoOff * 3600) {
		/* Turning the setting off is enough to remove the cron job: the schedule is
		   derived from the settings by bandwidthd_cron(), so we just regenerate it. */
		bwd_cfg_set('probe_enable', 'off', "BandwidthD: auto-disabled active probing after {$autoOff}h");
		bwd_reload_cron();
		@unlink($stateFile);
		bwd_notify("BandwidthD", "Active device probing auto-disabled after {$autoOff}h (fleet fingerprinted). Re-enable under Settings to refresh.", LOG_NOTICE);
		echo "auto-off: active probing disabled after {$autoOff}h.\n";
		exit(0);
	}

	$stale = ($opts['stale'] ?? 24) * 3600;
	$max   = $opts['max'] ?? 50;
	$done = 0; $ident = 0; $freshSkipped = 0; $reachedMax = false;
	foreach (array_keys($macmap) as $ip) {
		if ($done >= $max) { $reachedMax = true; break; }
		if (!bwd_fp_target_allowed($ip)) { continue; }   // monitored LAN scope only (#32)
		$mac = $macmap[$ip] ?? '';
		if (bwd_fp_fresh($mac, $ip, $stale)) { $freshSkipped++; continue; }   // skip recently-probed
		$res = bwd_fp_identify_device($ip, array('fast' => true));
		bwd_fp_store($mac, $ip, $res);
		$done++;
		if ($res['vendor'] !== '' || $res['model'] !== '') { $ident++; }
	}
	printf("cron probe: %d probed (fast), %d identified; %d skipped (fresh <%dh)%s.\n",
		$done, $ident, $freshSkipped, $opts['stale'] ?? 24,
		$reachedMax ? "; hit --max=$max, more remain for the next run" : "; fleet covered");
	exit(0);
}

if (!bwd_fp_enabled()) {
	fwrite(STDERR, "note: active probing (probe_enable) is OFF in settings; running anyway (explicit CLI invocation).\n");
}

/* ---- DHCP fingerprint harvest mode: sniff option 55/60 from live DHCP ---- */
if (!empty($opts['dhcp'])) {
	$secs = $opts['secs'] ?? 60;
	fwrite(STDERR, "sniffing DHCP (opt 55/60) for {$secs}s — devices must renew/request a lease in this window…\n");
	$harvest = bwd_fp_dhcp_harvest($opts['iface'] ?? '', $secs);
	$ip2mac = array(); foreach ($macmap as $i => $m) { $ip2mac[$m] = $i; }
	foreach ($harvest as $mac => $d) {
		$m = bwd_fp_match_dhcp($d);
		if ($m) { bwd_fp_store($mac, $ip2mac[$mac] ?? '', $m + array('observations' => array('dhcp' => $d))); }
		printf("%-18s opt60=%-24s opt55=%-28s -> %s\n", $mac, substr($d['opt60'], 0, 24) ?: '-', $d['opt55'] ?: '-',
			$m ? ($m['vendor'] ? $m['vendor'] . ' ' : '') . ($m['os'] ?: '') . " tag=" . $m['tag'] . " (" . $m['confidence'] . ")" : '(no match)');
	}
	if (!$harvest) { echo "no DHCP traffic captured in the window.\n"; }
	exit(0);
}
$targets = array();
if (!empty($opts['ip'])) {
	if (!is_ipaddrv4($opts['ip'])) { fwrite(STDERR, "invalid --ip\n"); exit(2); }
	if (!bwd_fp_target_allowed($opts['ip'])) { fwrite(STDERR, "--ip is outside the monitored subnets (RFC1918 / configured stats subnets); refusing to probe (#32)\n"); exit(2); }
	$targets[] = $opts['ip'];
} elseif (!empty($opts['all'])) {
	// ARP-derived, but filter to the monitored scope defensively (a WAN gateway
	// can appear in ARP and must not be probed).
	$targets = array_values(array_filter(array_keys($macmap), 'bwd_fp_target_allowed'));
} else {
	fwrite(STDERR, "usage: php bandwidthd_probe.php -- --ip <addr> | --all | --cron | --dhcp\n  [--fast] [--json] [--no-nmap] [--max=N] [--stale-hours=H] [--iface=em0] [--secs=60]\n");
	exit(2);
}

$results = array();
foreach ($targets as $ip) {
	$res = bwd_fp_identify_device($ip, array('nmap' => $useNmap, 'fast' => !empty($opts['fast'])));
	$mac = $macmap[$ip] ?? '';
	$stored = bwd_fp_store($mac, $ip, $res);
	$results[$ip] = $stored;
	if (empty($opts['json'])) {
		printf("%-15s %-18s vendor=%-12s model=%-18s os=%-12s tag=%-8s conf=%.2f via=%s\n",
			$ip, $mac ?: '(no mac)', $res['vendor'] ?: '-', $res['model'] ?: '-', $res['os'] ?: '-',
			$res['tag'] ?: '-', $res['confidence'], $res['via'] ?: '-');
		$o = $res['observations'];
		if ($o['server'])    { echo "    http:     " . implode(' | ', array_slice($o['server'], 0, 3)) . (($o['titles']) ? '  [' . implode(' | ', array_slice($o['titles'], 0, 2)) . ']' : '') . "\n"; }
		if ($o['endpoints']) { echo "    endpoints:" . implode(', ', $o['endpoints']) . "\n"; }
		if ($o['mdns'])      { echo "    mdns:     " . implode(' ', array_slice($o['mdns'], 0, 12)) . "\n"; }
		if ($o['ssdp'])      { echo "    ssdp:     " . substr(implode(' | ', $o['ssdp']), 0, 160) . "\n"; }
		if ($o['banners'])   { echo "    banners:  " . implode(' | ', array_slice($o['banners'], 0, 4)) . "\n"; }
		if ($o['tls'])       { echo "    tls:      " . substr(implode(' | ', $o['tls']), 0, 160) . "\n"; }
		if ($o['nmap'])      { echo "    nmap:     " . implode(' ; ', array_slice($o['nmap'], 0, 6)) . "\n"; }
	}
}
if (!empty($opts['json'])) { echo bwd_json($results, JSON_PRETTY_PRINT) . "\n"; }
