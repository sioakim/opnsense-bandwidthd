<?php
/*
 * bwd_db.inc.php — optional external PostgreSQL backend for durable history.
 *
 * The firewall is a thin exporter: bandwidthd_dbexport.php pushes CDF samples
 * here; the data layer reads history from here and UNIONs it with the live CDF
 * tail (see bwd_data.inc.php). All access is via the php83-pgsql extension
 * (pg_*), NOT PDO. NOTE: OPNsense's package repo ships NO pgsql PHP extension at
 * all, so this backend stays inert unless one is installed out-of-band; every
 * entry point is gated on bwd_db_available(). See docs/POSTGRES.md.
 *
 * Everything degrades gracefully: if the DB is disabled, the extension is
 * missing, or the server is unreachable, bwd_db() returns null and callers fall
 * back to CDF-only behavior. No output here; pure functions.
 *
 * Tables are prefixed `bwd_` so the database may be shared.
 *
 * Licensed under the Apache License, Version 2.0.
 */

if (!defined('BWD_DB_PREFIX')) { define('BWD_DB_PREFIX', 'bwd_'); }

function bwd_db_cfg($k, $def = '') {
	$v = bwd_cfg($k, null);
	return ($v === null || $v === '') ? $def : $v;
}
/* True when the pg_* extension is actually loadable on this box. OPNsense does
 * not ship one, so this is the hard gate that keeps the rest of the plugin
 * working (CDF-only) instead of fataling on an undefined pg_connect(). */
function bwd_db_available() {
	return function_exists('pg_connect');
}
function bwd_db_enabled() {
	return bwd_db_cfg('db_enable') === 'on' && bwd_db_available();
}

/* Quote a value for a libpq connection string (wrap in single quotes, escape
 * backslash and single-quote). */
function bwd_db_q($v) {
	return "'" . str_replace(array('\\', "'"), array('\\\\', "\\'"), (string) $v) . "'";
}

/* Lazily open (and cache) the PG connection. Returns a pg connection resource,
 * or null if disabled / extension missing / unreachable. Tries once per request. */
function bwd_db($reset = false) {
	static $conn = null; static $tried = false;
	if ($reset) { $conn = null; $tried = false; }
	if ($tried) { return $conn; }
	$tried = true;

	if (!bwd_db_enabled() || !function_exists('pg_connect')) { return $conn = null; }

	$pass = (string) bwd_db_cfg('db_pass');
	$parts = array(
		'host=' . bwd_db_q(bwd_db_cfg('db_host')),
		'port=' . bwd_db_q(bwd_db_cfg('db_port', '5432')),
		'dbname=' . bwd_db_q(bwd_db_cfg('db_name')),
		'user=' . bwd_db_q(bwd_db_cfg('db_user')),
		'password=' . bwd_db_q($pass),
		'sslmode=' . bwd_db_q(bwd_db_cfg('db_sslmode', 'require')),
		'connect_timeout=5',
		'application_name=' . bwd_db_q('opnsense-bandwidthd'),
	);
	$c = @pg_connect(implode(' ', $parts), PGSQL_CONNECT_FORCE_NEW);
	if ($c === false || pg_connection_status($c) !== PGSQL_CONNECTION_OK) {
		return $conn = null;
	}
	/* Align the session timezone to the firewall's so that day-bucketing of the
	 * unix-epoch `ts` column (date_trunc('day', to_timestamp(ts))) lands on the
	 * same local calendar day the JSON rollup uses — the server default (e.g.
	 * Europe/Berlin) would otherwise shift bucket boundaries and break parity. */
	$tz = @date_default_timezone_get();
	if ($tz) { @pg_query($c, 'SET TIME ZONE ' . pg_escape_literal($c, $tz)); }
	return $conn = $c;
}

/* Render a PHP string list as a Postgres array literal, for passing a set to a
 * `col = ANY($n::text[])` parameter (elements quoted, \ and " escaped). */
function bwd_db_text_array($list) {
	$q = array_map(function ($v) {
		return '"' . str_replace(array('\\', '"'), array('\\\\', '\\"'), (string) $v) . '"';
	}, $list);
	return '{' . implode(',', $q) . '}';
}

/* Parameterized query. Returns the pg result, or false on error (logged). */
function bwd_db_exec($sql, $params = array()) {
	$c = bwd_db();
	if (!$c) { return false; }
	$r = @pg_query_params($c, $sql, $params);
	if ($r === false) { @error_log('bwd_db: ' . pg_last_error($c)); }
	return $r;
}

