<?php
/*
 * Copyright 2026 Jeff Nelson
 * Licensed under the Apache License, Version 2.0.
 */
require __DIR__.'/assert.inc';
require __DIR__.'/../files/usr/local/pkg/gamedetect_lib.inc';

$out = file_get_contents(__DIR__.'/fixtures/pfctl_ss.txt');
$cidrs = ['10.10.3.0/24'];
$local = ['203.0.113.9'];

function flow(string $p, string $si, int $sp, string $di, int $dp): array {
	return ['proto' => $p, 'src_ip' => $si, 'src_port' => $sp, 'dst_ip' => $di, 'dst_port' => $dp];
}

$got = gd_parse_states($out, $cidrs, $local);
assert_eq([
	flow('udp', '10.10.3.17', 51234, '3.120.1.5', 9017),
	flow('tcp', '10.10.3.17', 40000, '1.2.3.4', 443),
	flow('udp', '10.10.3.17', 50012, '1.2.3.4', 443),
	flow('udp', '10.10.3.18', 52000, '5.6.7.8', 27015),
	flow('udp', '10.10.3.20', 50030, '5.6.7.9', 27015),
], $got, 'parse_states_basic');

$nat = gd_parse_states("igc0 udp 203.0.113.9:52000 (10.10.3.18:52000) -> 5.6.7.8:27015 (9.9.9.9:27016)       MULTIPLE:MULTIPLE\n", $cidrs, $local);
assert_eq([flow('udp', '10.10.3.18', 52000, '5.6.7.8', 27015)], $nat, 'parse_states_nat_both_sides');
$nat2 = gd_parse_states("igc0 udp 203.0.113.9:1 (10.10.3.18:2) -> 5.6.7.8:3 (9.9.9.9:4)\n", $cidrs, $local);
assert_eq([flow('udp', '10.10.3.18', 2, '5.6.7.8', 3)], $nat2, 'parse_states_nat_inner_src');

$keys = array_map(fn($f) => "{$f['proto']} {$f['dst_ip']}:{$f['dst_port']}", $got);
assert_true(!in_array('tcp 1.2.3.4:80', $keys, true), 'non-443 tcp dropped');
assert_true(in_array('udp 1.2.3.4:443', $keys, true), 'udp 443 kept by parser');
foreach ($got as $f) {
	assert_true(strpos($f['dst_ip'], ':') === false && strpos($f['src_ip'], ':') === false, 'no v6');
	assert_true(!in_array($f['src_ip'], ['10.10.4.5'], true), 'non-attendee src dropped');
	assert_true($f['dst_ip'] !== '203.0.113.9' && $f['dst_ip'] !== '10.99.0.1' && $f['dst_ip'] !== '10.10.3.1', 'ignored dst dropped');
	assert_true(!in_array($f['dst_port'], [53, 67, 68, 123, 3478], true), 'ignored port dropped');
}
assert_eq([], gd_parse_states("igc0 udp 1.2.3.4:9017 <- 203.0.113.9:51234  MULTIPLE:MULTIPLE\n", ['0.0.0.0/0'], []), 'inbound dropped');
assert_eq([], gd_parse_states('', $cidrs, $local), 'empty input');

assert_true(gd_ip_in_cidrs('10.0.0.5', ['10.0.0.5/32']), 'cidr /32 match');
assert_true(!gd_ip_in_cidrs('10.0.0.6', ['10.0.0.5/32']), 'cidr /32 miss');
assert_true(gd_ip_in_cidrs('10.255.255.255', ['10.0.0.0/8']), 'cidr /8 top');
assert_true(gd_ip_in_cidrs('10.0.0.0', ['10.0.0.0/8']), 'cidr /8 bottom');
assert_true(!gd_ip_in_cidrs('11.0.0.0', ['10.0.0.0/8']), 'cidr /8 above');
assert_true(!gd_ip_in_cidrs('9.255.255.255', ['10.0.0.0/8']), 'cidr /8 below');
assert_true(gd_ip_in_cidrs('1.2.3.4', ['0.0.0.0/0']), 'cidr /0');
assert_true(gd_ip_in_cidrs('1.2.3.4', ['9.9.9.9/32', '1.2.3.0/24']), 'cidr list');
assert_true(gd_ip_in_cidrs('1.2.3.4', ['1.2.3.4']), 'bare ip = /32');
assert_true(!gd_ip_in_cidrs('1.2.3.4', []), 'empty list');
assert_true(!gd_ip_in_cidrs('nonsense', ['0.0.0.0/0']), 'invalid ip');
