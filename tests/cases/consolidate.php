<?php
/* bwd_consolidate_by_mac: rows sharing a MAC merge into one device (summed across
 * its IPs, representative = highest-traffic IP); MAC-less rows stay separate. */
t_group('consolidate');

function _h($ip, $mac, $in, $out) {
	return array('ip' => $ip, 'mac' => $mac, 'in' => (float) $in, 'out' => (float) $out,
		'total' => (float) ($in + $out), 'tcp' => 0.0, 'udp' => 0.0, 'http' => 0.0,
		'p2p' => 0.0, 'icmp' => 0.0, 'ftp' => 0.0);
}

$list = array(
	_h('10.0.0.5', 'aa:bb:cc:dd:ee:ff', 60, 40),    // total 100
	_h('10.0.0.6', 'aa:bb:cc:dd:ee:ff', 200, 100),  // total 300 (higher)
	_h('10.0.0.7', '', 50, 0),                       // no MAC -> stays single
);
$out = bwd_consolidate_by_mac($list);

// one merged device + one single
t_eq(2, count($out), 'two rows out (1 merged + 1 single)');
$merged = null; $single = null;
foreach ($out as $r) { if (($r['mac'] ?? '') === 'aa:bb:cc:dd:ee:ff') { $merged = $r; } else { $single = $r; } }

t_ok($merged !== null, 'merged row present');
t_feq(400, $merged['total'], 'merged total = 100+300');
t_feq(260, $merged['in'], 'merged in');
t_feq(140, $merged['out'], 'merged out');
t_eq('10.0.0.6', $merged['ip'], 'representative ip = highest traffic');
t_eq(array('10.0.0.6', '10.0.0.5'), $merged['ips'], 'ips listed highest-first');

t_ok($single !== null, 'MAC-less row preserved');
t_eq('10.0.0.7', $single['ip'], 'single ip unchanged');
