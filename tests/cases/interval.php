<?php
/* bwd_cdf_interval: the logging interval, as the median gap between the distinct
 * timestamps in the newest slot's tail. Fewer than three distinct timestamps is
 * "unknown" (0) rather than a guess from a single gap. */
t_group('interval');
t_eq(0, bwd_cdf_interval(1), 'fixture has two distinct timestamps -> unknown (0), not 1000');
t_eq(0, bwd_cdf_interval(4), 'no file for the period -> 0');
