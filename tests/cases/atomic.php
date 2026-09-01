<?php
/* bwd_atomic_write: durable replace via temp+rename, leaves no temp debris. */
t_group('atomic');

$dir = sys_get_temp_dir() . '/bwd_atomic_' . getmypid();
@mkdir($dir, 0755, true);
$f = "$dir/state.json";

t_ok(bwd_atomic_write($f, '{"a":1}'), 'write returns true');
t_eq('{"a":1}', file_get_contents($f), 'content written');

t_ok(bwd_atomic_write($f, '{"a":2}'), 'overwrite returns true');
t_eq('{"a":2}', file_get_contents($f), 'content replaced');

// no leftover temp files in the directory (rename consumed them)
$leftovers = array_filter((array) scandir($dir), function ($n) { return strpos($n, '.bwd') === 0; });
t_eq(0, count($leftovers), 'no temp-file debris left behind');

// writing into a not-yet-existing subdir creates it
$f2 = "$dir/sub/deep/state.json";
t_ok(bwd_atomic_write($f2, 'x'), 'creates missing parent dirs');
t_ok(is_file($f2), 'nested file exists');

@unlink($f); @unlink($f2); @rmdir("$dir/sub/deep"); @rmdir("$dir/sub"); @rmdir($dir);
