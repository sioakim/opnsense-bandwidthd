#!/usr/local/bin/php
<?php
/*
 * bandwidthd_dbexport.php — cron exporter: CDF samples -> external PostgreSQL.
 *
 * Rotation-safe ingest: reads the finest-resolution (daily) CDF for samples
 * newer than the capture watermark and upserts them into the durable history DB
 * (idempotent via PK (ts, mac) + ON CONFLICT DO NOTHING).
 *
 * Resilience (#17): the CDF ring (~1 day) is no longer the only buffer. When the
 * DB is unreachable, pending fine buckets are written to a bounded local spool
 * under rollups/ and the capture watermark still advances, so samples that
 * rotate out of the CDF during a long outage are not lost. On reconnect the
 * spool is replayed (idempotent) before live ingest, then trimmed. The DB
 * `usage_watermark` (which the hybrid reader uses to split DB vs CDF) only ever
 * advances to data actually in the DB — never to spooled-but-unsent data — so no
 * read gap is created. A notice is raised after several failed runs.
 *
 * On first run it seeds the durable daily table (+ interface daily totals) from
 * the existing MAC-keyed rollup (rollups/daily.json) so pre-DB history isn't lost.
 *
 * Run by cron when the History DB is enabled. Flags:
 *   --dry-run    report without writing/spooling
 *   --backfill   re-scan ALL available CDF + the spool and upsert everything
 *                (idempotent), then recompute the watermark — for gap recovery.
 *
 * Licensed under the Apache License, Version 2.0.
 */

require_once(__DIR__ . '/lib/bwd_platform.inc.php');
require_once(__DIR__ . '/lib/bwd_data.inc.php');
require_once(__DIR__ . '/lib/bwd_db.inc.php');

define('BWD_SPOOL', BWD_BASE . '/rollups/dbexport_spool.ndjson');
define('BWD_EXPORT_STATE', BWD_BASE . '/rollups/dbexport_state.json');
define('BWD_LOCK', BWD_BASE . '/rollups/dbexport.lock');
define('BWD_SPOOL_MAX_BYTES', 67108864);   // 64 MiB; oldest dropped past this (size, not line count)
define('BWD_REPLAY_BATCH', 5000);          // replay rows per transaction (bounds memory)
define('BWD_FAIL_NOTICE_AT', 6);           // notice after N consecutive failures (~30 min @ */5)

$DRY = in_array('--dry-run', $argv, true) || in_array('-n', $argv, true);
$BACKFILL = in_array('--backfill', $argv, true);
$P = BWD_DB_PREFIX;

if (!bwd_db_enabled()) { echo "History DB is disabled — nothing to do.\n"; exit(0); }
if (!function_exists('pg_connect')) { fwrite(STDERR, "php pgsql extension not installed.\n"); exit(1); }

/* ---- local export state (survives DB outages; the DB can't store it then) ---- */
function exp_state_load() {
	$s = is_file(BWD_EXPORT_STATE) ? (json_decode(@file_get_contents(BWD_EXPORT_STATE), true) ?: array()) : array();
	return array('capture_watermark' => (int) ($s['capture_watermark'] ?? 0),
		'fails' => (int) ($s['fails'] ?? 0), 'notified' => (int) ($s['notified'] ?? 0));
}
function exp_state_save($s) { return bwd_atomic_write(BWD_EXPORT_STATE, bwd_json($s)); }

/* Append usage/iface records to the bounded spool. Records: usage rows as the
 * 11-elem array prefixed "u"; iface as ["i",ts,in,out]. */
function spool_append($rows, $iface) {
	$fh = @fopen(BWD_SPOOL, 'a');
	if (!$fh) { return 0; }
	$n = 0;
	/* Check every fwrite: a short/failed write (disk full) must surface as a count
	 * below the expected total so the caller does NOT advance the capture watermark
	 * over samples that never reached the spool. */
	foreach ($rows as $r) {
		$line = bwd_json(array_merge(array('u'), $r)) . "\n";
		if (fwrite($fh, $line) !== strlen($line)) { fclose($fh); spool_enforce_cap(); return $n; }
		$n++;
	}
	foreach ($iface as $ts => $io) {
		$line = bwd_json(array('i', $ts, (int) round($io[0]), (int) round($io[1]))) . "\n";
		if (fwrite($fh, $line) !== strlen($line)) { fclose($fh); spool_enforce_cap(); return $n; }
		$n++;
	}
	fclose($fh);
	spool_enforce_cap();
	return $n;
}
/* Keep the spool bounded by SIZE (not line count): past the byte cap, stream the
 * newest tail to a temp file and rename it in, dropping the oldest records. Streaming
 * avoids slurping a multi-GB spool into memory (`file()` would OOM the firewall long
 * before a 2M-line cap engaged — the very long-outage case the spool exists for). */
