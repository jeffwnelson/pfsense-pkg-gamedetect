<?php
/*
 * Copyright 2026 Jeff Nelson
 * Licensed under the Apache License, Version 2.0.
 */
require __DIR__.'/assert.inc';
require __DIR__.'/../files/usr/local/pkg/gamedetect_lib.inc';

$json = file_get_contents(__DIR__.'/fixtures/signatures_test.json');
$r = gd_load_signatures($json);
assert_true($r['ok'] ?? false, 'load_signatures_ok');
$sigs = $r['sigs'] ?? [];

$base = json_decode($json, true);
$bad = function (callable $mut, string $needle, string $name) use ($base) {
	$d = $base;
	$mut($d);
	$res = gd_load_signatures(json_encode($d));
	assert_true(($res['ok'] ?? true) === false && strpos($res['error'] ?? '', $needle) !== false, $name . ' (' . ($res['error'] ?? 'no error') . ')');
};
$bad(function (&$d) { unset($d['games'][1]['publisher']); }, 'games[1].publisher', 'load_signatures_rejects_missing_publisher');
$bad(function (&$d) { $d['games'][3]['rules'][1]['set'] = 'nope'; }, 'games[3].rules[1].set', 'load_signatures_rejects_unknown_set');
$bad(function (&$d) { $d['games'][2]['rules'][0]['weight'] = 0; }, 'games[2].rules[0].weight', 'load_signatures_rejects_weight_zero');
$bad(function (&$d) { $d['games'][2]['rules'][1]['dst_ports'] = ['x']; }, 'games[2].rules[1].dst_ports', 'load_signatures_rejects_bad_range');
$bad(function (&$d) { $d['version'] = 2; }, 'version', 'load_signatures_rejects_version');
$bad(function (&$d) { $d['thresholds'] = ['high' => 10, 'medium' => 50, 'low' => 30]; }, 'thresholds', 'load_signatures_rejects_threshold_order');
$bad(function (&$d) { $d['games'][1]['id'] = 'fortnite'; }, 'games[1].id', 'load_signatures_rejects_dup_id');
$bad(function (&$d) { $d['bogus'] = 1; }, 'bogus', 'load_signatures_rejects_unknown_top_key');
$bad(function (&$d) { $d['prefix_sets'] = [['3.120.0.0/16']]; }, 'prefix_sets', 'load_signatures_rejects_list_prefix_sets');
$bad(function (&$d) { $d['prefix_sets']['epic'] = ['a' => '3.120.0.0/16']; }, 'prefix_sets.epic', 'load_signatures_rejects_object_prefix_set_value');
$bad(function (&$d) { $d['games'][0]['rules'][1]['weight'] = '30'; }, 'games[0].rules[1].weight', 'load_signatures_rejects_string_weight');
$bad(function (&$d) { $d['prefix_sets']['epic'] = ['3.120.0.0/33']; }, 'prefix_sets.epic[0]', 'load_signatures_rejects_bad_cidr');
$bad(function (&$d) { $d['games'][3]['dns_optional'] = 'yes'; }, 'games[3].dns_optional', 'load_signatures_rejects_non_bool_dns_optional');
$bad(function (&$d) { $d['games'][3]['dns_optional'] = null; }, 'games[3].dns_optional', 'load_signatures_rejects_non_bool_dns_optional null');
$srcgame = ['id' => 'srcgame', 'name' => 'Source game', 'publisher' => 'source', 'rules' => [
    ['type' => 'src_port', 'src_ports' => ['27005'], 'weight' => 40],
    ['type' => 'port', 'dst_ports' => ['27015-27050'], 'weight' => 15],
]];
$bad(function (&$d) use ($srcgame) { $srcgame['rules'][0]['src_ports'] = ['x']; $d['games'][] = $srcgame; }, 'games[4].rules[0].src_ports', 'load_signatures_rejects_bad_src_ports bad range');
$bad(function (&$d) use ($srcgame) { unset($srcgame['rules'][0]['src_ports']); $d['games'][] = $srcgame; }, 'games[4].rules[0].src_ports', 'load_signatures_rejects_bad_src_ports missing');
$bad(function (&$d) use ($srcgame) { $srcgame['rules'][0]['src_ports'] = []; $d['games'][] = $srcgame; }, 'games[4].rules[0].src_ports', 'load_signatures_rejects_bad_src_ports empty');
$bad(function (&$d) use ($srcgame) { $srcgame['rules'][0]['src_ports'] = ['70000']; $d['games'][] = $srcgame; }, 'games[4].rules[0].src_ports', 'load_signatures_rejects_bad_src_ports out of range');
assert_true(($r = gd_load_signatures('{nope'))['ok'] === false, 'load_signatures_rejects_bad_json');

