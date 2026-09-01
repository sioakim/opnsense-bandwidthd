<?php
/* bwd_classify accuracy floor on the labeled fixture. Guards against a heuristic
 * data edit (data/*_tags.php) silently regressing classification. Off-box the
 * config-dependent signals (overrides, subnet rules) are stubbed empty, so this
 * exercises the hostname + vendor fusion only. */
t_group('classify');

$file = __DIR__ . '/../fixtures/labeled.tsv';
$total = 0; $correct = 0;
foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
	if ($line === '' || $line[0] === '#') { continue; }
	$c = explode("\t", $line);
	$exp = strtolower(trim($c[0] ?? ''));
	if ($exp === '') { continue; }
	$name = $c[1] ?? ''; $vendor = $c[2] ?? '';
	$total++;
	$res = bwd_classify('', '', $name, $vendor);
	if (($res['tag'] ?? '') === $exp) { $correct++; }
}
t_ok($total > 0, 'labeled sample loaded');
$acc = $total ? $correct / $total : 0;
t_ok($acc >= 0.85, sprintf('classifier accuracy %.0f%% >= 85%% (%d/%d)', $acc * 100, $correct, $total));
