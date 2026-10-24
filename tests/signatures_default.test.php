<?php
/*
 * Copyright 2026 Jeff Nelson
 * Licensed under the Apache License, Version 2.0.
 *
 * The shipped default signature file loads and classifies plausible samples.
 */
require __DIR__.'/assert.inc';
require __DIR__.'/../files/usr/local/pkg/gamedetect_lib.inc';

$path = __DIR__.'/../files/usr/local/share/pfSense-pkg-gamedetect/signatures.default.json';
$json = is_file($path) ? file_get_contents($path) : '';
$r = gd_load_signatures($json);
assert_true($r['ok'] ?? false, 'default_signatures_load_ok (' . ($r['error'] ?? '') . ')');
$sigs = $r['sigs'] ?? ['games' => [], 'prefix_sets' => [], 'thresholds' => ['high' => 80, 'medium' => 50, 'low' => 30]];
$raw = json_decode($json, true) ?: [];
assert_true(isset($raw['_sources']) && $raw['_sources'], 'default_has_sources');

$byid = [];
foreach ($sigs['games'] as $g) {
	$byid[$g['id']] = $g;
}
$required = [
	'fortnite' => 'epic', 'rocketleague' => 'epic',
	'valorant' => 'riot', 'lol' => 'riot', 'riot' => 'riot',
	'overwatch2' => 'blizzard', 'wow' => 'blizzard',
	'cod' => 'activision',
	'apex' => 'ea', 'battlefield' => 'ea',
	'cs2' => 'valve', 'dota2' => 'valve', 'steam' => 'valve',
	'roblox' => 'roblox',
	'source_engine' => 'source',
	'minecraft_bedrock' => 'mojang',
	'marvel_rivals' => 'netease',
];
foreach ($required as $id => $pub) {
	assert_true(isset($byid[$id]) && $byid[$id]['publisher'] === $pub, "default_has_required_titles $id ($pub)");
}

/* A title may skip the dns rule only with "dns_optional": true, explained in _notes. */
$notes = implode("\n", (array)($raw['_notes'] ?? []));
foreach ($sigs['games'] as $g) {
	$types = array_column($g['rules'], 'type');
	$has_dns = in_array('dns', $types, true);
	$has_flow = (bool)array_intersect(['port', 'prefix', 'port_and_prefix', 'src_port'], $types);
	$optional = ($g['dns_optional'] ?? false) === true;
	assert_true(($has_dns || $optional) && $has_flow, "default_every_title_has_dns_and_flow_rule {$g['id']}");
	if ($optional) {
		assert_true(!$has_dns && preg_match('/\b' . preg_quote($g['id'], '/') . '\b/', $notes) === 1,
		    "default_dns_optional_title_is_explained_in_notes {$g['id']}");
	}
}

$mb = $byid['minecraft_bedrock'] ?? ['rules' => []];
$mb_ports = [];
foreach ($mb['rules'] as $rule) {
	$mb_ports = array_merge($mb_ports, $rule['dst_ports'] ?? []);
}
assert_true(gd_port_in_ranges(19132, $mb_ports), 'default_minecraft_bedrock_claims_19132');
assert_true($sigs['games'] ? !gd_sigs_claim_port($sigs, 443) : false, 'default_no_title_claims_udp_443');

$udp = fn($ip, $port) => ['proto' => 'udp', 'src_ip' => '10.10.3.17', 'src_port' => 50000, 'dst_ip' => $ip, 'dst_port' => $port];
$tcp = fn($ip) => ['proto' => 'tcp', 'src_ip' => '10.10.3.17', 'src_port' => 50001, 'dst_ip' => $ip, 'dst_port' => 443];
$conf_rank = ['unknown' => 0, 'low' => 1, 'medium' => 2, 'high' => 3];
$at_least = fn(array $c, string $lvl) => $conf_rank[$c['confidence']] >= $conf_rank[$lvl];
$classify = fn(array $flows, array $dns) => $sigs['games'] ? gd_classify_client($flows, $dns, $sigs)
    : ['game_id' => 'none', 'confidence' => 'unknown'];

