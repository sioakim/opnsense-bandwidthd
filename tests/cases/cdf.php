<?php
/* bwd_cdf_accumulate: parsing, windowing, watermark exclusion, sentinel routing,
 * and the protocol sub-slice invariant. Fixture: tests/fixtures/cdf/log.1.0.cdf. */
t_group('cdf');

$hosts = array(); $total = bwd_blank_host('0.0.0.0');
list($min, $max) = bwd_cdf_accumulate($hosts, $total, 1, 0, 0, 0);

t_eq(1000, $min, 'min ts');
t_eq(2000, $max, 'max ts');

// per-IP in/out sums across both buckets
t_feq(150, $hosts['10.0.0.5']['out'], 'host5 out');
t_feq(950, $hosts['10.0.0.5']['in'], 'host5 in');
t_feq(940, $hosts['10.0.0.5']['tcp'], 'host5 tcp (send+recv)');
t_feq(160, $hosts['10.0.0.5']['udp'], 'host5 udp');
t_feq(540, $hosts['10.0.0.5']['http'], 'host5 http');
t_feq(200, $hosts['10.0.0.6']['out'], 'host6 out');
t_feq(10, $hosts['10.0.0.6']['in'], 'host6 in');

// protocol invariant: http is a SUB-slice of tcp (never a peer)
t_ok($hosts['10.0.0.5']['http'] <= $hosts['10.0.0.5']['tcp'], 'http subset of tcp');
// tcp+udp+icmp are disjoint and sum to the in+out total
t_feq(1100, $hosts['10.0.0.5']['tcp'] + $hosts['10.0.0.5']['udp'] + $hosts['10.0.0.5']['icmp'],
	'tcp+udp+icmp == total for host5');

// the 0.0.0.0 sentinel is the interface total, never a host row
t_ok(!isset($hosts['0.0.0.0']), 'sentinel is not a host');
t_feq(250, $total['out'], 'iface out');
t_feq(60, $total['in'], 'iface in');

// malformed / non-IP lines are skipped
t_ok(!isset($hosts['not-an-ip']), 'non-ip line skipped');
t_ok(!isset($hosts['malformed']), 'short line skipped');

// window: from excludes the ts=1000 bucket
$h = array(); $t = bwd_blank_host('0.0.0.0');
bwd_cdf_accumulate($h, $t, 1, 1500, 0, 0);
t_feq(50, $h['10.0.0.5']['out'], 'from=1500 excludes ts1000');

// watermark (minExcl) excludes ts <= 1000
$h = array(); $t = bwd_blank_host('0.0.0.0');
bwd_cdf_accumulate($h, $t, 1, 0, 0, 1000);
t_feq(50, $h['10.0.0.5']['out'], 'minExcl=1000 excludes ts<=1000');

// to-bound keeps only the ts=1000 bucket
$h = array(); $t = bwd_blank_host('0.0.0.0');
bwd_cdf_accumulate($h, $t, 1, 0, 1500, 0);
t_feq(100, $h['10.0.0.5']['out'], 'to=1500 keeps ts1000');
t_ok(!isset($h['10.0.0.6']), 'to=1500 excludes ts2000 host');
