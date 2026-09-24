<?php
/* bwd_flows: ntopng live flows -> per-device destination/app deltas, top-N
 * capping, hourly/daily buckets and the windowed query the dashboard reads. */
t_group('flows');

$isLocal = function ($ip) { return strpos($ip, '192.0.2.') === 0; };
$idOf = function ($ip) { return $ip === '192.0.2.10' ? 'aa:bb:cc:00:00:10' : $ip; };
$flow = function ($cli, $srv, $cb, $sb, $info = '', $app = 'TLS', $key = '1', $first = 1000) {
	return array('ifid' => 0, 'key' => $key, 'first_seen' => $first, 'info' => $info,
		'client' => array('ip' => $cli, 'name' => $cli), 'server' => array('ip' => $srv, 'name' => $srv),
		'bytes' => array('cli_bytes' => $cb, 'srv_bytes' => $sb, 'total' => $cb + $sb),
		'application' => array('name' => $app), 'l4_proto' => array('name' => 'TCP'));
};

/* ---- destination label ---- */
foreach (array('-.example', '-bad.example', 'bad-.example', 'ok.bad-', str_repeat('a', 64) . '.example',
	'ok.' . str_repeat('a', 64), 'empty..example') as $invalid) {
	t_eq('198.51.100.1', bwd_flows_dest_label($invalid, '', '198.51.100.1'), 'reject invalid destination label: ' . $invalid);
	t_eq('valid.example', bwd_flows_dest_label($invalid, 'valid.example', '198.51.100.1'), 'invalid info falls back to server name: ' . $invalid);
	t_eq('198.51.100.1', bwd_flows_dest_label('', $invalid, '198.51.100.1'), 'reject invalid server label: ' . $invalid);
}
foreach (array('_dmarc.example.com', 'a._service', 'a.b', 'a-b.example', str_repeat('a', 63) . '.example') as $valid) {
	t_eq($valid, bwd_flows_dest_label($valid, '', '198.51.100.1'), 'accept valid destination label: ' . $valid);
}
$maxName = str_repeat(str_repeat('a', 63) . '.', 3) . str_repeat('b', 61);
t_eq($maxName, bwd_flows_dest_label($maxName, '', '198.51.100.1'), 'accept 253-character destination');
t_eq('198.51.100.1', bwd_flows_dest_label($maxName . 'b', '', '198.51.100.1'), 'reject 254-character destination');
t_eq('2001:db8::1', bwd_flows_dest_label('-.example', 'bad-.example', '2001:db8::1'), 'invalid labels preserve IPv6 fallback');
t_eq('video.example.com', bwd_flows_dest_label('Video.Example.COM.', '', '198.51.100.1'), 'SNI lower-cased, trailing dot dropped');
t_eq('198.51.100.1', bwd_flows_dest_label('Desktop Sharing', '198.51.100.1', '198.51.100.1'),
	'free-text info (a Teams STUN label) is not a destination');
t_eq('cdn.example.net', bwd_flows_dest_label('', 'cdn.example.net', '198.51.100.1'), 'falls back to the resolved server name');
t_eq('198.51.100.1', bwd_flows_dest_label('', '198.51.100.1', '198.51.100.1'), 'an IP-shaped name is not a hostname');
// A malformed row must not be able to name itself after the (other) remainder row.
t_eq('unknown', bwd_flows_dest_label('', '', BWD_FLOWS_OTHER), 'a non-address fallback becomes unknown, never (other)');

/* ---- ingest ---- */
$f1 = $flow('192.0.2.10', '198.51.100.1', 100, 5000, 'video.example.com', 'TLS.Video');

// First poll (no previous state): a baseline, so bytes that predate collection
// are not dumped into the current hour.
list($st, $add) = bwd_flows_ingest(array(), 0, array($f1), $isLocal, $idOf, 2000);
t_eq(0, count($add['hosts']), 'baseline poll counts nothing');
t_eq(1, count($st), 'baseline poll records the flow counters');

// Second poll: only the growth since the baseline counts; local=client means
// client bytes are "out" and server bytes "in".
$f1b = $flow('192.0.2.10', '198.51.100.1', 300, 9000, 'video.example.com', 'TLS.Video');
list($st2, $add) = bwd_flows_ingest($st, 2000, array($f1b), $isLocal, $idOf, 2060);
$h = $add['hosts']['aa:bb:cc:00:00:10'] ?? null;
t_ok($h !== null, 'device keyed by MAC when known');
t_eq(array(4000.0, 200.0, 'TLS.Video'), $h['d']['video.example.com'], 'delta in/out attributed to the SNI');
t_eq(array(4000.0, 200.0), $h['a']['TLS.Video'], 'same delta attributed to the application');