function spool_enforce_cap() {
	clearstatcache();
	$sz = is_file(BWD_SPOOL) ? @filesize(BWD_SPOOL) : 0;
	if ($sz === false || $sz <= BWD_SPOOL_MAX_BYTES) { return; }
	$keep = (int) (BWD_SPOOL_MAX_BYTES / 2);   // keep the newest half
	$in = @fopen(BWD_SPOOL, 'r');
	if (!$in) { return; }
	@fseek($in, $sz - $keep);
	@fgets($in);   // discard the partial first line after the seek
	$tmp = BWD_SPOOL . '.trim.' . getmypid();
	$out = @fopen($tmp, 'w');
	if (!$out) { fclose($in); return; }
	stream_copy_to_stream($in, $out);
	fclose($in); fclose($out);
	if (@rename($tmp, BWD_SPOOL)) {
		bwd_notify("BandwidthD", "History-DB export spool exceeded " . (BWD_SPOOL_MAX_BYTES >> 20) . " MiB; dropped the oldest pending samples during a prolonged DB outage.", LOG_WARNING);
	} else {
		@unlink($tmp);
	}
}
/* Replay the spool into the DB (idempotent), in bounded batches. Claims the spool
 * by atomically renaming it aside first, so records appended by a concurrent run
 * between read and delete are never lost (they land in a fresh spool). Returns
 * [recordsWritten, maxTs]; on a write error the claimed records are folded back so
 * they retry next run. */
function spool_replay($conn, $P) {
	if (!is_file(BWD_SPOOL)) { return array(0, 0); }
	$work = BWD_SPOOL . '.replay.' . getmypid();
	if (!@rename(BWD_SPOOL, $work)) { return array(0, 0); }   // claim atomically
	$fh = @fopen($work, 'r');
	if (!$fh) { return array(0, 0); }

	$rows = array(); $iface = array(); $maxTs = 0; $total = 0; $ok = true;
	$flush = function () use ($conn, $P, &$rows, &$iface, &$total) {
		if (!$rows && !$iface) { return true; }
		pg_query($conn, 'BEGIN');
		if (db_write_usage($conn, $P, $rows) && db_write_iface($conn, $P, $iface)
			&& pg_query($conn, 'COMMIT') !== false && pg_transaction_status($conn) === PGSQL_TRANSACTION_IDLE) {
			$total += count($rows) + count($iface); $rows = array(); $iface = array(); return true;
		}
		@pg_query($conn, 'ROLLBACK'); return false;
	};
	while (($line = fgets($fh)) !== false) {
		$j = json_decode(trim($line), true);
		if (!is_array($j) || !isset($j[0])) { continue; }
		if ($j[0] === 'u' && count($j) === 12) { $rows[] = array_slice($j, 1); if ($j[1] > $maxTs) { $maxTs = (int) $j[1]; } }
		elseif ($j[0] === 'i' && count($j) === 4) { $iface[(int) $j[1]] = array($j[2], $j[3]); if ($j[1] > $maxTs) { $maxTs = (int) $j[1]; } }
		if (count($rows) + count($iface) >= BWD_REPLAY_BATCH) { if (!$flush()) { $ok = false; break; } }
	}
	if ($ok) { $ok = $flush(); }
	fclose($fh);
	if ($ok) { @unlink($work); return array($total, $maxTs); }
	// failed: fold the claimed lines back into the (possibly newly-created) spool
	$back = @fopen(BWD_SPOOL, 'a');
	if ($back) { $wf = @fopen($work, 'r'); if ($wf) { stream_copy_to_stream($wf, $back); fclose($wf); } fclose($back); }
	@unlink($work);
	return array(-1, 0);   // signal failure; records preserved for next run
}