$udp = fn($ip, $port) => ['proto' => 'udp', 'src_ip' => '10.10.3.17', 'src_port' => 50000, 'dst_ip' => $ip, 'dst_port' => $port];
$tcp = fn($ip) => ['proto' => 'tcp', 'src_ip' => '10.10.3.17', 'src_port' => 50001, 'dst_ip' => $ip, 'dst_port' => 443];
$dns = ['3.120.1.5' => ['names' => ['api.epicgames.com'], 'expires' => 9999]];

$c = gd_classify_client([$udp('3.120.1.5', 9017)], $dns, $sigs);
assert_eq('fortnite', $c['game_id'], 'classify_fortnite_high id');
assert_eq('Fortnite', $c['game'], 'classify_fortnite_high name');
assert_eq('high', $c['confidence'], 'classify_fortnite_high confidence');
assert_eq(100, $c['score'], 'classify_fortnite_high score');
assert_true(in_array('dns:*.epicgames.com', $c['evidence'], true), 'classify_fortnite_high evidence dns');
assert_true(in_array('port:9000-9100', $c['evidence'], true), 'classify_fortnite_high evidence port');
assert_true(in_array('prefix:epic', $c['evidence'], true), 'classify_fortnite_high evidence prefix');

$c = gd_classify_client([$udp('3.120.1.5', 9017)], [], $sigs);
assert_eq('fortnite', $c['game_id'], 'classify_flow_rules_only id');
assert_eq(40, $c['score'], 'classify_flow_rules_only score');
assert_eq('low', $c['confidence'], 'classify_flow_rules_only confidence');

/* Port 9000 is in both Fortnite 9000-9100 and Rocket League 7000-9000; DNS names both titles so each scores 100 (capped to medium). */
$c = gd_classify_client([$udp('3.120.1.5', 9000)], ['3.120.1.5' => ['names' => ['epicgames.com', 'x.psyonix.com'], 'expires' => 9999]], $sigs);
assert_eq('epic:ambiguous', $c['game_id'], 'classify_ambiguous id');
assert_eq('Epic (Fortnite or Rocket League)', $c['game'], 'classify_ambiguous name');
assert_eq('medium', $c['confidence'], 'classify_ambiguous confidence');
assert_eq(2, count($c['candidates']), 'classify_ambiguous candidates');
assert_true(in_array('port:7000-9000', $c['evidence'], true) && in_array('port:9000-9100', $c['evidence'], true), 'classify_ambiguous evidence both');
$c = gd_classify_client([$udp('3.120.1.5', 9000)], [], $sigs);
assert_eq('epic:ambiguous', $c['game_id'], 'classify_ambiguous_flow_only id');
assert_eq('low', $c['confidence'], 'classify_ambiguous_flow_only stays low');

$c = gd_classify_client([$tcp('3.120.1.5')], $dns, $sigs);
assert_eq('unknown', $c['game_id'], 'classify_tcp_only_is_unknown');
assert_eq(0, $c['score'], 'classify_tcp_only score');

