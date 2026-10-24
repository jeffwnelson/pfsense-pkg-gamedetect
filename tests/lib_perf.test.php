<?php
/*
 * Copyright 2026 Jeff Nelson
 * Licensed under the Apache License, Version 2.0.
 *
 * Classifier cost at the design point: 700 clients x (30 UDP + 10 TCP) flows,
 * default signatures, a 2k-entry DNS map. One gd_aggregate must finish well
 * inside a poll (target <= 0.5 s on a dev laptop; the 1.5 s bound is CI slack).
 */
require __DIR__.'/assert.inc';
require __DIR__.'/../files/usr/local/pkg/gamedetect_lib.inc';

$sigs = gd_load_signatures(file_get_contents(__DIR__.'/../files/usr/local/share/pfSense-pkg-gamedetect/signatures.default.json'))['sigs'];
mt_srand(1);
$rand_ip = fn() => mt_rand(1, 223) . '.' . mt_rand(0, 255) . '.' . mt_rand(0, 255) . '.' . mt_rand(1, 254);
/* A few real game destinations so the port and prefix paths both run. */
$game = [['185.40.64.10', 7100], ['162.249.72.9', 5100], ['155.133.230.4', 27050], ['24.105.29.40', 26500], ['3.120.1.5', 9017]];
$flows = [];
$dsts = [];
for ($c = 0; $c < 700; $c++) {
	$src = '10.10.' . intdiv($c, 250) . '.' . ($c % 250 + 1);
	for ($i = 0; $i < 30; $i++) {
		[$ip, $port] = $i === 0 && $c % 3 === 0 ? $game[$c % count($game)] : [$rand_ip(), mt_rand(1024, 65535)];
		$flows[] = ['proto' => 'udp', 'src_ip' => $src, 'src_port' => 40000 + $i, 'dst_ip' => $ip, 'dst_port' => $port];
		$dsts[] = $ip;
	}
	for ($i = 0; $i < 10; $i++) {
		$flows[] = ['proto' => 'tcp', 'src_ip' => $src, 'src_port' => 50000 + $i, 'dst_ip' => $rand_ip(), 'dst_port' => 443];
	}
}
$dns = [];
foreach (array_slice($dsts, 0, 1000) as $i => $ip) {
	$dns[$ip] = ['names' => ["host$i.example.com"], 'expires' => PHP_INT_MAX];
}
while (count($dns) < 2000) {
	$dns[$rand_ip()] = ['names' => ['cdn' . count($dns) . '.example.net'], 'expires' => PHP_INT_MAX];
}
$dns['3.120.1.5'] = ['names' => ['x.ol.epicgames.com'], 'expires' => PHP_INT_MAX];

$t = hrtime(true);
$r = gd_aggregate($flows, $dns, ['mac' => [], 'hostname' => []], $sigs, [], 1800000000, ['idle_timeout' => 120, 'unknown_list_size' => 50]);
$ms = (hrtime(true) - $t) / 1e6;
printf("perf: gd_aggregate 700 clients x 40 flows (%d flows, %d dns): %.0f ms\n", count($flows), count($dns), $ms);
assert_true($ms < 1500, sprintf('aggregate_700x40_under_1500ms (%.0f ms)', $ms));
assert_true(count($r['clients']) > 0 && count($r['unknown']) === 50, 'aggregate_700x40_shape');
$ids = array_count_values(array_column($r['clients'], 'game_id'));
assert_true(($ids['fortnite'] ?? 0) > 0, 'aggregate_700x40_classifies_fortnite');
