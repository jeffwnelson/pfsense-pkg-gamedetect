<?php
/*
 * Copyright 2026 Jeff Nelson
 * Licensed under the Apache License, Version 2.0.
 */
require __DIR__.'/assert.inc';
require __DIR__.'/../files/usr/local/pkg/gamedetect_lib.inc';

$now = 1000;
$dump = file_get_contents(__DIR__.'/fixtures/unbound_dump_cache.txt');
$map = gd_parse_unbound_cache($dump, $now);

$names = $map['3.120.1.5']['names'] ?? [];
sort($names);
assert_eq(['api.epicgames.com', 'cdn.ol.epicgames.com'], $names, 'unbound_a_and_cname_flattened');
assert_eq($now + 120, $map['3.120.1.5']['expires'] ?? null, 'unbound_expires_is_now_plus_ttl');

$chain = $map['9.9.9.9']['names'] ?? [];
sort($chain);
assert_eq(['final.chain.example', 'hop1.chain.example', 'hop2.chain.example', 'hop3.chain.example'], $chain, 'unbound_three_hop_chain');
assert_true(isset($map['9.9.9.10']), 'unbound_multiple_ips_per_owner');
assert_eq(['mixed.case.example'], $map['8.8.4.4']['names'] ?? [], 'unbound_lowercases');

assert_true(!isset($map['6.6.6.6']), 'unbound_ignores_msg_cache');
assert_eq(4, count($map), 'unbound_ignores_aaaa_ns_dnskey');

$old = ['1.1.1.1' => ['names' => ['old.example'], 'expires' => 500],
    '2.2.2.2' => ['names' => ['keep.example'], 'expires' => 2000],
    '3.3.3.3' => ['names' => ['old3.example'], 'expires' => 3000]];
$new = ['3.3.3.3' => ['names' => ['new3.example'], 'expires' => 4000]];
$merged = gd_merge_dns_maps($old, $new, $now);
assert_true(!isset($merged['1.1.1.1']), 'merge_drops_expired');
assert_true(isset($merged['2.2.2.2']), 'merge_keeps_unexpired');
assert_eq(['names' => ['old3.example', 'new3.example'], 'expires' => 4000], $merged['3.3.3.3'], 'merge_unions_same_ip');

$arp = gd_parse_arp(file_get_contents(__DIR__.'/fixtures/arp_an.txt'));
assert_eq(['10.10.3.17' => 'a4:5e:60:aa:bb:cc', '10.10.3.20' => 'a4:5e:60:dd:ee:ff'], $arp, 'arp_parses_and_lowercases');

assert_true(gd_names_match_glob(['api.epicgames.com'], '*.epicgames.com'), 'glob_matches_subdomain');
assert_true(gd_names_match_glob(['epicgames.com'], '*.epicgames.com'), 'glob_matches_apex');
assert_true(!gd_names_match_glob(['epicgames.com.evil.net'], '*.epicgames.com'), 'glob_rejects_suffix_trick');
assert_true(!gd_names_match_glob([], '*.epicgames.com'), 'glob_empty_names');
assert_true(gd_names_match_glob(['x.y', 'steam.valve.net'], 'steam*'), 'glob_any_name');

assert_true(!gd_ip_in_cidrs('8.8.8.8', ['10.0.0.0/abc']), 'ip_in_cidrs_rejects_malformed_prefix');
assert_true(!gd_ip_in_cidrs('8.8.8.8', ['10.0.0.0/33']), 'ip_in_cidrs_rejects_oversize_prefix');
assert_true(gd_ip_in_cidrs('10.0.0.5', ['10.0.0.0/8']), 'ip_in_cidrs_still_works');

$m2 = gd_merge_dns_maps(
    ['4.4.4.4' => ['names' => ['a.example', 'shared.example'], 'expires' => 3000]],
    ['4.4.4.4' => ['names' => ['shared.example', 'b.example'], 'expires' => 2500]], $now);
assert_eq(['a.example', 'shared.example', 'b.example'], $m2['4.4.4.4']['names'] ?? null, 'merge_unions_names_for_same_ip');
assert_eq(3000, $m2['4.4.4.4']['expires'] ?? null, 'merge_takes_max_expires');

assert_eq(['10.10.3.9' => 'a4:05:60:0a:0b:0c'], gd_parse_arp("? (10.10.3.9) at a4:5:60:a:b:c on igb0 ifscope [ethernet]\n"), 'arp_pads_octets');

assert_true(!gd_names_match_glob(['api.epicgamesXcom'], '*.epicgames.com'), 'glob_dot_is_literal');

$cyc = "START_RRSET_CACHE\na.example.\t60\tIN\tCNAME\tb.example.\nb.example.\t60\tIN\tCNAME\ta.example.\na.example.\t60\tIN\tA\t5.5.5.5\nEND_RRSET_CACHE\n";
$cm = gd_parse_unbound_cache($cyc, $now);
$cn = $cm['5.5.5.5']['names'] ?? [];
sort($cn);
assert_eq(['a.example', 'b.example'], $cn, 'unbound_cname_cycle_terminates');
