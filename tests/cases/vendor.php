<?php
/* bwd_vendor: OUI lookup, randomized (locally-administered) MAC detection, and the
 * short-MAC guard. The OUI assertion reads the bundled oui.tsv's first row so it
 * stays correct as the table is refreshed. */
t_group('vendor');

// randomized / locally-administered MAC: 2nd-least-significant bit of octet 1 set
$r = bwd_vendor('02:11:22:33:44:55');
t_ok($r['randomized'] === true, 'locally-administered MAC flagged randomized');
t_eq('', $r['vendor'], 'randomized MAC has no OUI vendor');

// a normal universally-administered MAC is not randomized
$r = bwd_vendor('00:11:22:33:44:55');
t_ok($r['randomized'] === false, 'universal MAC not randomized');

// too-short MAC -> empty result, no crash
$r = bwd_vendor('00:11');
t_eq('', $r['vendor'], 'short mac -> empty vendor');
t_eq('', $r['oui'], 'short mac -> empty oui');

// OUI lookup against the bundled table (first row, whatever it is)
$ouiFile = realpath(__DIR__ . '/../../plugin/src/opnsense/scripts/OPNsense/Bandwidthd/lib/data/oui.tsv');
// Fail loud rather than silently skipping: a moved OUI table would otherwise
// make this whole case a no-op and the classifier's headline signal untested.
t_ok($ouiFile && is_file($ouiFile), 'bundled oui.tsv is present');
if ($ouiFile && is_file($ouiFile)) {
	$first = fgets(fopen($ouiFile, 'r'));
	$cols = explode("\t", trim($first));
	// These used to be silent guards, which let the assertions below pass vacuously.
	t_ok(count($cols) >= 2 && strlen($cols[0]) === 6, 'first oui.tsv row is <6 hex>\t<vendor>');
	if (count($cols) >= 2 && strlen($cols[0]) === 6) {
		$mac = strtolower(substr($cols[0], 0, 2) . ':' . substr($cols[0], 2, 2) . ':' . substr($cols[0], 4, 2)) . ':00:00:01';
		$v = bwd_vendor($mac);
		t_ok(!$v['randomized'], 'synthesised MAC from the first OUI is not locally administered');
		t_eq($cols[1], $v['vendor'], 'OUI ' . $cols[0] . ' resolves to ' . $cols[1]);
		t_eq(strtoupper($cols[0]), $v['oui'], 'OUI hex parsed');
	}
}