// A flow that appeared since the last (non-stale) poll counts in full.
$f2 = $flow('192.0.2.10', '198.51.100.2', 10, 20, 'api.example.org', 'TLS', '2', 2030);
list(, $add) = bwd_flows_ingest($st2, 2060, array($f1b, $f2), $isLocal, $idOf, 2120);
t_eq(array(20.0, 10.0, 'TLS'), $add['hosts']['aa:bb:cc:00:00:10']['d']['api.example.org'], 'new flow counted in full');
t_ok(!isset($add['hosts']['aa:bb:cc:00:00:10']['d']['video.example.com']), 'unchanged flow contributes nothing');

// Inbound (local end is the server): the local side's view flips.
$f3 = $flow('203.0.113.5', '192.0.2.20', 700, 30, '', 'SIP', '3', 2100);
list(, $add) = bwd_flows_ingest($st2, 2060, array($f3), $isLocal, $idOf, 2120);
t_eq(array(700.0, 30.0, 'SIP'), $add['hosts']['192.0.2.20']['d']['203.0.113.5'], 'inbound flow: client bytes are the device\'s "in"');

// Local-to-local and non-local flows are not a device's traffic.
$f4 = $flow('192.0.2.10', '192.0.2.1', 50, 50, 'router.lan', 'DNS', '4', 2100);
$f5 = $flow('198.51.100.9', '203.0.113.9', 50, 50, '', 'TLS', '5', 2100);
list($st5, $add) = bwd_flows_ingest($st2, 2060, array($f4, $f5), $isLocal, $idOf, 2120);
t_eq(0, count($add['hosts']), 'local<->local and remote<->remote flows skipped');
t_eq(array_keys($st2), array_keys($st5), 'skipped flows add no state; missing known flows retain grace');

// A counter that went backwards is a reused key: count the new flow in full.
$f1r = $flow('192.0.2.10', '198.51.100.1', 5, 50, 'video.example.com', 'TLS.Video');
list(, $add) = bwd_flows_ingest($st2, 2060, array($f1r), $isLocal, $idOf, 2120);
t_eq(array(50.0, 5.0, 'TLS.Video'), $add['hosts']['aa:bb:cc:00:00:10']['d']['video.example.com'], 'counter reset counted from zero');

// After a gap longer than BWD_FLOWS_STALE the poll is a baseline again.
$grown = $f1b;
$grown['bytes']['srv_bytes'] = 1000000;
list(, $add) = bwd_flows_ingest($st2, 2060, array($grown, $f2), $isLocal, $idOf, 2060 + BWD_FLOWS_STALE + 1);
t_eq(0, count($add['hosts']), 'stale previous poll: nothing counted');

// Identical rows subtract the previous observation only once.
list(, $once) = bwd_flows_ingest($st, 2000, array($f1b, $f1b), $isLocal, $idOf, 2060);
t_eq(4000.0, $once['hosts']['aa:bb:cc:00:00:10']['d']['video.example.com'][0], 'duplicate rows cannot subtract prev twice');
foreach (array('client', 'server', 'protocol') as $field) {
	$distinct = $f1b;
	if ($field === 'protocol') { $distinct['l4_proto']['name'] = 'UDP'; }
	else { $distinct[$field]['port'] = 443; }
	list($keys,) = bwd_flows_ingest(array(), 0, array($f1b, $distinct), $isLocal, $idOf, 2060);
	t_eq(2, count($keys), $field . ' distinguishes simultaneous flow keys');
}
list($missing,) = bwd_flows_ingest($st, 2000, array(), $isLocal, $idOf, 2060);
list(, $back) = bwd_flows_ingest($missing, 2060, array($f1b), $isLocal, $idOf, 2120);
t_eq(4000.0, $back['hosts']['aa:bb:cc:00:00:10']['d']['video.example.com'][0], 'one missing poll does not replay lifetime counters');
list($expired,) = bwd_flows_ingest($missing, 2060, array(), $isLocal, $idOf, 2601);
t_eq(array(), $expired, 'grace expires from last sighting, not the last poll');

