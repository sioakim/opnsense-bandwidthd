#!/usr/local/bin/php
<?php
/*
 * bandwidthd_dbmaint.php — cron-driven PostgreSQL maintenance (#16).
 *
 * Keeps the external history DB bounded and fast:
 *   1. Downsampling — roll whole days of fine `bwd_usage` rows older than the
 *      fine-retention window up into the coarse `bwd_daily` tier (per-device +
 *      an interface sentinel row mac='0.0.0.0'), then delete the fine rows.
 *      The daily value is written as the AUTHORITATIVE sum of that day's fine
 *      rows (ON CONFLICT DO UPDATE), so a prior rollup-seed estimate is
 *      corrected, and deleting the fine rows advances the daily/fine seam so the
 *      reader serves the day from `bwd_daily` — never both (no double-count).
 *   2. Retention — optional cap on `bwd_daily` age.
 *   3. Maintenance — VACUUM (ANALYZE) the tables so stats/space stay healthy.
 *
 * Retention is OFF by default (db_fine_retention_days = 0 keeps all fine rows);
 * nothing is deleted until the operator sets a window. Idempotent + safe to
 * re-run. Run daily by cron when the History DB is enabled. --dry-run reports
 * the plan without writing/deleting.
 *
 * Licensed under the Apache License, Version 2.0.
 */

require_once(__DIR__ . '/lib/bwd_platform.inc.php');
require_once(__DIR__ . '/lib/bwd_data.inc.php');
require_once(__DIR__ . '/lib/bwd_db.inc.php');

define('BWD_MAINT_MAX_DAYS', 120);    // bound work per run (catch-up over time)

$DRY = in_array('--dry-run', $argv, true) || in_array('-n', $argv, true);
$P = BWD_DB_PREFIX;

/* --test: connect, create the schema if needed, report, and stop. Drives the
 * "Test database connection" button on the settings page. */
if (in_array('--test', $argv, true)) {
	/* Always exits 0: this is a diagnostic whose result is the text it prints.
	   configd's script_output reports a non-zero exit as "Execute error" and
	   throws the message away, which is the one thing the button must not do. */
	if (!bwd_db_available()) {
		echo "failed: no pgsql PHP extension on this system (see docs/POSTGRES.md)\n";
	} elseif (bwd_db_cfg('db_enable') !== 'on') {
		echo "failed: history database is not enabled\n";
	} elseif (!bwd_db()) {
		echo "failed: cannot connect to " . bwd_db_cfg('db_host') . "\n";
	} else {
		echo bwd_db_ensure_schema() ? "ok: connected, schema present\n" : "failed: connected but schema setup failed\n";
	}
	exit(0);
}

if (!bwd_db_enabled()) {
	echo bwd_db_available() ? "History DB is disabled — nothing to do.\n"
		: "History DB unavailable (no pgsql PHP extension) — nothing to do.\n";
	exit(0);
}
$conn = bwd_db();
if (!$conn) { fwrite(STDERR, "Cannot connect to history DB — will retry next run.\n"); exit(1); }
if (!bwd_db_ensure_schema()) { fwrite(STDERR, "Schema setup failed.\n"); exit(1); }

