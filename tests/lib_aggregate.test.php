<?php
/*
 * Copyright 2026 Jeff Nelson
 * Licensed under the Apache License, Version 2.0.
 */
require __DIR__.'/assert.inc';
require __DIR__.'/../files/usr/local/pkg/gamedetect_lib.inc';

$json = file_get_contents(__DIR__.'/fixtures/signatures_test.json');
$sigs = gd_load_signatures($json)['sigs'];
$udp = fn($src, $dst, $port) => ['proto' => 'udp', 'src_ip' => $src, 'src_port' => 50000, 'dst_ip' => $dst, 'dst_port' => $port];
$dns = ['3.120.1.5' => ['names' => ['api.epicgames.com'], 'expires' => 99999]];
$noid = ['mac' => [], 'hostname' => []];
$opts = ['idle_timeout' => 300, 'unknown_list_size' => 10];
$T = 1800000000;
$A = '10.10.3.17';
$B = '10.10.3.18';
$byip = function (array $r, string $ip) {
	foreach ($r['clients'] as $c) {
		if ($c['ip'] === $ip) {
			return $c;
		}
	}
	return null;
};

$r = gd_aggregate([$udp($A, '3.120.1.5', 9017), $udp($A, '3.120.1.5', 9018)], $dns,
    ['mac' => [$A => 'aa:bb:cc:dd:ee:ff'], 'hostname' => [$A => 'pc1']], $sigs, [], $T, $opts);
$c = $byip($r, $A);
assert_eq(1, count($r['clients']), 'aggregate_first_poll count');
assert_eq(date(DATE_ATOM, $T), $c['first_seen'], 'aggregate_first_poll_sets_first_seen first');
assert_eq($c['first_seen'], $c['last_seen'], 'aggregate_first_poll_sets_first_seen last');
assert_eq('fortnite', $c['game_id'], 'aggregate_first_poll game_id');
assert_eq(2, $c['flows'], 'aggregate_first_poll flows');
assert_eq('aa:bb:cc:dd:ee:ff', $c['mac'], 'aggregate_first_poll mac');
assert_eq('pc1', $c['hostname'], 'aggregate_first_poll hostname');
assert_eq(['ip' => '3.120.1.5', 'port' => 9017, 'names' => ['api.epicgames.com']], $c['top_destinations'][0], 'aggregate_first_poll top_destinations');
assert_eq(['ip', 'mac', 'hostname', 'game', 'game_id', 'confidence', 'score', 'candidates', 'evidence', 'flows', 'first_seen', 'last_seen', 'top_destinations'], array_keys($c), 'aggregate_record_shape');

$r2 = gd_aggregate([$udp($A, '3.120.1.5', 9017)], $dns, $noid, $sigs, $r, $T + 10, $opts);
$c2 = $byip($r2, $A);
assert_eq($c['first_seen'], $c2['first_seen'], 'aggregate_carries_first_seen first');
assert_eq(date(DATE_ATOM, $T + 10), $c2['last_seen'], 'aggregate_carries_first_seen_and_updates_last_seen last');
assert_eq(null, $c2['mac'], 'aggregate_identity_null_when_missing mac');
assert_eq(null, $c2['hostname'], 'aggregate_identity_null_when_missing hostname');

$r3 = gd_aggregate([], $dns, $noid, $sigs, $r, $T + 300, $opts);
assert_eq(date(DATE_ATOM, $T), $byip($r3, $A)['last_seen'] ?? null, 'aggregate_expires_idle_client kept within timeout');
$r4 = gd_aggregate([], $dns, $noid, $sigs, $r, $T + 301, $opts);
assert_eq(0, count($r4['clients']), 'aggregate_expires_idle_client gone after timeout');

/* unknown: UDP to a port/ip nothing claims */
$uf = [$udp($A, '8.8.4.4', 40000)];
$p1 = gd_aggregate($uf, [], $noid, $sigs, [], $T, $opts);
assert_eq(0, count($p1['clients']), 'aggregate_unknown_needs_two_polls first poll not listed');
assert_eq([$A], $p1['pending'], 'aggregate_unknown_needs_two_polls pending');
$p2 = gd_aggregate($uf, [], $noid, $sigs, $p1, $T + 10, $opts);
assert_eq('unknown', $byip($p2, $A)['game_id'] ?? null, 'aggregate_unknown_needs_two_polls second poll listed');
assert_eq([], $p2['pending'], 'aggregate_unknown_needs_two_polls pending cleared');