/* ---- merge + cap ---- */
$b = array('v' => 1, 'hosts' => array());
$add = array('hosts' => array('m' => array('ip' => '192.0.2.10', 'a' => array('TLS' => array(10, 0)), 'd' => array(
	'a.example' => array(1, 0, 'TLS'), 'b.example' => array(50, 0, 'TLS'), 'c.example' => array(30, 0, 'TLS'),
	'd.example' => array(2, 0, 'TLS')))));
bwd_flows_merge($b, $add, 3);
$d = $b['hosts']['m']['d'];
t_eq(3, count($d), 'capped to topn rows, (other) included');
t_ok(isset($d['b.example']) && isset($d['c.example']), 'largest destinations kept');
t_eq(3.0, $d[BWD_FLOWS_OTHER][0], 'folded destinations summed into (other)');
bwd_flows_merge($b, $add, 3);
t_eq(100.0, $b['hosts']['m']['d']['b.example'][0], 'merge adds to existing rows');
t_eq(6.0, $b['hosts']['m']['d'][BWD_FLOWS_OTHER][0] + 0.0, 'overflow keeps accruing into (other)');
t_eq(20.0, $b['hosts']['m']['a']['TLS'][0] + 0.0, 'applications summed, never capped');

/* ---- buckets on disk, compaction and the windowed query ---- */
$dir = sys_get_temp_dir() . '/bwd_flows_' . getmypid();
@mkdir($dir, 0700, true);
$now = strtotime('2026-03-20 12:30:00');
$mk = function ($dest, $in, $mac = 'aa:bb:cc:00:00:10', $ip = '192.0.2.10') {
	return array('v' => 1, 'hosts' => array($mac => array('ip' => $ip, 'd' => array($dest => array($in, 1, 'TLS')), 'a' => array('TLS' => array($in, 1)))));
};
bwd_atomic_write(bwd_flows_hour_file($now, $dir), bwd_json($mk('now.example', 100)));
bwd_atomic_write(bwd_flows_hour_file($now - 3 * 3600, $dir), bwd_json($mk('earlier.example', 200)));
bwd_atomic_write(bwd_flows_hour_file($now - 3 * 3600, $dir), bwd_json($mk('earlier.example', 200)));
$old = strtotime('2026-03-01 10:00:00');   // beyond the hourly horizon
bwd_atomic_write(bwd_flows_hour_file($old, $dir), bwd_json($mk('old.example', 300)));
bwd_atomic_write(bwd_flows_hour_file($old + 3600, $dir), bwd_json($mk('old.example', 5, 'aa:bb:cc:00:00:99', '192.0.2.99')));

t_eq(1, bwd_flows_compact($now, 100, 31, $dir), 'one finished day compacted');
t_ok(is_file("$dir/d/20260301.json"), 'daily file written');
t_ok(!is_file(bwd_flows_hour_file($old, $dir)), 'hourly files past the horizon removed once compacted');
t_ok(is_file(bwd_flows_hour_file($now, $dir)), 'recent hourly files kept');
t_eq(strtotime('2026-03-01 00:00:00'), bwd_flows_since($dir), 'since = earliest bucket start');

$r = bwd_destinations('aa:bb:cc:00:00:10', 1, $now - 3600, $now, array(), 100, $dir, $now);
t_eq(array('now.example'), array_column($r['dests'], 'name'), '1h window reads only the current hour');
$r = bwd_destinations('aa:bb:cc:00:00:10', 1, 0, 0, array(), 100, $dir, $now);
t_eq(array('earlier.example', 'now.example'), array_column($r['dests'], 'name'), 'default 24h window, sorted by total');
t_eq(300.0, $r['total_in'] + 0.0, 'window totals');
$r = bwd_destinations('192.0.2.10', 1, 0, 0, array(), 100, $dir, $now);
t_eq(2, count($r['dests']), 'an IP id matches the device recorded under its MAC');
$r = bwd_destinations('0.0.0.0', 3, strtotime('2026-02-25'), $now, array(), 100, $dir, $now);
t_eq(array('old.example', 'earlier.example', 'now.example'), array_column($r['dests'], 'name'), 'all devices, daily + hourly files');
t_eq(305.0, $r['dests'][0]['in'] + 0.0, 'two devices\' bytes for one destination are summed');
$r = bwd_destinations('aa:bb:cc:00:00:10', 1, 0, 0, array(), 1, $dir, $now);
t_eq(1, count($r['dests']), 'limit applied');
t_eq(1, $r['more'], 'rows beyond the limit are reported');
t_eq(array(), bwd_destinations('aa:bb:cc:00:00:77', 1, 0, 0, array(), 100, $dir, $now)['dests'], 'unknown device: empty');