/* Fortnite: UDP to an AWS-hosted game server in 9000-9100; client also talks TLS to the Epic MCP. */
$c = $classify([$udp('18.198.53.7', 9017), $tcp('52.5.12.40')],
    ['52.5.12.40' => ['names' => ['fngw-mcp-gc-livefn.ol.epicgames.com'], 'expires' => 9999]]);
assert_eq('fortnite', $c['game_id'], 'default_fortnite_sample_classifies id');
assert_true($at_least($c, 'medium'), 'default_fortnite_sample_classifies >= medium (' . $c['confidence'] . ')');

/* Rocket League: same publisher, distinct ports and DNS. */
$c = $classify([$udp('35.80.4.9', 7810), $tcp('34.120.1.1')],
    ['34.120.1.1' => ['names' => ['api.rlpsynet.psyonix.com'], 'expires' => 9999]]);
assert_eq('rocketleague', $c['game_id'], 'default_rocketleague_sample_classifies id');
assert_true($at_least($c, 'medium'), 'default_rocketleague_sample_classifies >= medium (' . $c['confidence'] . ')');

/* CS2: UDP to a Valve SDR relay (AS32590, relay ports 27015-27140); main menu news from counter-strike.net. */
$c = $classify([$udp('155.133.226.71', 27031), $tcp('23.45.1.2')],
    ['23.45.1.2' => ['names' => ['www.counter-strike.net'], 'expires' => 9999]]);
assert_eq('cs2', $c['game_id'], 'default_cs2_sample_classifies id');
assert_true($at_least($c, 'medium'), 'default_cs2_sample_classifies >= medium (' . $c['confidence'] . ')');

/* Valve network traffic with no title DNS: Steam generic (port 25 + prefix 15 = 40, low), never CS2/Dota 2 (5 + 5 = 10). */
$c = $classify([$udp('155.133.226.71', 27031)], []);
assert_eq('steam', $c['game_id'], 'default_valve_network_only_is_steam_generic id');
assert_eq('low', $c['confidence'], 'default_valve_network_only_is_steam_generic conf');
assert_eq(40, $c['score'], 'default_valve_network_only_is_steam_generic score');

/* Same Valve flow plus counter-strike.net DNS: CS2 (5 + 5 + 50 = 60) beats Steam (40). */
$c = $classify([$udp('155.133.226.71', 27031), $tcp('23.45.1.2')],
    ['23.45.1.2' => ['names' => ['api.counter-strike.net'], 'expires' => 9999]]);
assert_eq('cs2', $c['game_id'], 'default_cs2_needs_dns id');
assert_true($at_least($c, 'medium'), 'default_cs2_needs_dns >= medium (' . $c['confidence'] . ')');

/* Same Valve flow plus dota2.com DNS: Dota 2. */
$c = $classify([$udp('155.133.226.71', 27031), $tcp('23.45.1.3')],
    ['23.45.1.3' => ['names' => ['www.dota2.com'], 'expires' => 9999]]);
assert_eq('dota2', $c['game_id'], 'default_dota2_needs_dns id');
assert_true($at_least($c, 'medium'), 'default_dota2_needs_dns >= medium (' . $c['confidence'] . ')');

/*
 * Live TF2 community-server flow (pf state `udp (10.10.3.17:27005) -> 108.181.244.75:49100`,
 * TELUS-hosted, no DNS): the Source client port 27005 (pre-NAT, from the parentheses) is the
 * only evidence. src_port 40 = low.
 */
$src = fn($ip, $port) => ['proto' => 'udp', 'src_ip' => '10.10.3.17', 'src_port' => 27005, 'dst_ip' => $ip, 'dst_port' => $port];
$c = $classify([$src('108.181.244.75', 49100)], []);
assert_eq('source_engine', $c['game_id'], 'default_tf2_community_server_is_source_engine id');
assert_eq('low', $c['confidence'], 'default_tf2_community_server_is_source_engine conf');
assert_eq('Source engine game', $c['game'] ?? null, 'default_tf2_community_server_is_source_engine name');

