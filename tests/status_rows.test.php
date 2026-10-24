<?php
/*
 * Copyright 2026 Jeff Nelson
 * Licensed under the Apache License, Version 2.0.
 *
 * gamedetect_status_lib.inc: filtering, summary, row HTML, and the status banner.
 */
require __DIR__ . '/assert.inc';
require __DIR__ . '/../files/usr/local/pkg/gamedetect_status_lib.inc';

$rec = function (string $ip, string $game_id, string $game, string $conf, array $extra = []): array {
	return array_merge([
	    'ip' => $ip, 'mac' => null, 'hostname' => null, 'game' => $game, 'game_id' => $game_id,
	    'confidence' => $conf, 'score' => 50, 'candidates' => [], 'evidence' => [], 'flows' => 2,
	    'first_seen' => '2026-10-24T10:00:00-05:00', 'last_seen' => '2026-10-24T10:05:07-05:00',
	    'top_destinations' => [],
	], $extra);
};
$clients = [
    $rec('10.10.3.17', 'fortnite', 'Fortnite', 'high', ['mac' => 'a4:5e:60:01:02:03', 'hostname' => 'DESKTOP-K9']),
    $rec('10.10.3.22', 'valorant', 'Valorant', 'medium', ['hostname' => 'steamdeck']),
    $rec('10.10.4.101', 'unknown', 'unknown', 'unknown', ['top_destinations' => [
        ['ip' => '8.8.4.4', 'port' => 40000, 'names' => ['dns.google']],
    ]]),
    $rec('10.10.4.102', 'fortnite', 'Fortnite', 'low'),
];
$ips = fn(array $l) => array_map(fn($c) => $c['ip'], $l);

/* filter_by_substring_and_game */
assert_eq($ips($clients), $ips(gd_status_filter_clients($clients, '', '')), 'filter_by_substring_and_game empty filter keeps all');
assert_eq(['10.10.3.17'], $ips(gd_status_filter_clients($clients, 'desktop', '')), 'filter_by_substring_and_game hostname case-insensitive');
assert_eq(['10.10.3.17'], $ips(gd_status_filter_clients($clients, 'A4:5E', '')), 'filter_by_substring_and_game mac');
assert_eq(['10.10.4.101', '10.10.4.102'], $ips(gd_status_filter_clients($clients, '10.10.4.', '')), 'filter_by_substring_and_game ip');
assert_eq(['10.10.3.17', '10.10.4.102'], $ips(gd_status_filter_clients($clients, 'fort', '')), 'filter_by_substring_and_game game name');
assert_eq(['10.10.3.17', '10.10.4.102'], $ips(gd_status_filter_clients($clients, '', 'fortnite')), 'filter_by_substring_and_game game_id');
assert_eq(['10.10.4.102'], $ips(gd_status_filter_clients($clients, '10.10.4', 'fortnite')), 'filter_by_substring_and_game substring and game');
assert_eq([], gd_status_filter_clients($clients, 'nomatch', ''), 'filter_by_substring_and_game no match');
assert_eq(['10.10.4.101'], $ips(gd_status_filter_clients($clients, '', 'unknown')), 'filter_by_substring_and_game unknown game_id');

/* summary_counts */
$s = gd_status_summary($clients);
assert_eq(4, $s['total'], 'summary_counts total');
assert_eq(3, $s['playing'], 'summary_counts playing');
assert_eq(1, $s['unknown'], 'summary_counts unknown');
assert_eq(['name' => 'Fortnite', 'count' => 2], $s['by_game']['fortnite'] ?? null, 'summary_counts by_game fortnite');
assert_eq(['name' => 'Valorant', 'count' => 1], $s['by_game']['valorant'] ?? null, 'summary_counts by_game valorant');
assert_eq(['name' => 'unknown', 'count' => 1], $s['by_game']['unknown'] ?? null, 'summary_counts by_game unknown');
assert_eq(['total' => 0, 'playing' => 0, 'unknown' => 0, 'by_game' => []], gd_status_summary([]), 'summary_counts empty');