/* Create the schema if absent (idempotent). Returns true on success. */
function bwd_db_ensure_schema() {
	$c = bwd_db();
	if (!$c) { return false; }
	$p = BWD_DB_PREFIX;
	$stmts = array(
		"CREATE TABLE IF NOT EXISTS {$p}meta (k text PRIMARY KEY, v text)",
		"CREATE TABLE IF NOT EXISTS {$p}device (
			mac text PRIMARY KEY, last_ip text, name text, vendor text, tag text,
			first_seen bigint, last_seen bigint)",
		"CREATE TABLE IF NOT EXISTS {$p}usage (
			ts bigint NOT NULL, mac text NOT NULL, ip text,
			in_bytes bigint NOT NULL DEFAULT 0, out_bytes bigint NOT NULL DEFAULT 0,
			tcp bigint, udp bigint, http bigint, p2p bigint, icmp bigint, ftp bigint,
			PRIMARY KEY (ts, mac))",
		"CREATE INDEX IF NOT EXISTS {$p}usage_mac_ts ON {$p}usage (mac, ts)",
		"CREATE INDEX IF NOT EXISTS {$p}usage_ts ON {$p}usage (ts)",
		"CREATE TABLE IF NOT EXISTS {$p}iface (
			ts bigint PRIMARY KEY, in_bytes bigint NOT NULL DEFAULT 0, out_bytes bigint NOT NULL DEFAULT 0)",
		"CREATE TABLE IF NOT EXISTS {$p}daily (
			day date NOT NULL, mac text NOT NULL, ip text,
			in_bytes bigint NOT NULL DEFAULT 0, out_bytes bigint NOT NULL DEFAULT 0,
			PRIMARY KEY (day, mac))",
	);
	foreach ($stmts as $s) {
		if (@pg_query($c, $s) === false) { @error_log('bwd_db schema: ' . pg_last_error($c)); return false; }
	}
	if (bwd_db_meta_get('schema_version') === null) { bwd_db_meta_set('schema_version', '1'); }
	return true;
}

