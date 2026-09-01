<?php
/* bwd_parse_tags slug sanitization + bwd_host_has_tag union (type tag + custom). */
t_group('tags');

t_eq(array('foo', 'bar', 'baz'), bwd_parse_tags('Foo, BAR  baz'), 'lowercase + split + dedup order');
t_eq(array('ab'), bwd_parse_tags('a@#b'), 'strip non-slug chars');
t_eq(array('a-b_c'), bwd_parse_tags('a-b_c'), 'keep - and _');
t_eq(array('x'), bwd_parse_tags('x x X'), 'dedup repeats');
t_eq(array(), bwd_parse_tags('   '), 'empty input -> empty');

$h = array('tag' => 'pc', 'tags' => array('work', 'lab'));
t_ok(bwd_host_has_tag($h, array('pc')), 'matches type tag');
t_ok(bwd_host_has_tag($h, array('work')), 'matches custom tag');
t_ok(bwd_host_has_tag($h, array('phone', 'lab')), 'matches any in selection');
t_ok(!bwd_host_has_tag($h, array('phone', 'iot')), 'no match');
t_ok(!bwd_host_has_tag(array('tag' => '', 'tags' => array()), array('pc')), 'untagged host never matches');