/* row_html_escapes */
$evil = $rec('10.10.3.30', 'fortnite', 'Fortnite', 'high', ['hostname' => '<script>alert(1)</script>', 'mac' => 'x"y']);
$h = gd_status_row_html($evil);
assert_true(!str_contains($h, '<script>'), 'row_html_escapes no raw script tag');
assert_true(str_contains($h, '&lt;script&gt;alert(1)&lt;/script&gt;'), 'row_html_escapes hostname escaped');
assert_true(str_contains($h, 'x&quot;y'), 'row_html_escapes mac quote escaped');
assert_true(str_starts_with(trim($h), '<tr'), 'row_html_escapes starts with tr');
assert_true(str_contains($h, 'label label-success'), 'row_html_escapes high -> label-success');
assert_true(!str_contains($h, 'fa-chevron-right'), 'row_html_escapes classified row has no chevron');
assert_true(str_contains(gd_status_row_html($clients[1]), 'label label-warning'), 'row_html_escapes medium -> label-warning');
assert_true(str_contains(gd_status_row_html($clients[3]), 'label label-default'), 'row_html_escapes low -> label-default');
$u = gd_status_row_html($clients[2]);
assert_true(str_contains($u, 'fa-solid fa-chevron-right'), 'row_html_escapes unknown row has chevron');
assert_true(str_contains($u, '8.8.4.4') && str_contains($u, '40000') && str_contains($u, 'dns.google'), 'row_html_escapes unknown row lists top destinations');
assert_true(!str_contains($u, 'label-success') && !str_contains($u, 'label-warning'), 'row_html_escapes unknown no confidence badge');
$u2 = gd_status_row_html($rec('10.10.4.9', 'unknown', 'unknown', 'unknown', ['top_destinations' => [
    ['ip' => '1.2.3.4', 'port' => 1, 'names' => ['<b>x</b>']]]]));
assert_true(!str_contains($u2, '<b>') && str_contains($u2, '&lt;b&gt;x&lt;/b&gt;'), 'row_html_escapes destination names escaped');
$bare = gd_status_row_html(['ip' => '10.0.0.1']);
assert_true(str_contains($bare, '10.0.0.1'), 'row_html_escapes tolerates missing fields');

/* banner_states */
assert_null(gd_status_banner(['running' => true, 'state' => 'running', 'warnings' => []]), 'banner_states healthy -> null');
assert_null(gd_status_banner(['running' => true, 'state' => 'running']), 'banner_states healthy without warnings key -> null');
$b = gd_status_banner(['running' => false]);
assert_true(is_string($b) && stripos($b, 'not running') !== false, 'banner_states not running');
$b = gd_status_banner([]);
assert_true(is_string($b) && stripos($b, 'not running') !== false, 'banner_states empty daemon -> not running');
$why = 'poll took 61.2 s (limit 60 s); send SIGHUP or restart to resume';
$b = gd_status_banner(['running' => true, 'state' => 'paused', 'reason' => $why, 'warnings' => []]);
assert_true(is_string($b) && stripos($b, 'paused') !== false && str_contains($b, $why), 'banner_states paused includes reason');
$b = gd_status_banner(['running' => true, 'state' => 'paused', 'reason' => 'no_attendee_networks', 'warnings' => []]);
assert_true(is_string($b) && stripos($b, 'attendee network') !== false, 'banner_states paused no_attendee_networks');
foreach ([
    'dns_cache_unavailable' => 'DNS',
    'signatures_invalid: bad JSON at line 3' => 'bad JSON at line 3',
    'arp_unavailable' => 'ARP',
    'leases_unavailable' => 'DHCP',
    'states_unavailable' => 'state table',
] as $w => $needle) {
	$b = gd_status_banner(['running' => true, 'state' => 'running', 'warnings' => [$w]]);
	assert_true(is_string($b) && stripos($b, $needle) !== false, "banner_states warning $w");
}
$b = gd_status_banner(['running' => true, 'state' => 'running', 'warnings' => ['dns_cache_unavailable', 'arp_unavailable']]);
assert_true(is_string($b) && str_contains($b, 'DNS') && str_contains($b, 'ARP'), 'banner_states multiple warnings');
$b = gd_status_banner(['running' => true, 'state' => 'running', 'warnings' => ['something_new']]);
assert_true(is_string($b) && str_contains($b, 'something_new'), 'banner_states unrecognised warning shown verbatim');