/* Source client to a common Source server port (27015) on a non-Valve host: src_port 40 + port 15 = 55, medium. */
$c = $classify([$src('169.150.232.56', 27015)], []);
assert_eq('source_engine', $c['game_id'], 'default_source_on_27015_is_medium id');
assert_eq('medium', $c['confidence'], 'default_source_on_27015_is_medium conf');

/*
 * Source client through a Valve SDR relay (src 27005 -> 155.133.230.4:27050, valve prefix):
 * source_engine 40 + 15 = 55 vs steam 25 + 15 = 40. Different publishers but 15 apart (> 10),
 * so source_engine wins at medium: the client port is the more specific evidence.
 */
$c = $classify([$src('155.133.230.4', 27050)], []);
assert_eq('source_engine', $c['game_id'], 'default_source_client_via_valve_relay_prefers_source_engine id');
assert_eq('medium', $c['confidence'], 'default_source_client_via_valve_relay_prefers_source_engine conf');
assert_eq(55, $c['score'], 'default_source_client_via_valve_relay_prefers_source_engine score');

/*
 * CS2 / Dota 2 with their DNS whose client also binds 27005, via a Valve relay on 27031:
 * cs2 60 + 5 + 5 = 70 vs source_engine 40 + 15 = 55 vs steam 40. > 10 apart, so the
 * title DNS wins (a 50 dns weight gave 60 vs 55 -> unknown).
 */
$c = $classify([$src('155.133.230.4', 27031), $tcp('23.45.1.2')],
    ['23.45.1.2' => ['names' => ['api.counter-strike.net'], 'expires' => 9999]]);
assert_eq('cs2', $c['game_id'], 'default_cs2_dns_beats_source_client_port id');
assert_true($at_least($c, 'medium'), 'default_cs2_dns_beats_source_client_port conf (' . $c['confidence'] . ')');
$c = $classify([$src('155.133.230.4', 27031), $tcp('23.45.1.3')],
    ['23.45.1.3' => ['names' => ['www.dota2.com'], 'expires' => 9999]]);
assert_eq('dota2', $c['game_id'], 'default_dota2_dns_beats_source_client_port id');
assert_true($at_least($c, 'medium'), 'default_dota2_dns_beats_source_client_port conf (' . $c['confidence'] . ')');

/* Source client to a Valve relay above 27050 (relay config spans 27015-27140): source_engine 55 vs steam 40, not a tie. */
$c = $classify([$src('155.133.230.4', 27100)], []);
assert_eq('source_engine', $c['game_id'], 'default_source_client_via_valve_relay_27100 id');
assert_eq('medium', $c['confidence'], 'default_source_client_via_valve_relay_27100 conf');

/* Valorant: UDP 7000-8000 to Riot Direct, plus a Valorant-specific host and the Riot client hosts. */
$c = $classify([$udp('192.64.170.20', 7155), $tcp('104.160.131.3'), $tcp('23.45.9.9')],
    ['104.160.131.3' => ['names' => ['auth.riotgames.com'], 'expires' => 9999],
     '23.45.9.9' => ['names' => ['playvalorant.com'], 'expires' => 9999]]);
assert_eq('valorant', $c['game_id'], 'default_valorant_sample_classifies id');
assert_eq('high', $c['confidence'], 'default_valorant_sample_classifies high');

/*
 * Live LoL session: `udp (10.10.3.17:60327) -> 192.207.0.5:7193`, riot
 * prefix, port inside Valorant's 7000-8000 too. The Riot client / Vanguard names the cache held
 * are shared by every Riot title, so they only name the generic "Riot game"; a game-specific
 * host (TCP 443 from the same client) names the title. DNS is client-scope: each name sits on
 * a destination this client is talking to.
 */
