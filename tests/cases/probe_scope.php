<?php
/* bwd_fp_target_allowed (#32): the active-probe gate.
 *
 * Scope is what bandwidthd is configured to monitor — the selected interfaces'
 * subnets plus subnets_extra — and NOT all of RFC1918. The wider gate the code
 * used to have turned the probe privilege into an nmap primitive against any
 * private host the firewall could route to, which is a different power from
 * "identify the devices on my monitored LAN".
 *
 * Off-box the platform layer resolves no interfaces, so subnets_extra is the
 * injection point for the configured scope. */
t_group('probe_scope');

if (!function_exists('bwd_fp_target_allowed')) {
	t_ok(false, 'bwd_fp_target_allowed is defined (fingerprint module loaded)');
	return;
}

/* --- nothing configured: refuse everything (a scanner must fail closed) --- */
$GLOBALS['bwd_test_cfg']['subnets_extra'] = '';
t_ok(!bwd_fp_target_allowed('10.0.0.58'), 'no configured scope -> refuse 10/8');
t_ok(!bwd_fp_target_allowed('192.168.1.20'), 'no configured scope -> refuse 192.168/16');
t_ok(!bwd_fp_target_allowed('172.16.5.5'), 'no configured scope -> refuse 172.16/12');

/* --- a configured subnet allows its own hosts, and only those --- */
$GLOBALS['bwd_test_cfg']['subnets_extra'] = '10.0.0.0/24';
t_ok(bwd_fp_target_allowed('10.0.0.58'), 'allow a host inside the monitored subnet');
t_ok(bwd_fp_target_allowed('10.0.0.1'), 'allow the gateway inside it');
t_ok(!bwd_fp_target_allowed('10.0.1.58'), 'refuse a host just outside the monitored subnet');
t_ok(!bwd_fp_target_allowed('10.99.99.5'), 'refuse other RFC1918 space in the same /8');
t_ok(!bwd_fp_target_allowed('192.168.1.20'), 'refuse a different private range entirely');

/* --- several subnets, as an operator with more than one monitored network --- */
$GLOBALS['bwd_test_cfg']['subnets_extra'] = '10.0.0.0/24, 192.168.50.0/24';
t_ok(bwd_fp_target_allowed('192.168.50.7'), 'allow a host in a second configured subnet');
t_ok(bwd_fp_target_allowed('10.0.0.7'), 'still allow the first subnet');
t_ok(!bwd_fp_target_allowed('192.168.51.7'), 'refuse the adjacent /24');

/* --- SSRF / scan targets are refused whatever is configured --- */
$GLOBALS['bwd_test_cfg']['subnets_extra'] = '10.0.0.0/24';
t_ok(!bwd_fp_target_allowed('127.0.0.1'), 'block loopback');
t_ok(!bwd_fp_target_allowed('169.254.169.254'), 'block link-local cloud metadata');
t_ok(!bwd_fp_target_allowed('8.8.8.8'), 'block public internet');
t_ok(!bwd_fp_target_allowed('172.32.0.1'), 'block 172.32 (outside 172.16/12)');
t_ok(!bwd_fp_target_allowed('not-an-ip'), 'block malformed');
t_ok(!bwd_fp_target_allowed(''), 'block empty');

/* An operator cannot widen the gate to everything by declaring 0.0.0.0/0 and
 * accidentally re-open the metadata/loopback targets the gate exists to block. */
$GLOBALS['bwd_test_cfg']['subnets_extra'] = '0.0.0.0/0';
t_ok(!bwd_fp_target_allowed('169.254.169.254'), 'a 0.0.0.0/0 rule still does not reach link-local metadata');
t_ok(!bwd_fp_target_allowed('127.0.0.1'), 'a 0.0.0.0/0 rule still does not reach loopback');

$GLOBALS['bwd_test_cfg']['subnets_extra'] = '';
