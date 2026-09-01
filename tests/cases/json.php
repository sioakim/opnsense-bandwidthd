<?php
/* bwd_json: device bytes must never turn a state file or export into false. */
t_group('json');

$latin1 = array('title' => "Caf\xe9 printer");
t_eq(false, json_encode($latin1), 'plain json_encode fails on Latin-1 (the reason the helper exists)');
$j = bwd_json($latin1);
t_ok(is_string($j), 'bwd_json returns a string for invalid UTF-8');
t_eq("Caf\xef\xbf\xbd printer", json_decode($j, true)['title'], 'invalid byte becomes U+FFFD');
t_eq('{"a":1}', bwd_json(array('a' => 1)), 'valid input unchanged');
t_ok(strpos(bwd_json(array('a' => 1), JSON_PRETTY_PRINT), "\n") !== false, 'extra flags are honoured');