$lol_flow = ['proto' => 'udp', 'src_ip' => '10.10.3.17', 'src_port' => 60327, 'dst_ip' => '192.207.0.5', 'dst_port' => 7193];
$riot_generic = ['104.160.131.3' => ['names' => ['authenticate.riotgames.com', 'entitlements.auth.riotgames.com',
    'player-lifecycle-euc.publishing.riotgames.com', 'riot-client.dyn.riotcdn.net', 'na.vg.ac.pvp.net',
    'usw2-red.pp.sgp.pvp.net', 'cdn.rgpub.io.akamaized.net', 'ns0x.riotdns.com'], 'expires' => 9999]];
$gap = fn(array $c, string $other) => $c['score'] - (array_column($c['candidates'], 'score', 'id')[$other] ?? 0);

$c = $classify([$lol_flow, $tcp('104.160.131.3')], $riot_generic);
assert_eq('riot', $c['game_id'], 'default_riot_generic_names_is_riot_game id');
assert_eq('Riot game', $c['game'] ?? null, 'default_riot_generic_names_is_riot_game name');
assert_true($at_least($c, 'medium'), 'default_riot_generic_names_is_riot_game >= medium (' . $c['confidence'] . ')');

$c = $classify([$lol_flow], []);
assert_eq('riot', $c['game_id'], 'default_riot_flow_no_dns_is_riot_low id');
assert_eq('low', $c['confidence'], 'default_riot_flow_no_dns_is_riot_low conf');

foreach (['lolstatic-a.akamaihd.net.edgesuite.net', 'lolstatic-a.akamaihd.net', 'euw1.api.leagueoflegends.com'] as $n) {
	$c = $classify([$lol_flow, $tcp('104.160.131.3'), $tcp('23.45.8.8')],
	    $riot_generic + ['23.45.8.8' => ['names' => [$n], 'expires' => 9999]]);
	assert_eq('lol', $c['game_id'], "default_lol_specific_host_is_lol id ($n)");
	assert_true($at_least($c, 'medium'), "default_lol_specific_host_is_lol >= medium ($n, {$c['confidence']})");
	assert_true($gap($c, 'riot') > 10, "default_lol_specific_host_is_lol ahead of riot by > 10 ($n, " . $gap($c, 'riot') . ')');
}

$c = $classify([$lol_flow, $tcp('104.160.131.3'), $tcp('23.45.9.9')],
    $riot_generic + ['23.45.9.9' => ['names' => ['x.playvalorant.com'], 'expires' => 9999]]);
assert_eq('valorant', $c['game_id'], 'default_valorant_specific_host_is_valorant id');
assert_true($at_least($c, 'medium'), 'default_valorant_specific_host_is_valorant >= medium (' . $c['confidence'] . ')');
assert_true($gap($c, 'riot') > 10, 'default_valorant_specific_host_is_valorant ahead of riot by > 10 (' . $gap($c, 'riot') . ')');

/* Minecraft Bedrock: a community server on some VPS, no DNS context at all. */
$c = $classify([$udp('203.0.113.50', 19132)], []);
assert_eq('minecraft_bedrock', $c['game_id'], 'default_minecraft_bedrock_port_only_is_low_or_better id');
assert_true($at_least($c, 'low'), 'default_minecraft_bedrock_port_only_is_low_or_better conf (' . $c['confidence'] . ')');

/* Random ephemeral UDP to an unknown host must not become Roblox on the port alone. */
$c = $classify([$udp('198.51.100.9', 55000)], []);
assert_eq('unknown', $c['game_id'], 'default_ephemeral_port_alone_is_unknown');

/* Valorant voice (UDP 27016-27024 on Riot IPs) overlaps the Steam game range; it must not read as Valve. */
$c = $classify([$udp('104.160.131.3', 27020)], []);
assert_true(strncmp($c['game_id'], 'valve', 5) !== 0 && !in_array($c['game_id'], ['cs2', 'dota2', 'steam'], true), 'default_riot_voice_is_not_valve (' . $c['game_id'] . ')');

/* Roblox DNS in the cache plus a WebRTC-style high-port flow to a non-Roblox IP must not be Roblox. */
$rbx_dns = ['128.116.1.10' => ['names' => ['www.roblox.com', 'c0.rbxcdn.com'], 'expires' => 9999]];
$c = $classify([$udp('198.51.100.9', 55000), $tcp('128.116.1.10')], $rbx_dns);
assert_eq('unknown', $c['game_id'], 'default_roblox_dns_plus_ephemeral_non_roblox_ip_is_unknown');