/* Chunked multi-row idempotent upsert of usage rows (11 cols). */
function db_write_usage($conn, $P, $rows) {
	$cols = 11; $batch = array_values($rows);
	for ($off = 0; $off < count($batch); $off += 500) {
		$chunk = array_slice($batch, $off, 500);
		$ph = array(); $vals = array(); $i = 1;
		foreach ($chunk as $r) {
			$set = array();
			for ($c = 0; $c < $cols; $c++) { $set[] = '$' . $i++; $vals[] = ($c <= 2) ? $r[$c] : (int) round($r[$c]); }
			$ph[] = '(' . implode(',', $set) . ')';
		}
		$sql = "INSERT INTO {$P}usage (ts,mac,ip,in_bytes,out_bytes,tcp,udp,http,p2p,icmp,ftp) VALUES "
			. implode(',', $ph) . " ON CONFLICT (ts, mac) DO NOTHING";
		if (pg_query_params($conn, $sql, $vals) === false) { fwrite(STDERR, "usage insert: " . pg_last_error($conn) . "\n"); return false; }
	}
	return true;
}
function db_write_iface($conn, $P, $iface) {
	foreach ($iface as $ts => $io) {
		if (pg_query_params($conn,
			"INSERT INTO {$P}iface (ts, in_bytes, out_bytes) VALUES ($1,$2,$3) ON CONFLICT (ts) DO NOTHING",
			array($ts, (int) round($io[0]), (int) round($io[1]))) === false) {
			fwrite(STDERR, "iface insert: " . pg_last_error($conn) . "\n"); return false;
		}
	}
	return true;
}

/* Read CDF (daily = finest) samples with ts > $after into usage rows + iface. */
function read_cdf($after) {
	$macmap = bwd_macmap();
	$rows = array(); $iface = array(); $devs = array(); $maxTs = $after;
	foreach (bwd_cdf_files(1, $after + 1, 0) as $file) {
		$fh = @fopen($file, 'r');
		if (!$fh) { continue; }
		while (($line = fgets($fh)) !== false) {
			$f = explode(',', trim($line));
			if (count($f) < 16) { continue; }
			$ip = $f[0];
			if (!is_ipaddrv4($ip)) { continue; }
			$ts = (int) $f[1];
			if ($ts <= $after) { continue; }
			if ($ts > $maxTs) { $maxTs = $ts; }
			if ($ip === '0.0.0.0') {
				if (!isset($iface[$ts])) { $iface[$ts] = array(0, 0); }
				$iface[$ts][0] += (float) $f[9]; $iface[$ts][1] += (float) $f[2];
				continue;
			}
			$mac = $macmap[$ip] ?? $ip;
			$key = $ts . '|' . $mac;
			if (!isset($rows[$key])) { $rows[$key] = array($ts, $mac, $ip, 0, 0, 0, 0, 0, 0, 0, 0); }
			$r = &$rows[$key];
			$r[3] += (float)$f[9]; $r[4] += (float)$f[2];
			$r[5] += (float)$f[5] + (float)$f[12]; $r[6] += (float)$f[4] + (float)$f[11];
			$r[7] += (float)$f[7] + (float)$f[14]; $r[8] += (float)$f[8] + (float)$f[15];
			$r[9] += (float)$f[3] + (float)$f[10]; $r[10] += (float)$f[6] + (float)$f[13];
			unset($r);
			$devs[$mac] = array($ip, $ts);
		}
		fclose($fh);
	}
	/* The newest timestamp may be a bucket bandwidthd is still appending to (CDF
	 * writes are not atomic): reading mid-flush yields a partial (ts,mac) row that
	 * ON CONFLICT DO NOTHING would then freeze forever. Defer the newest bucket —
	 * drop every sample at the max ts and report the newest fully-closed bucket as
	 * the watermark. The deferred bucket is re-read complete next run; until then
	 * the hybrid reader serves it from the live CDF tail (ts > watermark), so it is
	 * never hidden from the dashboard. */
	if ($maxTs > $after) {
		$open = $maxTs;
		foreach ($rows as $k => $r) { if ((int) $r[0] === $open) { unset($rows[$k]); } }
		unset($iface[$open]);
		$closed = $after;
		foreach ($rows as $r) { if ((int) $r[0] > $closed) { $closed = (int) $r[0]; } }
		foreach (array_keys($iface) as $ts) { if ((int) $ts > $closed) { $closed = (int) $ts; } }
		$maxTs = $closed;
	}
	return array(array_values($rows), $iface, $devs, $maxTs);
}