/* ---- 1. Downsampling: whole days older than the fine-retention window ---- */
$fineDays = (int) bwd_db_cfg('db_fine_retention_days', 0);
$downDays = 0; $downUsage = 0; $downIface = 0; $downFails = 0;
if ($fineDays > 0) {
	// Earliest fine day present.
	$r = pg_query($conn, "SELECT min(ts) FROM {$P}usage");
	$minTs = ($r && ($x = pg_fetch_row($r)) && $x[0] !== null) ? (int) $x[0] : 0;
	if ($minTs) {
		$cutoffDay = date('Y-m-d', strtotime("today -{$fineDays} day"));  // days < this are eligible
		$day = date('Y-m-d', $minTs);
		while (strcmp($day, $cutoffDay) < 0 && $downDays < BWD_MAINT_MAX_DAYS) {
			$t0 = strtotime("$day 00:00:00");
			$t1 = strtotime("$day 00:00:00 +1 day");   // [t0, t1) local-day bounds (no tz ambiguity)
			// only act if the day still has fine rows
			$r = pg_query_params($conn, "SELECT count(*) FROM {$P}usage WHERE ts >= $1 AND ts < $2", array($t0, $t1));
			$cnt = $r ? (int) pg_fetch_row($r)[0] : 0;
			if ($cnt > 0) {
				if ($DRY) {
					printf("DRY: would downsample %s (%d fine rows) -> bwd_daily, then delete.\n", $day, $cnt);
				} else {
					pg_query($conn, 'BEGIN');
					$ok = pg_query_params($conn,
						"INSERT INTO {$P}daily (day, mac, ip, in_bytes, out_bytes)
						 SELECT $1::date, mac, (array_agg(ip ORDER BY ts DESC))[1], sum(in_bytes), sum(out_bytes)
						   FROM {$P}usage WHERE ts >= $2 AND ts < $3 GROUP BY mac
						 ON CONFLICT (day, mac) DO UPDATE SET
						   ip = EXCLUDED.ip, in_bytes = EXCLUDED.in_bytes, out_bytes = EXCLUDED.out_bytes",
						array($day, $t0, $t1)) !== false;
					$ok = $ok && pg_query_params($conn,
						"INSERT INTO {$P}daily (day, mac, ip, in_bytes, out_bytes)
						 SELECT $1::date, '0.0.0.0', '0.0.0.0', coalesce(sum(in_bytes),0), coalesce(sum(out_bytes),0)
						   FROM {$P}iface WHERE ts >= $2 AND ts < $3 HAVING count(*) > 0
						 ON CONFLICT (day, mac) DO UPDATE SET
						   in_bytes = EXCLUDED.in_bytes, out_bytes = EXCLUDED.out_bytes",
						array($day, $t0, $t1)) !== false;
					$ok = $ok && pg_query_params($conn, "DELETE FROM {$P}usage WHERE ts >= $1 AND ts < $2", array($t0, $t1)) !== false;
					$ok = $ok && pg_query_params($conn, "DELETE FROM {$P}iface WHERE ts >= $1 AND ts < $2", array($t0, $t1)) !== false;
					if ($ok) { pg_query($conn, 'COMMIT'); $downUsage += $cnt; $downIface++; }
					else { pg_query($conn, 'ROLLBACK'); $downFails++; fwrite(STDERR, "downsample $day failed: " . pg_last_error($conn) . "\n"); }
				}
				$downDays++;
			}
			$day = date('Y-m-d', $t1);
		}
	}
	echo ($DRY ? "DRY: " : "") . "downsampled $downDays day(s); $downUsage fine rows folded" . ($downFails ? "; $downFails day(s) FAILED" : "") . ".\n";
	if ($downFails && !$DRY) {
		// cron output is discarded — surface persistent failures as a notice
		bwd_notify("BandwidthD", "History-DB downsampling failed for $downFails day(s); fine rows for those days were not folded and will be retried. Check the DB.", LOG_WARNING);
	}
} else {
	echo "fine retention disabled (db_fine_retention_days=0) — no downsampling.\n";
}

/* ---- 2. Daily-tier retention (optional; default keep forever) ---- */
$dailyDays = (int) bwd_db_cfg('db_daily_retention_days', 0);
if ($dailyDays > 0) {
	$cut = date('Y-m-d', strtotime("today -{$dailyDays} day"));
	if ($DRY) {
		$r = pg_query_params($conn, "SELECT count(*) FROM {$P}daily WHERE day < $1", array($cut));
		printf("DRY: would delete %s daily rows older than %s.\n", $r ? pg_fetch_row($r)[0] : '?', $cut);
	} else {
		$r = pg_query_params($conn, "DELETE FROM {$P}daily WHERE day < $1", array($cut));
		printf("pruned %d daily rows older than %s.\n", $r ? pg_affected_rows($r) : 0, $cut);
	}
}

/* ---- 3. Maintenance: keep stats/space healthy (VACUUM cannot run in a txn) ---- */
if (!$DRY) {
	foreach (array('usage', 'iface', 'daily', 'device') as $t) {
		@pg_query($conn, "VACUUM (ANALYZE) {$P}$t");
	}
	echo "vacuum/analyze done.\n";
}