// Retention: daily files older than the retention are removed.
bwd_flows_compact(strtotime('2026-03-20 12:30:00'), 100, 9, $dir);
t_ok(!is_file("$dir/d/20260301.json"), 'daily files past retention removed');

// A far-future request is bounded by actual retained history and now.
$started = microtime(true);
$selected = bwd_flows_files(1, 253402300799, $now, $dir);
t_eq(2, count($selected), 'extreme window selects only surviving files');
$r = bwd_destinations('0.0.0.0', 1, 1, 253402300799, array(), 1, $dir, $now);
t_eq(300.0, $r['total_in'] + 0.0, 'extreme API window aggregates only retained files');
t_ok(microtime(true) - $started < 1, 'extreme window completes without walking arbitrary timestamps');
t_eq(array(), bwd_flows_files($now, $now - 1, $now, $dir), 'reversed window selects nothing');

// The repeated local hour is one physical file and must contribute only once.
$tz = date_default_timezone_get();
date_default_timezone_set('Europe/Athens');
$dstDir = "$dir/dst";
$fall = strtotime('2026-10-25T03:00:00+03:00');
bwd_atomic_write(bwd_flows_hour_file($fall, $dstDir), bwd_json($mk('dst.example', 300)));
$paths = bwd_flows_files($fall, $fall + 7199, $fall + 7200, $dstDir);
t_eq(1, count($paths), 'DST repeated hour is read once');
t_eq(1, count(bwd_flows_files($fall, $fall + 1800, $fall + 7200, $dstDir)), 'first occurrence of oldest DST hour remains selectable');
$r = bwd_destinations('0.0.0.0', 1, $fall, $fall + 7199, array(), 100, $dstDir, $fall + 7200);
t_eq(300.0, $r['total_in'] + 0.0, 'DST shared file contributes its bytes once');
unlink(bwd_flows_hour_file($fall, $dstDir));
rmdir("$dstDir/h"); rmdir($dstDir);
date_default_timezone_set($tz);

// An invalid input must not finalise a partial day or destroy its good hours.
$bad = bwd_flows_hour_file($old + 3600, $dir);
$good = bwd_flows_hour_file($old, $dir);
bwd_atomic_write($good, bwd_json($mk('survivor.example', 321)));
bwd_atomic_write($bad, '{broken');
t_eq(null, bwd_flows_load($bad), 'invalid bucket is distinct from missing');
t_eq(array('v' => 1, 'hosts' => array()), bwd_flows_load("$dir/absent.json"), 'missing bucket is empty');
t_eq(0, bwd_flows_compact($now, 100, 31, $dir), 'invalid hourly input prevents daily finalisation');
t_ok(!file_exists(bwd_flows_day_file($old, $dir)) && is_file($good), 'invalid day keeps good hours and has no daily file');
$r = bwd_destinations('0.0.0.0', 1, $old, $old + 7200, array(), 100, $dir, $now);
t_eq(321.0, $r['total_in'] + 0.0, 'old day without daily file falls back to surviving hours');
unlink($bad);
mkdir($bad); // A directory is an existing but unreadable bucket on every test uid.
t_eq(null, bwd_flows_load($bad), 'unreadable bucket is not empty');
t_eq(0, bwd_flows_compact($now, 100, 31, $dir), 'unreadable hourly input prevents daily finalisation');
rmdir($bad);
unlink($good);