/* Upsert the device identity cache for the devices seen this run. Best-effort and
 * NON-transactional by design (called after the history commit): returns the number
 * of upserts that failed so the caller can report it, but a failure here never
 * affects ingested traffic or the watermark. */
function write_devices($conn, $P, $devs, $names) {
	$fails = 0;
	foreach ($devs as $mac => $d) {
		list($ip, $ls) = $d;
		$nm = bwd_name($ip, $mac, $names[$ip] ?? '');
		$v = bwd_device_vendor($mac, $ip);
		$tag = bwd_tag($mac, $ip, $v['vendor']);
		$r = pg_query_params($conn,
			"INSERT INTO {$P}device (mac, last_ip, name, vendor, tag, first_seen, last_seen)
			 VALUES ($1,$2,$3,$4,$5,$6,$6)
			 ON CONFLICT (mac) DO UPDATE SET last_ip=EXCLUDED.last_ip,
			   name=CASE WHEN EXCLUDED.name <> '' THEN EXCLUDED.name ELSE {$P}device.name END,
			   vendor=EXCLUDED.vendor, tag=EXCLUDED.tag,
			   first_seen=LEAST({$P}device.first_seen, EXCLUDED.first_seen),
			   last_seen=GREATEST({$P}device.last_seen, EXCLUDED.last_seen)",
			array($mac, $ip, $nm, $v['vendor'], $tag, $ls));
		if ($r === false) { $fails++; @error_log('bwd_dbexport device upsert: ' . pg_last_error($conn)); }
	}
	return $fails;
}

/* Run-lock: a scheduled run that overruns its interval (large spool replay, slow
 * remote DB after an outage) must not overlap the next one — overlapping runs racing
 * the spool could drop records. A non-blocking exclusive lock means a late run skips. */
$lockfh = @fopen(BWD_LOCK, 'c');
if ($lockfh && !@flock($lockfh, LOCK_EX | LOCK_NB)) {
	echo "another export run is in progress — skipping.\n";
	exit(0);
}

$state = exp_state_load();
$conn = bwd_db();
$dbUp = $conn && bwd_db_ensure_schema();

/* =========================== BACKFILL / REPAIR =========================== */
if ($BACKFILL) {
	if (!$dbUp) { fwrite(STDERR, "Backfill needs the DB; it is unreachable.\n"); exit(1); }
	list($rows, $iface, $devs, $maxTs) = read_cdf(0);   // ALL available CDF
	list($sn, $sMax) = $DRY ? array(0, 0) : spool_replay($conn, $P);
	if ($sn < 0) { fwrite(STDERR, "spool replay failed during backfill.\n"); exit(1); }
	if (!$DRY) {
		pg_query($conn, 'BEGIN');
		$ok = db_write_usage($conn, $P, $rows) && db_write_iface($conn, $P, $iface);
		if (!$ok) { pg_query($conn, 'ROLLBACK'); fwrite(STDERR, "backfill insert failed.\n"); exit(1); }
		if (pg_query($conn, 'COMMIT') === false || pg_transaction_status($conn) !== PGSQL_TRANSACTION_IDLE) {
			@pg_query($conn, 'ROLLBACK'); fwrite(STDERR, "backfill commit failed.\n"); exit(1);
		}
		$r = pg_query($conn, "SELECT coalesce(max(ts),0) FROM {$P}usage");
		$wm = $r ? (int) pg_fetch_row($r)[0] : $maxTs;
		bwd_db_meta_set('usage_watermark', $wm);
		exp_state_save(array('capture_watermark' => max($state['capture_watermark'], $maxTs, $wm), 'fails' => 0));
	}
	printf("%sbackfill: scanned %d CDF usage rows + %d iface, replayed %d spooled; watermark synced to DB max.\n",
		$DRY ? "DRY: " : "", count($rows), count($iface), max(0, $sn));
	exit(0);
}

/* ============================= NORMAL RUN ============================= */
$Wdb = $dbUp ? bwd_db_watermark() : 0;
$captureW = max($state['capture_watermark'], $Wdb);
list($rows, $iface, $devs, $maxTs) = read_cdf($captureW);

