<?php
/* bwd_pctile (linear interpolation, edge cases), bwd_bucket_for, bwd_default_window,
 * bwd_ip_in_cidr. */
t_group('math');

// percentile
t_feq(0.0, bwd_pctile(array(), 95), 'pctile empty -> 0');
t_feq(5.0, bwd_pctile(array(5), 95), 'pctile single');
t_feq(38.5, bwd_pctile(array(10, 20, 30, 40), 95), 'pctile p95 interpolated');
t_feq(40.0, bwd_pctile(array(10, 20, 30, 40), 100), 'pctile p100 = max');
t_feq(10.0, bwd_pctile(array(40, 30, 20, 10), 0), 'pctile p0 = min (sorts first)');
t_feq(25.0, bwd_pctile(array(10, 20, 30, 40), 50), 'pctile p50 = median');

// bucket sizing: floors at 60s, ~target points
t_ok(bwd_bucket_for(86400, 300) >= 60, 'bucket >= 60s');
t_eq(60, bwd_bucket_for(60, 300), 'tiny span floors at 60');

// default windows
t_eq(86400, bwd_default_window(1), 'daily window');
t_eq(604800, bwd_default_window(2), 'weekly window');
t_eq(31536000, bwd_default_window(4), 'yearly window');

// CIDR membership
t_ok(bwd_ip_in_cidr('10.0.0.5', '10.0.0.0/24'), 'in /24');
t_ok(!bwd_ip_in_cidr('10.0.1.5', '10.0.0.0/24'), 'outside /24');
t_ok(bwd_ip_in_cidr('1.2.3.4', '0.0.0.0/0'), '/0 matches all');
t_ok(bwd_ip_in_cidr('10.0.0.5', '10.0.0.5'), 'bare ip exact match');
t_ok(!bwd_ip_in_cidr('10.0.0.6', '10.0.0.5'), 'bare ip non-match');
