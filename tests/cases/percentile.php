<?php
/* bwd_percentile / bwd_pct_population: rates from bytes-per-interval, with the
 * silent intervals counted as zero samples. */
t_group('percentile');

// fixture: 10.0.0.5 has out=100 @1000 and out=50 @2000 -> interval 1000 s
$p = bwd_percentile('10.0.0.5', 1, 0, 0, 100);
t_eq(1000, $p['interval'], 'median gap is the interval');
t_feq(0.8, $p['out_bps'], 'max out rate = 100 B * 8 / 1000 s');
t_feq(7.2, $p['in_bps'], 'max in rate = 900 B * 8 / 1000 s');
t_eq(2, $p['samples'], 'sample count reported');

// a gap in the samples is filled with zero-rate samples
$pts = array(
	array('t' => 0,   'in' => 100, 'out' => 0),
	array('t' => 100, 'in' => 100, 'out' => 0),
	array('t' => 400, 'in' => 100, 'out' => 0),   // 200 and 300 were silent
);
list($ins, $outs, $tots) = bwd_pct_population($pts, 100);
t_eq(5, count($ins), 'two silent intervals become zero samples');
t_eq(2, count(array_filter($ins, function ($x) { return $x == 0.0; })), 'exactly two zeros added');
t_feq(8.0, max($ins), 'observed rates unchanged');
t_feq(8.0 * 0.6, array_sum($tots) / count($tots), 'mean reflects the quiet intervals');

// no gaps -> nothing added; fewer than two points -> nothing added
list($a) = bwd_pct_population(array($pts[0], $pts[1]), 100);
t_eq(2, count($a), 'contiguous samples are not padded');
list($b) = bwd_pct_population(array($pts[0]), 100);
t_eq(1, count($b), 'single sample untouched');