/* UDP to 7500 on a Riot IP matches valorant flow (40) and rocketleague port (30); Epic has nothing else. Different publishers within 10. */
$c = gd_classify_client([$udp('162.249.72.9', 7500)], [], $sigs);
assert_eq('unknown', $c['game_id'], 'classify_different_publishers_tie_is_unknown');
assert_eq(2, count($c['candidates']), 'classify_tie_candidates');
assert_eq('valorant', $c['candidates'][0]['id'], 'classify_tie_sorted_desc');

$c = gd_classify_client([], [], $sigs);
assert_eq(['game_id' => 'unknown', 'game' => 'unknown', 'confidence' => 'unknown', 'score' => 0, 'candidates' => [], 'evidence' => []], $c, 'classify_no_flows');

$bp = $base;
$bp['publishers'] = ['epic' => 'Epic Games'];
$s2 = gd_load_signatures(json_encode($bp))['sigs'];
$c = gd_classify_client([$udp('3.120.1.5', 9000)], [], $s2);
assert_eq('Epic Games (Fortnite or Rocket League)', $c['game'], 'classify_publisher_display_name');

/* Fortnite 25+10=35, Rocket League 16+10=26 at port 9000: second is below low (30), so top wins outright. */
$bs = $base;
$bs['games'][0]['rules'][1]['weight'] = 25;
$bs['games'][1]['rules'][1]['weight'] = 16;
$s3 = gd_load_signatures(json_encode($bs))['sigs'];
$c = gd_classify_client([$udp('3.120.1.5', 9000)], [], $s3);
assert_eq('fortnite', $c['game_id'], 'classify_second_below_low_is_not_ambiguous id');
assert_eq(35, $c['score'], 'classify_second_below_low_is_not_ambiguous score');
assert_eq('low', $c['confidence'], 'classify_second_below_low_is_not_ambiguous confidence');

/* classify_src_port_rule: src_port matches the flow's (pre-NAT) source port and sums with other flow rules on the same flow. */
$bsrc = $base;
$bsrc['games'][] = $srcgame;
$ls = gd_load_signatures(json_encode($bsrc));
assert_true($ls['ok'] ?? false, 'classify_src_port_rule loads (' . ($ls['error'] ?? '') . ')');
$s4 = $ls['sigs'] ?? $sigs;
$sflow = fn($sport, $ip, $port) => ['proto' => 'udp', 'src_ip' => '10.10.3.17', 'src_port' => $sport, 'dst_ip' => $ip, 'dst_port' => $port];
$c = gd_classify_client([$sflow(27005, '203.0.113.5', 49100)], [], $s4);
assert_eq('srcgame', $c['game_id'], 'classify_src_port_rule src only id');
assert_eq(40, $c['score'], 'classify_src_port_rule src only score');
assert_eq('low', $c['confidence'], 'classify_src_port_rule src only conf');
assert_eq(['src_port:27005'], $c['evidence'], 'classify_src_port_rule evidence');
$c = gd_classify_client([$sflow(27005, '203.0.113.5', 27015)], [], $s4);
assert_eq(55, $c['score'], 'classify_src_port_rule src + dst port same flow sums');
assert_eq('medium', $c['confidence'], 'classify_src_port_rule src + dst port conf');
assert_eq(['src_port:27005', 'port:27015-27050'], $c['evidence'], 'classify_src_port_rule src + dst evidence');
$c = gd_classify_client([$sflow(27005, '203.0.113.5', 49100), $sflow(50000, '203.0.113.6', 27015)], [], $s4);
assert_eq(40, $c['score'], 'classify_src_port_rule different flows do not sum');
$c = gd_classify_client([$sflow(27006, '203.0.113.5', 49100)], [], $s4);
assert_eq('unknown', $c['game_id'], 'classify_src_port_rule other src port no match');
assert_eq([], $c['candidates'], 'classify_src_port_rule other src port no candidate');
$c = gd_classify_client([$sflow(27005, '203.0.113.5', 49100)], [], $sigs);
assert_eq('unknown', $c['game_id'], 'classify_src_port_rule dst port never matches src rule');