/* Same DNS, high-port UDP to a Roblox game server inside AS22697. */
$c = $classify([$udp('128.116.50.20', 55000), $tcp('128.116.1.10')], $rbx_dns);
assert_eq('roblox', $c['game_id'], 'default_roblox_sample_classifies id');
assert_true($at_least($c, 'medium'), 'default_roblox_sample_classifies >= medium (' . $c['confidence'] . ')');

/*
 * Weight policy: a plain port rule (not prefix-scoped) stays below low, so a
 * broad range alone never labels a title. Only a title-unique port may reach low.
 */
$title_unique_ports = ['minecraft_bedrock'];
foreach ($sigs['games'] as $g) {
	foreach ($g['rules'] as $rule) {
		if ($rule['type'] === 'port' && !in_array($g['id'], $title_unique_ports, true)) {
			assert_true($rule['weight'] < $sigs['thresholds']['low'], "default_plain_port_rule_below_low {$g['id']} ({$rule['weight']})");
		}
	}
}

/* UDP 5060 (SIP/RTP) to a random host with no DNS: League is a candidate but not a label. */
$c = $classify([$udp('198.51.100.77', 5060)], []);
assert_eq('unknown', $c['game_id'], 'default_broad_port_only_is_unknown id');
assert_true(in_array('lol', array_column($c['candidates'], 'id'), true), 'default_broad_port_only_is_unknown lol in candidates');
foreach (['cod' => 3074, 'rocketleague' => 8500, 'cs2' => 27100, 'fortnite' => 9050, 'apex' => 38000, 'battlefield' => 3659, 'wow' => 3724] as $id => $port) {
	$c = $classify([$udp('198.51.100.77', $port)], []);
	assert_eq('unknown', $c['game_id'], "default_broad_port_only_is_unknown $id:$port");
	assert_true(in_array($id, array_column($c['candidates'], 'id'), true), "default_broad_port_only_is_unknown $id:$port candidate");
}

/*
 * Every title with a dns rule still reaches >= medium with its DNS plus its own flow rule.
 * Publisher-generic titles (they own the publisher's shared network/ports; a weak generic dns
 * rule at most) are covered by their own tests above.
 */
$publisher_generic = ['steam', 'riot'];
foreach ($sigs['games'] as $g) {
	if (($g['dns_optional'] ?? false) === true || in_array($g['id'], $publisher_generic, true)) {
		continue;
	}
	$dns_rule = array_values(array_filter($g['rules'], fn($r) => $r['type'] === 'dns'))[0] ?? null;
	$flow_rule = array_values(array_filter($g['rules'], fn($r) => in_array($r['type'], ['port', 'port_and_prefix'], true)))[0] ?? null;
	if ($dns_rule === null || $flow_rule === null) {
		assert_true(false, "default_dns_plus_port_reaches_medium {$g['id']}: needs a dns and a port rule");
		continue;
	}
	$glob = $dns_rule['match'][0];
	$name = str_starts_with($glob, '*.') ? 'play.' . substr($glob, 2) : str_replace('*', 'x', $glob);
	$port = gd_parse_port_range($flow_rule['dst_ports'][0])[0];
	$ip = '198.51.100.77';
	if ($flow_rule['type'] === 'port_and_prefix') {
		$ip = long2ip(ip2long(explode('/', $sigs['prefix_sets'][$flow_rule['set']][0])[0]) + 1);
	}
	$c = $classify([$udp($ip, $port), $tcp('192.0.2.10')], ['192.0.2.10' => ['names' => [$name], 'expires' => 9999]]);
	assert_eq($g['id'], $c['game_id'], "default_dns_plus_port_reaches_medium {$g['id']} id ($name, $ip:$port)");
	assert_true($at_least($c, 'medium'), "default_dns_plus_port_reaches_medium {$g['id']} conf ({$c['confidence']}, {$c['score']})");
}