if (!$dbUp) {
	/* DB down: spool the new samples so they survive CDF rotation; advance the
	 * capture watermark (so we don't re-spool them) but NOT the DB watermark. */
	$state['fails']++;
	if ($rows || $iface) {
		$expected = count($rows) + count($iface);
		$n = $DRY ? $expected : spool_append($rows, $iface);
		if (!$DRY && $n < $expected) {
			/* Spool write failed/partial (disk full, RO fs): do NOT advance the
			 * capture watermark — these samples are in neither the DB nor the
			 * spool, so they must be re-read next run, not skipped. */
			fwrite(STDERR, "spool write incomplete ($n/$expected) — not advancing watermark; will retry.\n");
			if ($state['fails'] >= BWD_FAIL_NOTICE_AT) {
				bwd_notify("BandwidthD", "History DB unreachable and the local export spool could not be written ($n/$expected records); samples may be lost if this persists.", LOG_ERR);
			}
			exp_state_save($state);
			exit(1);
		}
		$state['capture_watermark'] = max($captureW, $maxTs);
		printf("%sDB unreachable — spooled %d pending record(s) (watermark %s -> %s).\n",
			$DRY ? "DRY: " : "", $n, $captureW ? date('Y-m-d H:i:s', $captureW) : '0', date('Y-m-d H:i:s', $maxTs));
	} else {
		echo "DB unreachable — no new samples to spool.\n";
	}
	/* Latch the outage notice: raise it ONCE when the failure threshold is first
	 * crossed, not on every run (a week-long outage would otherwise emit ~2000
	 * notices). 'notified' is carried in state during the outage and auto-clears on
	 * the next successful run (which writes a fresh state without the flag). */
	if ($state['fails'] >= BWD_FAIL_NOTICE_AT && empty($state['notified']) && !$DRY) {
		bwd_notify("BandwidthD", "History DB unreachable for {$state['fails']} consecutive export runs; samples are being spooled locally and will replay on reconnect.", LOG_WARNING);
		$state['notified'] = 1;
	}
	if (!$DRY) { exp_state_save($state); }
	exit(0);
}

/* DB up: one-time seed of pre-DB daily history from the rollup. */
if (bwd_db_meta_get('seeded') === null) {
	$roll = is_file(BWD_ROLLUP) ? (json_decode(@file_get_contents(BWD_ROLLUP), true) ?: array()) : array();
	$seeded = 0;
	if (!$DRY && $roll) { pg_query($conn, 'BEGIN'); }
	foreach ($roll as $day => $hosts) {
		/* Aggregate per (day, MAC) BEFORE inserting: the rollup is IP-keyed, so a
		 * MAC seen under two IPs the same day (a lease renewal — the exact case
		 * MAC-keying exists for) would otherwise insert twice, with the second hit
		 * dropped by the (day,mac) PK and its bytes lost. Keep the highest-traffic
		 * IP as the descriptive ip. */
		$agg = array();
		foreach ((array) $hosts as $ip => $v) {
			if ($ip === '0.0.0.0') { continue; }
			$mac = !empty($v['mac']) ? $v['mac'] : $ip;
			$in = (float) $v['in']; $out = (float) $v['out'];
			if (!isset($agg[$mac])) { $agg[$mac] = array('in' => 0.0, 'out' => 0.0, 'ip' => $ip, 'top' => -1.0); }
			$agg[$mac]['in'] += $in; $agg[$mac]['out'] += $out;
			if ($in + $out > $agg[$mac]['top']) { $agg[$mac]['top'] = $in + $out; $agg[$mac]['ip'] = $ip; }
		}
		foreach ($agg as $mac => $a) {
			if (!$DRY) {
				pg_query_params($conn,
					"INSERT INTO {$P}daily (day, mac, ip, in_bytes, out_bytes) VALUES ($1,$2,$3,$4,$5)
					 ON CONFLICT (day, mac) DO NOTHING",
					array($day, $mac, $a['ip'], (int) round($a['in']), (int) round($a['out'])));
			}
			$seeded++;
		}
	}
	if (!$DRY && $roll) { pg_query($conn, 'COMMIT'); bwd_db_meta_set('seeded', date('c')); }
	echo ($DRY ? "DRY: would seed " : "Seeded ") . "$seeded daily rows from rollup.\n";
}

/* One-time backfill of interface daily totals (sentinel mac '0.0.0.0') from the
 * rollup so reports over pre-DB days have interface figures (separate flag). */
