<?php
/* Exercise the production entry point's failure reporting and write boundaries. */
t_group('flows collector');
$dir = sys_get_temp_dir() . '/bwd_collector_' . getmypid();
$run = function ($error = '', $dry = false) use ($dir) {
	$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../fixtures/flows_runner.php') .
		' ' . escapeshellarg($dir) . ' ' . escapeshellarg($error) . ($dry ? ' --dry-run' : '') . ' 2>&1';
	exec($cmd, $output, $exit);
	$GLOBALS['collector_output'] = implode("\n", $output);
	return $exit;
};
t_eq(0, $run('', true), 'dry run executes successful fetch without persistence');
t_ok(!file_exists($dir), 'dry run creates neither directory nor lock');
mkdir($dir);
mkdir("$dir/state.json");
t_eq(1, $run(), 'state-write failure fails the collector');
$status = bwd_flows_status($dir);
t_ok(!$status['ok'] && strpos($status['error'], '/state.json') !== false, 'state-write failure appears in status');
rmdir("$dir/state.json");
$hour = bwd_flows_hour_file(time(), $dir);
bwd_atomic_write($hour, '{broken');
t_eq(1, $run(), 'invalid current bucket fails the collector');
$status = bwd_flows_status($dir);
t_ok(!$status['ok'] && strpos($status['error'], 'could not read') === 0, 'bucket read failure appears in status');
t_eq('{broken', file_get_contents($hour), 'failed collection preserves unreadable bucket bytes');
unlink($hour);
bwd_atomic_write("$dir/state.json", bwd_json(array('ts' => time() - 60, 'flows' => array())));
t_eq(0, $run(), 'old state format upgrades successfully');
t_ok(strpos($GLOBALS['collector_output'], 'baseline poll') !== false, 'port/protocol key upgrade baselines to avoid lifetime replay');
$state = json_decode(file_get_contents("$dir/state.json"), true);
t_eq(2, $state['v'], 'new key format is marked in durable state');
unlink($hour);
$old = bwd_flows_day_file(time() - 60 * 86400, $dir);
bwd_atomic_write($old, bwd_json(array('v' => 1, 'hosts' => array())));
$expiredHour = bwd_flows_hour_file(time() - 61 * 86400, $dir);
bwd_atomic_write($expiredHour, '{broken');
t_eq(1, $run('fixture fetch failure'), 'fetch failure fails the collector');
t_ok(!file_exists($expiredHour), 'fetch failure expires even hours whose compaction cannot succeed');
t_ok(!is_file($old), 'fetch failure still expires retained history');
t_eq('fixture fetch failure', bwd_flows_status($dir)['error'], 'fetch error survives housekeeping in status');
array_map('unlink', glob("$dir/*.*") ?: array());
rmdir("$dir/h"); rmdir("$dir/d"); rmdir($dir);