// Failed state persistence cannot commit a delta which the next poll replays.
$commitDir = "$dir/commit";
mkdir($commitDir);
mkdir("$commitDir/state.json");
$err = bwd_flows_commit($now, $st2, $mk('commit.example', 50), 100, $commitDir);
t_ok(strpos($err, 'could not write') === 0, 'state-write failure is returned');
t_ok(!file_exists(bwd_flows_hour_file($now, $commitDir)), 'state-write failure must not commit bucket');
rmdir("$commitDir/state.json");
// Isolate the next failure even when a mutation incorrectly wrote the bucket.
if (is_file(bwd_flows_hour_file($now, $commitDir))) { unlink(bwd_flows_hour_file($now, $commitDir)); }
if (is_dir("$commitDir/h")) { rmdir("$commitDir/h"); }
// Block the hour directory: state must already be durable when bucket writing fails.
bwd_atomic_write("$commitDir/h", 'blocked');
$err = bwd_flows_commit($now, $st2, $mk('commit.example', 50), 100, $commitDir);
$durable = json_decode((string) @file_get_contents("$commitDir/state.json"), true);
t_eq($now, $durable['ts'] ?? null, 'state precedes bucket write, closing the replay crash window');
t_ok($err !== '', 'bucket-write failure is returned');
$recover = $f1b;
$recover['bytes']['srv_bytes'] = 9020;
list(, $recovered) = bwd_flows_ingest($durable['flows'] ?? array(), $durable['ts'] ?? 0, array($recover), $isLocal, $idOf, $now + 60);
t_eq(20.0, $recovered['hosts']['aa:bb:cc:00:00:10']['d']['video.example.com'][0] ?? null, 'recovery after interrupted commit counts only new growth');
unlink("$commitDir/h");
$hour = bwd_flows_hour_file($now, $commitDir);
bwd_atomic_write($hour, '{broken');
$before = file_get_contents("$commitDir/state.json");
$err = bwd_flows_commit($now + 1, array(), $mk('commit.example', 50), 100, $commitDir);
t_ok(strpos($err, 'could not read') === 0, 'collector refuses invalid existing bucket');
t_eq('{broken', file_get_contents($hour), 'collector does not overwrite invalid bucket');
t_eq($before, file_get_contents("$commitDir/state.json"), 'read failure does not advance state');
unlink($hour);
mkdir($hour);
t_ok(strpos(bwd_flows_commit($now, array(), $mk('x', 1), 100, $commitDir), 'could not read') === 0, 'collector refuses unreadable existing bucket');
rmdir($hour);

// B's steady 90 bytes must accumulate past the 99 initial 100-byte leaders.
$rank = array('v' => 1, 'hosts' => array('m' => array('d' => array(), 'a' => array())));
for ($i = 0; $i < 99; $i++) { $rank['hosts']['m']['d']['leader' . $i] = array(100, 0, 'TLS'); }
$rank['hosts']['m']['d']['C'] = array(1, 0, 'TLS');
bwd_flows_commit($now, array(), $rank, 100, $commitDir);
$steady = array('hosts' => array('m' => array('d' => array('B' => array(90, 0, 'TLS')), 'a' => array())));
for ($i = 0; $i < 10; $i++) { bwd_flows_commit($now, array(), $steady, 100, $commitDir); }
$r = bwd_destinations('0.0.0.0', 1, $now - 1, $now, array(), 100, $commitDir, $now);
t_eq('B', $r['dests'][0]['name'], 'hourly headroom preserves steady destination ranking');
t_eq(900.0, $r['dests'][0]['in'] + 0.0, 'steady destination retains all ten polls');
// Daily ranking must also wait for every hour: B starts below the leaders.
bwd_atomic_write($hour, bwd_json($rank));
bwd_flows_commit($now, array(), $steady, 100, $commitDir);
$steady['hosts']['m']['d']['B'][0] = 810;
bwd_flows_commit($now + 3600, array(), $steady, 100, $commitDir);
bwd_flows_compact($now + 86400, 100, 31, $commitDir);
$daily = bwd_flows_load(bwd_flows_day_file($now, $commitDir));
t_eq(100, count($daily['hosts']['m']['d']), 'daily compaction applies strict cap after accumulation');
t_eq(900.0, $daily['hosts']['m']['d']['B'][0] + 0.0, 'daily ranking accumulates all hours before folding destinations');
array_map('unlink', array_merge(glob("$commitDir/h/*") ?: array(), glob("$commitDir/d/*") ?: array()));
unlink("$commitDir/state.json");
rmdir("$commitDir/h"); rmdir("$commitDir/d"); rmdir($commitDir);

array_map('unlink', array_merge(glob("$dir/h/*") ?: array(), glob("$dir/d/*") ?: array()));
@rmdir("$dir/h"); @rmdir("$dir/d"); @rmdir($dir);