$k = gd_aggregate([$udp($A, '8.8.4.4', 40000), $udp($B, '8.8.4.4', 40000), $udp($B, '9.9.9.9', 41000)], [], $noid, $sigs, [], $T, $opts);
assert_eq(['ip' => '8.8.4.4', 'port' => 40000, 'names' => [], 'clients' => 2], $k['unknown'][0], 'aggregate_unknown_ranking first');
assert_eq(1, $k['unknown'][1]['clients'], 'aggregate_unknown_ranking second');
$k = gd_aggregate([$udp($A, '8.8.4.4', 40000), $udp($B, '9.9.9.9', 41000)], [], $noid, $sigs, [], $T, ['idle_timeout' => 300, 'unknown_list_size' => 1]);
assert_eq(1, count($k['unknown']), 'aggregate_unknown_list_size');

/* listed unknown stays listed and fresh across consecutive polls */
$st = [];
$seq = [];
foreach ([0, 10, 20, 30] as $i => $off) {
	$st = gd_aggregate($uf, [], $noid, $sigs, $st, $T + $off, $opts);
	$seq[] = [$byip($st, $A)['last_seen'] ?? null, $st['pending']];
}
assert_eq([null, [$A]], $seq[0], 'aggregate_unknown_stable poll1');
for ($i = 1; $i < 4; $i++) {
	assert_eq([date(DATE_ATOM, $T + 10 * $i), []], $seq[$i], "aggregate_unknown_stable poll" . ($i + 1));
}

/* kept-from-prev records are stale snapshots */
$kept = $byip(gd_aggregate([], $dns, $noid, $sigs, $r, $T + 100, $opts), $A);
assert_eq(0, $kept['flows'], 'aggregate_kept_record_has_zero_flows flows');
assert_eq('fortnite', $kept['game_id'], 'aggregate_kept_record_has_zero_flows game_id');

/* UDP/443 ruling */
$q = gd_aggregate([$udp($A, '142.250.1.1', 443)], [], $noid, $sigs, [], $T, $opts);
assert_eq([], $q['pending'], 'aggregate_drops_udp_443_unless_signature_claims_it dropped: no pending');
assert_eq([], $q['unknown'], 'aggregate_drops_udp_443_unless_signature_claims_it dropped: no unknown');
$d = json_decode($json, true);
$d['games'][0]['rules'][1]['dst_ports'] = ['443'];
$s443 = gd_load_signatures(json_encode($d))['sigs'];
$q = gd_aggregate([$udp($A, '3.120.1.5', 443)], [], $noid, $s443, [], $T, $opts);
assert_eq('fortnite', $byip($q, $A)['game_id'] ?? null, 'aggregate_drops_udp_443_unless_signature_claims_it claimed: classified');

/* store */
$dir = sys_get_temp_dir() . '/gd-test-' . getmypid();
mkdir($dir);
$f = "$dir/clients.json";
assert_true(gd_write_json_atomic($f, ['a' => [1, 2], 'p' => '/x']), 'write_read_json_roundtrip write');
assert_eq(['a' => [1, 2], 'p' => '/x'], gd_read_json($f), 'write_read_json_roundtrip read');
assert_eq(['clients.json'], array_values(array_diff(scandir($dir), ['.', '..'])), 'write_json_leaves_no_temp');
assert_true(gd_write_json_atomic($f, ['hostname' => "bad\xffname", 'ok' => 'fine']), 'write_json_invalid_utf8_still_writes');
assert_eq(['hostname' => "bad\u{FFFD}name", 'ok' => 'fine'], gd_read_json($f), 'write_json_invalid_utf8_roundtrips_as_U+FFFD');
assert_null(gd_read_json("$dir/missing.json"), 'read_json_missing_returns_null');
file_put_contents($f, '{broken');
assert_null(gd_read_json($f), 'read_json_corrupt_returns_null');
unlink($f);
rmdir($dir);