if (bwd_db_meta_get('seeded_iface') === null) {
	$roll = isset($roll) ? $roll : (is_file(BWD_ROLLUP) ? (json_decode(@file_get_contents(BWD_ROLLUP), true) ?: array()) : array());
	$n = 0;
	if (!$DRY && $roll) { pg_query($conn, 'BEGIN'); }
	foreach ($roll as $day => $hosts) {
		$v = $hosts['0.0.0.0'] ?? null;
		if (!$v) { continue; }
		if (!$DRY) {
			pg_query_params($conn,
				"INSERT INTO {$P}daily (day, mac, ip, in_bytes, out_bytes) VALUES ($1,'0.0.0.0','0.0.0.0',$2,$3)
				 ON CONFLICT (day, mac) DO NOTHING",
				array($day, (int) round($v['in']), (int) round($v['out'])));
		}
		$n++;
	}
	if (!$DRY && $roll) { pg_query($conn, 'COMMIT'); bwd_db_meta_set('seeded_iface', date('c')); }
	echo ($DRY ? "DRY: would backfill " : "Backfilled ") . "$n interface daily rows from rollup.\n";
}

/* Replay any spool from a prior outage BEFORE live ingest (idempotent). */
$spoolMax = 0;
if (is_file(BWD_SPOOL) && !$DRY) {
	list($sn, $spoolMax) = spool_replay($conn, $P);
	if ($sn < 0) { fwrite(STDERR, "spool replay failed — will retry next run.\n"); exit(1); }
	if ($sn > 0) { echo "replayed $sn spooled record(s) from a prior outage.\n"; }
}

if (empty($rows) && empty($iface) && !$spoolMax) {
	if (!$DRY) { exp_state_save(array('capture_watermark' => max($captureW, $Wdb), 'fails' => 0)); }
	echo ($DRY ? "DRY: " : "") . "no new samples past watermark " . ($captureW ? date('Y-m-d H:i:s', $captureW) : '0') . ".\n";
	exit(0);
}

if ($DRY) {
	printf("DRY: would ingest %d usage rows + %d iface rows; watermark %s -> %s.\n",
		count($rows), count($iface), $captureW ? date('Y-m-d H:i:s', $captureW) : '0', date('Y-m-d H:i:s', $maxTs));
	exit(0);
}

/* Write the new samples AND advance the DB watermark in ONE transaction, so the
 * hybrid reader's DB/CDF split (on usage_watermark) can never point past data that
 * isn't durably stored. Every statement is checked; the local capture watermark is
 * advanced ONLY after the commit is confirmed. The device identity cache is upserted
 * separately, AFTER the commit (it is not history — a failure there must never roll
 * back ingested traffic nor strand the watermark, the exact bug that previously
 * dropped a whole run's samples silently). */
pg_query($conn, 'BEGIN');
if (!db_write_usage($conn, $P, $rows) || !db_write_iface($conn, $P, $iface)) {
	pg_query($conn, 'ROLLBACK');
	fwrite(STDERR, "ingest failed — will retry next run.\n"); exit(1);
}
$newWm = max($maxTs, $Wdb, $spoolMax);
if (!bwd_db_meta_set('usage_watermark', $newWm)) {
	pg_query($conn, 'ROLLBACK');
	fwrite(STDERR, "watermark update failed — will retry next run.\n"); exit(1);
}
if (pg_transaction_status($conn) === PGSQL_TRANSACTION_INERROR) {
	pg_query($conn, 'ROLLBACK');
	fwrite(STDERR, "transaction aborted before commit — will retry next run.\n"); exit(1);
}
if (pg_query($conn, 'COMMIT') === false || pg_transaction_status($conn) !== PGSQL_TRANSACTION_IDLE) {
	@pg_query($conn, 'ROLLBACK');
	fwrite(STDERR, "commit failed — will retry next run.\n"); exit(1);
}
/* History is durably committed — only now advance the local capture watermark. */
exp_state_save(array('capture_watermark' => max($captureW, $maxTs), 'fails' => 0));
/* Best-effort device identity cache (post-commit, autocommit; non-fatal). */
$devFails = write_devices($conn, $P, $devs, bwd_hostmap());

printf("ingested %d usage rows, %d iface rows, %d devices%s; watermark -> %s\n",
	count($rows), count($iface), count($devs),
	$devFails ? " ($devFails device upsert(s) failed)" : "", date('Y-m-d H:i:s', $newWm));