function bwd_db_meta_get($k, $def = null) {
	$r = bwd_db_exec('SELECT v FROM ' . BWD_DB_PREFIX . 'meta WHERE k = $1', array($k));
	if ($r && pg_num_rows($r) > 0) { $row = pg_fetch_row($r); return $row[0]; }
	return $def;
}
function bwd_db_meta_set($k, $v) {
	return bwd_db_exec('INSERT INTO ' . BWD_DB_PREFIX . 'meta (k, v) VALUES ($1, $2)
		ON CONFLICT (k) DO UPDATE SET v = EXCLUDED.v', array($k, (string) $v)) !== false;
}

/* The export watermark: max sample ts durably written to the DB. The data layer
 * reads DB rows with ts <= watermark and the live CDF tail with ts > watermark. */
function bwd_db_watermark() {
	return (int) bwd_db_meta_get('usage_watermark', 0);
}

/* ---- shared read helpers (Phase 3a, #15) ---------------------------------
 * Consumers that historically read rollups/daily.json (alerts baseline,
 * scheduled report, device registry) read these when the DB is enabled, and
 * fall back to the rollup otherwise. All of them respect ONE invariant so the
 * coarse daily tier and the fine usage tier never both count the same day:
 *   the "fine day start" seam = the local calendar day of min(usage.ts).
 *   days  < seam  are owned by bwd_daily (downsampled / seeded);
 *   days >= seam  are owned by bwd_usage (bucketed to local days).
 * Interface daily totals live in bwd_daily under the sentinel mac '0.0.0.0'. */

/* Reconstruct a device key ("mac:.." / "ip:..") from the value stored in the
 * usage/daily `mac` column (a real MAC, or the IP when no MAC was known) —
 * matching the keys the alerts engine and registry build. */
function bwd_db_devkey($v) {
	$v = (string) $v;
	return preg_match('/^[0-9a-f]{2}(:[0-9a-f]{2}){5}$/i', $v) ? 'mac:' . strtolower($v) : "ip:$v";
}

/* Local-midnight epoch of the earliest fine usage day (the daily/fine seam).
 * PHP_INT_MAX when there is no fine data (everything then comes from daily). */
function bwd_db_fine_day_start() {
	static $v = null;
	if ($v !== null) { return $v; }
	$r = bwd_db_exec('SELECT coalesce(min(ts),0) m FROM ' . BWD_DB_PREFIX . 'usage');
	$min = ($r && ($x = pg_fetch_row($r))) ? (int) $x[0] : 0;
	$v = $min ? strtotime(date('Y-m-d', $min) . ' 00:00:00') : PHP_INT_MAX;
	return $v;
}

/* Per-device aggregates over a [fromDay,toDay] window (inclusive, 'Y-m-d'),
 * merging the daily tier (days < seam) and the fine tier (days >= seam) with no
 * double-counting. Returns key => [in,out,ip,mac,maxday] like report_aggregate,
 * excluding the interface sentinel row. */
function bwd_db_window_devices($fromDay, $toDay) {
	$p = BWD_DB_PREFIX;
	$seam = bwd_db_fine_day_start();
	$seamDay = ($seam === PHP_INT_MAX) ? null : date('Y-m-d', $seam);
	$dev = array();
	$fold = function($mac, $ip, $in, $out) use (&$dev) {
		if ($mac === '0.0.0.0') { return; }
		$key = bwd_db_devkey($mac);
		if (!isset($dev[$key])) { $dev[$key] = array('in' => 0, 'out' => 0, 'ip' => $ip, 'mac' => (strpos($key, 'mac:') === 0 ? substr($key, 4) : ''), 'maxday' => 0, 'key' => $key); }
		$dev[$key]['in'] += $in; $dev[$key]['out'] += $out;
		if ($ip) { $dev[$key]['ip'] = $ip; }
		$day = $in + $out;
		if ($day > $dev[$key]['maxday']) { $dev[$key]['maxday'] = $day; }
	};
	// daily tier: days strictly before the seam
	$dailyTo = $seamDay ? date('Y-m-d', strtotime("$seamDay -1 day")) : $toDay;
	if (strcmp($fromDay, $dailyTo) <= 0) {
		$hi = (strcmp($dailyTo, $toDay) < 0) ? $dailyTo : $toDay;
		$r = bwd_db_exec("SELECT day::text, mac, ip, in_bytes, out_bytes FROM {$p}daily
			WHERE mac <> '0.0.0.0' AND day >= $1 AND day <= $2", array($fromDay, $hi));
		if ($r) { while ($x = pg_fetch_row($r)) { $fold($x[1], $x[2], (float)$x[3], (float)$x[4]); } }
	}
	// fine tier: days from max(fromDay,seam) .. toDay, bucketed to local days
	if ($seamDay) {
		$fineFrom = (strcmp($fromDay, $seamDay) > 0) ? $fromDay : $seamDay;
		if (strcmp($fineFrom, $toDay) <= 0) {
			$r = bwd_db_exec("SELECT mac, max(ip) ip, date_trunc('day',to_timestamp(ts))::date d,
				sum(in_bytes) i, sum(out_bytes) o FROM {$p}usage
				WHERE mac <> '0.0.0.0' AND ts >= $1 AND ts <= $2 GROUP BY mac, d",
				array(strtotime("$fineFrom 00:00:00"), strtotime("$toDay 23:59:59")));
			if ($r) { while ($x = pg_fetch_assoc($r)) { $fold($x['mac'], $x['ip'], (float)$x['i'], (float)$x['o']); } }
		}
	}
	return $dev;
}

/* Per-day interface totals over [fromDay,toDay] -> ['Y-m-d' => [in,out]].
 * Daily tier from the bwd_daily sentinel row; fine tier from bwd_iface. */
function bwd_db_window_iface_perday($fromDay, $toDay) {
	$p = BWD_DB_PREFIX;
	$seam = bwd_db_fine_day_start();
	$seamDay = ($seam === PHP_INT_MAX) ? null : date('Y-m-d', $seam);
	$perday = array();
	$dailyTo = $seamDay ? date('Y-m-d', strtotime("$seamDay -1 day")) : $toDay;
	if (strcmp($fromDay, $dailyTo) <= 0) {
		$hi = (strcmp($dailyTo, $toDay) < 0) ? $dailyTo : $toDay;
		$r = bwd_db_exec("SELECT day::text, in_bytes, out_bytes FROM {$p}daily
			WHERE mac = '0.0.0.0' AND day >= $1 AND day <= $2", array($fromDay, $hi));
		if ($r) { while ($x = pg_fetch_row($r)) { $perday[$x[0]] = array('in' => (float)$x[1], 'out' => (float)$x[2]); } }
	}
	if ($seamDay) {
		$fineFrom = (strcmp($fromDay, $seamDay) > 0) ? $fromDay : $seamDay;
		if (strcmp($fineFrom, $toDay) <= 0) {
			$r = bwd_db_exec("SELECT date_trunc('day',to_timestamp(ts))::date::text d,
				sum(in_bytes) i, sum(out_bytes) o FROM {$p}iface WHERE ts >= $1 AND ts <= $2 GROUP BY d",
				array(strtotime("$fineFrom 00:00:00"), strtotime("$toDay 23:59:59")));
			if ($r) { while ($x = pg_fetch_assoc($r)) { $perday[$x['d']] = array('in' => (float)$x['i'], 'out' => (float)$x['o']); } }
		}
	}
	return $perday;
}

/* Full per-(device,day) history -> mac => ['Y-m-d' => [in,out]] across all time,
 * merging the daily tier (days < seam) and fine tier (days >= seam). Excludes
 * the interface sentinel. Used to build the device registry (#15). */
function bwd_db_all_perday() {
	$p = BWD_DB_PREFIX;
	$seam = bwd_db_fine_day_start();
	$seamDay = ($seam === PHP_INT_MAX) ? null : date('Y-m-d', $seam);
	$out = array();
	$add = function($mac, $day, $in, $out_b) use (&$out) {
		if (!isset($out[$mac])) { $out[$mac] = array(); }
		$out[$mac][$day] = array('in' => $in, 'out' => $out_b);
	};
	$dailyTo = $seamDay ? date('Y-m-d', strtotime("$seamDay -1 day")) : null;
	$sql = "SELECT mac, day::text, in_bytes, out_bytes FROM {$p}daily WHERE mac <> '0.0.0.0'";
	$r = $dailyTo ? bwd_db_exec($sql . ' AND day <= $1', array($dailyTo)) : bwd_db_exec($sql);
	if ($r) { while ($x = pg_fetch_row($r)) { $add($x[0], $x[1], (float)$x[2], (float)$x[3]); } }
	if ($seamDay) {
		$r = bwd_db_exec("SELECT mac, date_trunc('day',to_timestamp(ts))::date::text d,
			sum(in_bytes) i, sum(out_bytes) o FROM {$p}usage
			WHERE mac <> '0.0.0.0' AND ts >= $1 GROUP BY mac, d", array($seam));
		if ($r) { while ($x = pg_fetch_assoc($r)) { $add($x['mac'], $x['d'], (float)$x['i'], (float)$x['o']); } }
	}
	return $out;
}

/* Trailing per-day totals (in+out) for a single device key over the last $days
 * days excluding today -> list of daily totals (for the alerts baseline). */
function bwd_db_device_baseline($key, $days = 7) {
	$today = date('Y-m-d');
	$from = date('Y-m-d', strtotime("-$days day"));
	$id = (strpos($key, 'mac:') === 0) ? substr($key, 4) : ((strpos($key, 'ip:') === 0) ? substr($key, 3) : $key);
	$p = BWD_DB_PREFIX;
	$seam = bwd_db_fine_day_start();
	$seamDay = ($seam === PHP_INT_MAX) ? null : date('Y-m-d', $seam);
	$perday = array();
	$dailyTo = $seamDay ? date('Y-m-d', strtotime("$seamDay -1 day")) : date('Y-m-d', strtotime('-1 day'));
	if (strcmp($from, $dailyTo) <= 0) {
		$r = bwd_db_exec("SELECT day::text, in_bytes+out_bytes FROM {$p}daily
			WHERE mac = $1 AND day >= $2 AND day <= $3 AND day <> $4", array($id, $from, $dailyTo, $today));
		if ($r) { while ($x = pg_fetch_row($r)) { $perday[$x[0]] = (float)$x[1]; } }
	}
	if ($seamDay && strcmp($seamDay, $today) < 0) {
		$fineFrom = (strcmp($from, $seamDay) > 0) ? $from : $seamDay;
		$r = bwd_db_exec("SELECT date_trunc('day',to_timestamp(ts))::date::text d,
			sum(in_bytes+out_bytes) t FROM {$p}usage
			WHERE mac = $1 AND ts >= $2 AND ts < $3 GROUP BY d",
			array($id, strtotime("$fineFrom 00:00:00"), strtotime("$today 00:00:00")));
		if ($r) { while ($x = pg_fetch_row($r)) { $perday[$x[0]] = (float)$x[1]; } }
	}
	return array_values($perday);
}

/* All device keys ("mac:.." / "ip:..") ever recorded before today's midnight —
 * the new-device gate, using the full DB history (not the 400-day rollup cap). */
function bwd_db_known_keys() {
	$p = BWD_DB_PREFIX;
	$mid = strtotime('today 00:00:00');
	$keys = array();
	$r = bwd_db_exec("SELECT DISTINCT mac FROM {$p}daily WHERE mac <> '0.0.0.0' AND day < $1",
		array(date('Y-m-d')));
	if ($r) { while ($x = pg_fetch_row($r)) { $keys[bwd_db_devkey($x[0])] = true; } }
	$r = bwd_db_exec("SELECT DISTINCT mac FROM {$p}usage WHERE mac <> '0.0.0.0' AND ts < $1", array($mid));
	if ($r) { while ($x = pg_fetch_row($r)) { $keys[bwd_db_devkey($x[0])] = true; } }
	return $keys;
}
