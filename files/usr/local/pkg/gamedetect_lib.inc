<?php
/*
 * Copyright 2026 Jeff Nelson
 * Licensed under the Apache License, Version 2.0.
 *
 * Pure-PHP helpers for pfSense-pkg-gamedetect. No pfSense dependencies.
 */

const GD_LIB_VERSION = '0.2.0';

/* True if $ip (dotted quad) lies within any of $cidrs ("a.b.c.d/n" or bare IP = /32). */
function gd_ip_in_cidrs(string $ip, array $cidrs): bool {
	$ipl = ip2long($ip);
	if ($ipl === false) {
		return false;
	}
	$ipl &= 0xFFFFFFFF;
	foreach ($cidrs as $cidr) {
		$parts = explode('/', (string)$cidr, 2);
		$netl = ip2long($parts[0]);
		if ($netl === false) {
			continue;
		}
		if (isset($parts[1])) {
			if (!ctype_digit($parts[1])) {
				continue;
			}
			$bits = (int)$parts[1];
		} else {
			$bits = 32;
		}
		if ($bits > 32) {
			continue;
		}
		$mask = $bits === 0 ? 0 : ((0xFFFFFFFF << (32 - $bits)) & 0xFFFFFFFF);
		if ((($netl & 0xFFFFFFFF) & $mask) === ($ipl & $mask)) {
			return true;
		}
	}
	return false;
}

/*
 * Precompile CIDR strings into [net_long, mask_long] pairs (net already masked).
 * Malformed entries are skipped exactly as gd_ip_in_cidrs() skips them.
 */
function gd_compile_cidrs(array $cidrs): array {
	$out = [];
	foreach ($cidrs as $cidr) {
		$parts = explode('/', (string)$cidr, 2);
		$netl = ip2long($parts[0]);
		if ($netl === false) {
			continue;
		}
		if (isset($parts[1])) {
			if (!ctype_digit($parts[1])) {
				continue;
			}
			$bits = (int)$parts[1];
		} else {
			$bits = 32;
		}
		if ($bits > 32) {
			continue;
		}
		$mask = $bits === 0 ? 0 : ((0xFFFFFFFF << (32 - $bits)) & 0xFFFFFFFF);
		$out[] = [($netl & 0xFFFFFFFF) & $mask, $mask];
	}
	return $out;
}

/* True if $ip_long (ip2long() & 0xFFFFFFFF) lies in any gd_compile_cidrs() pair. */
function gd_ip_in_compiled(int $ip_long, array $pairs): bool {
	foreach ($pairs as [$net, $mask]) {
		if (($ip_long & $mask) === $net) {
			return true;
		}
	}
	return false;
}

/*
 * Parse `pfctl -ss` output into deduplicated outbound IPv4 flows from attendee
 * addresses. Source is the pre-NAT (parenthesised) address when present.
 * Returns list of ['proto','src_ip','src_port','dst_ip','dst_port'] in input order.
 */
function gd_parse_states(string $pfctl_output, array $attendee_cidrs, array $local_ips): array {
	static $ignore_dst = ['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '100.64.0.0/10',
	    '127.0.0.0/8', '169.254.0.0/16', '224.0.0.0/4', '255.255.255.255/32'];
	static $ignore_udp_ports = [53, 67, 68, 123, 1900, 5353, 3478, 3479, 3480, 3481];
	static $ignore_pairs = null;
	$ignore_pairs ??= gd_compile_cidrs($ignore_dst);
	$attendee_pairs = gd_compile_cidrs($attendee_cidrs);
	$ep = '/^(\d{1,3}(?:\.\d{1,3}){3}):(\d+)$/';
	$flows = [];
	foreach (preg_split('/\r?\n/', $pfctl_output) as $line) {
		if (!preg_match('/^\S+\s+(udp|tcp)\s+(\S+)(?:\s+\((\S+)\))?\s+(->|<-)\s+(\S+)(?:\s+\((\S+)\))?/', $line, $m)) {
			continue;
		}
		if ($m[4] !== '->') {
			continue;
		}
		$proto = $m[1];
		$src = ($m[3] ?? '') !== '' ? $m[3] : $m[2];
		if (!preg_match($ep, $src, $s) || !preg_match($ep, $m[5], $d)) {
			continue;
		}
		$sport = (int)$s[2];
		$dport = (int)$d[2];
		$sl = ip2long($s[1]);
		$dl = ip2long($d[1]);
		if ($sl === false || !gd_ip_in_compiled($sl & 0xFFFFFFFF, $attendee_pairs)) {
			continue;
		}
		if (($dl !== false && gd_ip_in_compiled($dl & 0xFFFFFFFF, $ignore_pairs)) || in_array($d[1], $local_ips, true)) {
			continue;
		}
		if ($proto === 'tcp' && $dport !== 443) {
			continue;
		}
		if ($proto === 'udp' && in_array($dport, $ignore_udp_ports, true)) {
			continue;
		}
		$key = "$proto $s[1]:$sport $d[1]:$dport";
		if (!isset($flows[$key])) {
			$flows[$key] = ['proto' => $proto, 'src_ip' => $s[1], 'src_port' => $sport,
			    'dst_ip' => $d[1], 'dst_port' => $dport];
		}
	}
	return array_values($flows);
}

/*
 * Parse `unbound-control dump_cache` (RRSET section only) into
 * ip => ['names' => [...], 'expires' => int]. CNAME chains (depth <= 8) are
 * flattened so each A address carries every name that resolves to it.
 */
function gd_parse_unbound_cache(string $dump, int $now): array {
	$a = [];      /* owner => list of [ip, ttl] */
	$cname = [];  /* target => list of aliases */
	$in = false;
	foreach (preg_split('/\r?\n/', $dump) as $line) {
		$line = trim($line);
		if ($line === 'START_RRSET_CACHE') {
			$in = true;
			continue;
		}
		if ($line === 'END_RRSET_CACHE') {
			break;
		}
		if (!$in || $line === '' || $line[0] === ';') {
			continue;
		}
		$f = preg_split('/\s+/', $line);
		if (count($f) < 5 || strtoupper($f[2]) !== 'IN' || !ctype_digit($f[1])) {
			continue;
		}
		$owner = strtolower(rtrim($f[0], '.'));
		$type = strtoupper($f[3]);
		$rdata = strtolower(rtrim($f[4], '.'));
		if ($type === 'A' && filter_var($rdata, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
			$a[$owner][] = [$rdata, (int)$f[1]];
		} elseif ($type === 'CNAME') {
			$cname[$rdata][] = $owner;
		}
	}
	$out = [];
	foreach ($a as $owner => $recs) {
		$names = [$owner => true];
		$frontier = [$owner];
		for ($depth = 0; $depth < 8 && $frontier; $depth++) {
			$next = [];
			foreach ($frontier as $n) {
				foreach ($cname[$n] ?? [] as $alias) {
					if (!isset($names[$alias])) {
						$names[$alias] = true;
						$next[] = $alias;
					}
				}
			}
			$frontier = $next;
		}
		foreach ($recs as [$ip, $ttl]) {
			$exp = $now + $ttl;
			if (!isset($out[$ip])) {
				$out[$ip] = ['names' => [], 'expires' => $exp];
			}
			$out[$ip]['names'] = array_values(array_unique(array_merge($out[$ip]['names'], array_keys($names))));
			$out[$ip]['expires'] = max($out[$ip]['expires'], $exp);
		}
	}
	return $out;
}

/*
 * Union of two ip maps; entries with expires < $now are dropped. An IP live in
 * both maps gets the union of its names and the later expiry.
 */
function gd_merge_dns_maps(array $old, array $new, int $now): array {
	$live = fn($e) => ($e['expires'] ?? 0) >= $now;
	$merged = array_filter($old, $live);
	foreach (array_filter($new, $live) as $ip => $e) {
		if (isset($merged[$ip])) {
			$e['names'] = array_values(array_unique(array_merge($merged[$ip]['names'] ?? [], $e['names'] ?? [])));
			$e['expires'] = max($merged[$ip]['expires'], $e['expires']);
		}
		$merged[$ip] = $e;
	}
	return $merged;
}

/* Parse `arp -an` into ip => lower-case mac; incomplete entries are skipped. */
function gd_parse_arp(string $arp_output): array {
	$map = [];
	foreach (preg_split('/\r?\n/', $arp_output) as $line) {
		if (preg_match('/\((\d{1,3}(?:\.\d{1,3}){3})\)\s+at\s+([0-9a-fA-F]{1,2}(?::[0-9a-fA-F]{1,2}){5})\b/', $line, $m)) {
			$map[$m[1]] = implode(':', array_map(fn($o) => str_pad(strtolower($o), 2, '0', STR_PAD_LEFT), explode(':', $m[2])));
		}
	}
	return $map;
}

/* True if any name matches the glob. `*` spans dots; `*.x` also matches `x`. */
function gd_names_match_glob(array $names, string $glob): bool {
	$glob = strtolower($glob);
	$re = '/^' . str_replace('\\*', '.*', preg_quote($glob, '/')) . '$/';
	$apex = strncmp($glob, '*.', 2) === 0 ? substr($glob, 2) : null;
	foreach ($names as $n) {
		$n = strtolower((string)$n);
		if (preg_match($re, $n) || ($apex !== null && $n === $apex)) {
			return true;
		}
	}
	return false;
}

/* Parse "N" or "N-M" into [lo, hi]; null if malformed or out of 1-65535. */
function gd_parse_port_range(string $r): ?array {
	if (!preg_match('/^(\d{1,5})(?:-(\d{1,5}))?$/', $r, $m)) {
		return null;
	}
	$lo = (int)$m[1];
	$hi = isset($m[2]) ? (int)$m[2] : $lo;
	if ($lo < 1 || $hi > 65535 || $lo > $hi) {
		return null;
	}
	return [$lo, $hi];
}

/* True if $port lies in any "N"/"N-M" range string of $ranges. */
function gd_port_in_ranges(int $port, array $ranges): bool {
	foreach ($ranges as $r) {
		$p = gd_parse_port_range((string)$r);
		if ($p !== null && $port >= $p[0] && $port <= $p[1]) {
			return true;
		}
	}
	return false;
}

/* Precompile "N"/"N-M" range strings into [lo, hi] int pairs; malformed ones are skipped. */
function gd_compile_ports(array $ranges): array {
	$out = [];
	foreach ($ranges as $r) {
		$p = gd_parse_port_range((string)$r);
		if ($p !== null) {
			$out[] = $p;
		}
	}
	return $out;
}

/* True if $port lies in any gd_compile_ports() pair. */
function gd_port_in_compiled(int $port, array $ranges): bool {
	foreach ($ranges as [$lo, $hi]) {
		if ($port >= $lo && $port <= $hi) {
			return true;
		}
	}
	return false;
}

/*
 * Attach precompiled match data to decoded signatures (idempotent), under
 * $sigs['_compiled']:
 *   sets[name]   gd_compile_cidrs() pairs of each prefix set
 *   set_idx[name][first octet]  the pairs that can contain an ip with that octet
 *   frules       flow-scope rules in game/rule order: ['g' => game index,
 *                'type', 'weight', 'set', 'ports' => gd_compile_ports() of
 *                dst_ports (src_ports for src_port rules), 'ev']
 *   seg_lo/seg_rules  dst port segments: frule ids whose dst_ports cover
 *                [seg_lo[i], seg_lo[i+1]) (port and port_and_prefix rules)
 *   src_seg_lo/src_seg_rules  the same over src_ports (src_port rules)
 *   prefix_rules frule ids of type prefix (port-independent)
 * The classifier uses only these; the string forms stay for display.
 */
function gd_compile_signatures(array $sigs): array {
	if (isset($sigs['_compiled']['v'])) {
		return $sigs;
	}
	$sets = [];
	$idx = [];
	foreach ($sigs['prefix_sets'] ?? [] as $name => $cidrs) {
		$sets[$name] = gd_compile_cidrs((array)$cidrs);
		$idx[$name] = [];
		foreach ($sets[$name] as $pair) {
			$lo = $pair[0] >> 24;
			$hi = ($pair[0] | (~$pair[1] & 0xFFFFFFFF)) >> 24;
			for ($o = $lo; $o <= $hi; $o++) {
				$idx[$name][$o][] = $pair;
			}
		}
	}
	$frules = [];
	$prefix_rules = [];
	foreach ($sigs['games'] ?? [] as $gi => $g) {
		foreach ($g['rules'] ?? [] as $r) {
			$type = $r['type'] ?? null;
			if ($type === 'dns') {
				continue;
			}
			$pkey = $type === 'src_port' ? 'src_ports' : 'dst_ports';
			$ports = gd_compile_ports((array)($r[$pkey] ?? []));
			$pstr = implode(',', (array)($r[$pkey] ?? []));
			$set = (string)($r['set'] ?? '');
			$id = count($frules);
			$frules[] = ['g' => $gi, 'type' => $type, 'weight' => (int)$r['weight'], 'set' => $set, 'ports' => $ports,
			    'ev' => match ($type) {
			        'port' => "port:$pstr",
			        'src_port' => "src_port:$pstr",
			        'prefix' => "prefix:$set",
			        default => "port_and_prefix:$pstr+$set",
			    }];
			if ($type === 'prefix') {
				$prefix_rules[] = $id;
			}
		}
	}
	[$seg_lo, $seg_rules] = gd_compile_port_segments($frules, ['port', 'port_and_prefix']);
	[$src_seg_lo, $src_seg_rules] = gd_compile_port_segments($frules, ['src_port']);
	$sigs['_compiled'] = ['v' => 1, 'sets' => $sets, 'set_idx' => $idx, 'frules' => $frules,
	    'seg_lo' => $seg_lo, 'seg_rules' => $seg_rules, 'src_seg_lo' => $src_seg_lo, 'src_seg_rules' => $src_seg_rules,
	    'prefix_rules' => $prefix_rules];
	return $sigs;
}

/*
 * [seg_lo, seg_rules] over the frules whose type is in $types: seg_rules[i]
 * lists the frule ids whose ports cover [seg_lo[i], seg_lo[i+1]).
 */
function gd_compile_port_segments(array $frules, array $types): array {
	$bounds = [0 => true, 65536 => true];
	foreach ($frules as $fr) {
		foreach (in_array($fr['type'], $types, true) ? $fr['ports'] : [] as [$lo, $hi]) {
			$bounds[$lo] = true;
			$bounds[$hi + 1] = true;
		}
	}
	ksort($bounds);
	$seg_lo = array_keys($bounds);
	$seg_rules = [];
	foreach ($seg_lo as $i => $lo) {
		$seg_rules[$i] = [];
		foreach ($frules as $id => $fr) {
			if (in_array($fr['type'], $types, true) && gd_port_in_compiled($lo, $fr['ports'])) {
				$seg_rules[$i][] = $id;
			}
		}
	}
	return [$seg_lo, $seg_rules];
}

/*
 * frule ids (ascending) whose dst_ports cover $port, by binary search over the
 * segments; with $src true, the src_port rules whose src_ports cover it.
 */
function gd_rules_for_port(array $compiled, int $port, bool $src = false): array {
	$lo = $compiled[$src ? 'src_seg_lo' : 'seg_lo'];
	$a = 0;
	$b = count($lo) - 1;
	if ($port < 0 || $port >= 65536) {
		return [];
	}
	while ($a < $b) {
		$m = ($a + $b + 1) >> 1;
		if ($lo[$m] <= $port) {
			$a = $m;
		} else {
			$b = $m - 1;
		}
	}
	return $compiled[$src ? 'src_seg_rules' : 'seg_rules'][$a];
}

/*
 * Validate and decode a signature file. Returns ['ok'=>true,'sigs'=>array] or
 * ['ok'=>false,'error'=>string]. Top-level keys starting with "_" are ignored
 * (and dropped); the returned sigs carry gd_compile_signatures() data.
 */
function gd_load_signatures(string $json): array {
	$err = fn(string $m) => ['ok' => false, 'error' => $m];
	$d = json_decode($json, true);
	if (!is_array($d) || array_is_list($d)) {
		return $err('root: not a JSON object');
	}
	foreach (array_keys($d) as $k) {
		if (!in_array($k, ['version', 'thresholds', 'prefix_sets', 'games', 'publishers'], true)
		    && strncmp((string)$k, '_', 1) !== 0) {
			return $err("$k: unknown top-level key");
		}
	}
	if (($d['version'] ?? null) !== 1) {
		return $err('version: must be 1');
	}
	$t = $d['thresholds'] ?? null;
	if (!is_array($t) || !is_int($t['high'] ?? null) || !is_int($t['medium'] ?? null) || !is_int($t['low'] ?? null)
	    || !($t['high'] > $t['medium'] && $t['medium'] > $t['low'] && $t['low'] > 0)) {
		return $err('thresholds: need integers high > medium > low > 0');
	}
	$sets = $d['prefix_sets'] ?? null;
	if (!is_array($sets) || ($sets && array_is_list($sets))) {
		return $err('prefix_sets: must be an object');
	}
	foreach ($sets as $name => $cidrs) {
		if (!is_array($cidrs) || !array_is_list($cidrs)) {
			return $err("prefix_sets.$name: must be a list");
		}
		foreach ($cidrs as $i => $c) {
			if (!is_string($c) || !preg_match('#^\d{1,3}(?:\.\d{1,3}){3}(?:/\d{1,2})?$#', $c)
			    || ip2long(explode('/', $c)[0]) === false
			    || (strpos($c, '/') !== false && (int)explode('/', $c)[1] > 32)) {
				return $err("prefix_sets.{$name}[$i]: bad CIDR " . json_encode($c));
			}
		}
	}
	if (isset($d['publishers'])) {
		if (!is_array($d['publishers'])) {
			return $err('publishers: must be an object');
		}
		foreach ($d['publishers'] as $k => $v) {
			if (!is_string($v) || $v === '') {
				return $err("publishers.$k: must be a non-empty string");
			}
		}
	}
	$games = $d['games'] ?? null;
	if (!is_array($games) || !array_is_list($games) || !$games) {
		return $err('games: must be a non-empty list');
	}
	$idre = '/^[a-z0-9_-]+$/';
	$seen = [];
	foreach ($games as $gi => $g) {
		$gp = "games[$gi]";
		if (!is_array($g)) {
			return $err("$gp: must be an object");
		}
		if (!is_string($g['id'] ?? null) || !preg_match($idre, $g['id'])) {
			return $err("$gp.id: missing or invalid");
		}
		if (isset($seen[$g['id']])) {
			return $err("$gp.id: duplicate \"{$g['id']}\"");
		}
		$seen[$g['id']] = true;
		if (!is_string($g['name'] ?? null) || $g['name'] === '') {
			return $err("$gp.name: missing");
		}
		if (!is_string($g['publisher'] ?? null) || !preg_match($idre, $g['publisher'])) {
			return $err("$gp.publisher: missing or invalid");
		}
		if (array_key_exists('dns_optional', $g) && !is_bool($g['dns_optional'])) {
			return $err("$gp.dns_optional: must be true or false");
		}
		if (!is_array($g['rules'] ?? null) || !array_is_list($g['rules']) || !$g['rules']) {
			return $err("$gp.rules: must be a non-empty list");
		}
		foreach ($g['rules'] as $ri => $r) {
			$rp = "$gp.rules[$ri]";
			if (!is_array($r)) {
				return $err("$rp: must be an object");
			}
			$type = $r['type'] ?? null;
			if (!in_array($type, ['dns', 'port', 'src_port', 'prefix', 'port_and_prefix'], true)) {
				return $err("$rp.type: bad type " . json_encode($type));
			}
			$w = $r['weight'] ?? null;
			if (!is_int($w) || $w < 1 || $w > 100) {
				return $err("$rp.weight: must be integer 1-100");
			}
			if ($type === 'dns') {
				$m = $r['match'] ?? null;
				if (!is_array($m) || !$m || !array_is_list($m)) {
					return $err("$rp.match: must be a non-empty list");
				}
				foreach ($m as $x) {
					if (!is_string($x) || $x === '') {
						return $err("$rp.match: bad glob " . json_encode($x));
					}
				}
			}
			if ($type === 'port' || $type === 'port_and_prefix') {
				$pr = $r['dst_ports'] ?? null;
				if (!is_array($pr) || !$pr || !array_is_list($pr)) {
					return $err("$rp.dst_ports: must be a non-empty list");
				}
				foreach ($pr as $x) {
					if (!is_string($x) || gd_parse_port_range($x) === null) {
						return $err("$rp.dst_ports: bad range " . json_encode($x));
					}
				}
			}
			if ($type === 'src_port') {
				$pr = $r['src_ports'] ?? null;
				if (!is_array($pr) || !$pr || !array_is_list($pr)) {
					return $err("$rp.src_ports: must be a non-empty list");
				}
				foreach ($pr as $x) {
					if (!is_string($x) || gd_parse_port_range($x) === null) {
						return $err("$rp.src_ports: bad range " . json_encode($x));
					}
				}
			}
			if ($type === 'prefix' || $type === 'port_and_prefix') {
				if (!is_string($r['set'] ?? null) || !isset($sets[$r['set']])) {
					return $err("$rp.set: unknown prefix set " . json_encode($r['set'] ?? null));
				}
			}
		}
	}
	foreach (array_keys($d) as $k) {
		if (strncmp((string)$k, '_', 1) === 0) {
			unset($d[$k]);
		}
	}
	return ['ok' => true, 'sigs' => gd_compile_signatures($d)];
}

function gd_publisher_name(string $id, array $sigs): string {
	return $sigs['publishers'][$id] ?? ucfirst($id);
}

/*
 * Classify one client from its flows and the DNS map. Score per title =
 * best UDP flow's flow-scope rule sum (port, src_port, prefix,
 * port_and_prefix on that one flow) + client-scope DNS rule sum; a title
 * needs flow score > 0 to be a candidate. $memo caches (prefix set, dst ip)
 * membership; pass the same array for every client of one poll.
 */
function gd_classify_client(array $flows, array $dns_map, array $sigs, ?array &$memo = null): array {
	$sigs = gd_compile_signatures($sigs);
	$memo ??= [];
	$unknown = ['game_id' => 'unknown', 'game' => 'unknown', 'confidence' => 'unknown',
	    'score' => 0, 'candidates' => [], 'evidence' => []];
	$names = [];
	$udp = [];
	foreach ($flows as $f) {
		foreach ($dns_map[$f['dst_ip']]['names'] ?? [] as $n) {
			$names[] = $n;
		}
		if ($f['proto'] === 'udp') {
			$ipl = ip2long($f['dst_ip']);
			$udp[] = [(int)$f['dst_port'], $ipl === false ? -1 : ($ipl & 0xFFFFFFFF), (int)($f['src_port'] ?? 0)];
		}
	}
	$cc = $sigs['_compiled'];
	$frules = $cc['frules'];
	$best = [];      /* game index => best flow sum */
	$best_ev = [];   /* game index => that flow's evidence */
	foreach ($udp as [$port, $ipl, $sport]) {
		$ids = gd_rules_for_port($cc, $port);
		$extra = $cc['prefix_rules'];
		if (count($cc['src_seg_lo']) > 2) {
			$sids = gd_rules_for_port($cc, $sport, true);
			if ($sids) {
				$extra = $extra ? array_merge($extra, $sids) : $sids;
			}
		}
		if ($extra) {
			$ids = $ids ? array_merge($ids, $extra) : $extra;
			sort($ids);
		}
		$sum = [];
		$ev = [];
		foreach ($ids as $id) {
			$r = $frules[$id];
			if ($r['type'] === 'prefix' || $r['type'] === 'port_and_prefix') {
				$set = $r['set'];
				if ($ipl < 0) {
					continue;
				}
				if (!isset($memo[$set][$ipl])) {
					$memo[$set][$ipl] = gd_ip_in_compiled($ipl, $cc['set_idx'][$set][$ipl >> 24] ?? []);
				}
				if (!$memo[$set][$ipl]) {
					continue;
				}
			}
			$gi = $r['g'];
			$sum[$gi] = ($sum[$gi] ?? 0) + $r['weight'];
			$ev[$gi][] = $r['ev'];
		}
		foreach ($sum as $gi => $v) {
			if ($v > ($best[$gi] ?? 0)) {
				$best[$gi] = $v;
				$best_ev[$gi] = $ev[$gi];
			}
		}
	}
	$cands = [];
	foreach ($sigs['games'] as $gi => $g) {
		if (!isset($best[$gi])) {
			continue;
		}
		$score = $best[$gi];
		$gev = $best_ev[$gi];
		foreach ($g['rules'] as $r) {
			if ($r['type'] !== 'dns') {
				continue;
			}
			foreach ($r['match'] as $glob) {
				if (gd_names_match_glob($names, $glob)) {
					$score += $r['weight'];
					$gev[] = "dns:$glob";
					break;
				}
			}
		}
		$cands[] = ['id' => $g['id'], 'name' => $g['name'], 'score' => $score,
		    'publisher' => $g['publisher'], 'evidence' => array_values(array_unique($gev))];
	}
	if (!$cands) {
		return $unknown;
	}
	usort($cands, fn($a, $b) => $b['score'] <=> $a['score']);
	$out = $unknown;
	$out['candidates'] = array_map(fn($c) => ['id' => $c['id'], 'name' => $c['name'], 'score' => $c['score']], $cands);
	$t = $sigs['thresholds'];
	$top = $cands[0];
	if ($top['score'] < $t['low']) {
		return $out;
	}
	$conf = $top['score'] >= $t['high'] ? 'high' : ($top['score'] >= $t['medium'] ? 'medium' : 'low');
	$second = $cands[1] ?? null;
	if ($second !== null && $second['score'] >= $t['low'] && $top['score'] - $second['score'] <= 10) {
		if ($second['publisher'] !== $top['publisher']) {
			return $out;
		}
		$out['game_id'] = $top['publisher'] . ':ambiguous';
		$out['game'] = gd_publisher_name($top['publisher'], $sigs) . " ({$top['name']} or {$second['name']})";
		$out['confidence'] = $conf === 'high' ? 'medium' : $conf;
		$out['score'] = $top['score'];
		$out['evidence'] = array_values(array_unique(array_merge($top['evidence'], $second['evidence'])));
		return $out;
	}
	$out['game_id'] = $top['id'];
	$out['game'] = $top['name'];
	$out['confidence'] = $conf;
	$out['score'] = $top['score'];
	$out['evidence'] = $top['evidence'];
	return $out;
}

/* True if any signature port / port_and_prefix rule covers $port. */
function gd_sigs_claim_port(array $sigs, int $port): bool {
	foreach ($sigs['games'] as $g) {
		foreach ($g['rules'] as $r) {
			if (in_array($r['type'], ['port', 'port_and_prefix'], true) && gd_port_in_ranges($port, $r['dst_ports'])) {
				return true;
			}
		}
	}
	return false;
}

/*
 * Aggregate one poll into client records (clients.json shape), an unknown
 * destination ranking, and the pending list (ips with UDP but no candidate
 * title, awaiting a second consecutive poll before being listed as unknown).
 * $prev is the previous return value or [].
 * The unknown ranking counts UDP flows only from clients whose result is
 * game_id 'unknown' (including first-poll pending clients), never the
 * non-matching flows of classified clients. A client already listed as unknown
 * in $prev skips the 2-poll gate. Records kept from $prev for absent clients
 * are stale snapshots with flows set to 0.
 */
function gd_aggregate(array $flows, array $dns_map, array $identity, array $sigs, array $prev, int $now, array $opts): array {
	$idle = (int)($opts['idle_timeout'] ?? 300);
	$ulist = (int)($opts['unknown_list_size'] ?? 10);
	$sigs = gd_compile_signatures($sigs);
	$memo = [];
	$drop443 = !gd_sigs_claim_port($sigs, 443);
	$iso = fn(int $ts) => date(DATE_ATOM, $ts);

	$by = [];
	foreach ($flows as $f) {
		if ($f['proto'] === 'udp' && $drop443 && $f['dst_port'] === 443) {
			continue;
		}
		$by[$f['src_ip']][] = $f;
	}
	$prev_clients = [];
	foreach ($prev['clients'] ?? [] as $pc) {
		$prev_clients[$pc['ip']] = $pc;
	}
	$prev_pending = array_flip($prev['pending'] ?? []);

	$clients = [];
	$pending = [];
	$unk_dest = [];   /* "ip:port" => ['ip','port','clients'=>set] */
	foreach ($by as $ip => $cf) {
		$udp = array_values(array_filter($cf, fn($f) => $f['proto'] === 'udp'));
		if (!$udp) {
			continue;
		}
		$c = gd_classify_client($cf, $dns_map, $sigs, $memo);
		$is_unknown = $c['game_id'] === 'unknown';
		if ($is_unknown) {
			foreach ($udp as $f) {
				$k = $f['dst_ip'] . ':' . $f['dst_port'];
				$unk_dest[$k] ??= ['ip' => $f['dst_ip'], 'port' => $f['dst_port'], 'clients' => []];
				$unk_dest[$k]['clients'][$ip] = true;
			}
			$was_unknown = ($prev_clients[$ip]['game_id'] ?? null) === 'unknown';
			if (!$c['candidates'] && !isset($prev_pending[$ip]) && !$was_unknown) {
				$pending[] = $ip;
				continue;
			}
		}
		$counts = [];
		foreach ($udp as $f) {
			$k = $f['dst_ip'] . ':' . $f['dst_port'];
			$counts[$k] = ($counts[$k] ?? ['ip' => $f['dst_ip'], 'port' => $f['dst_port'], 'n' => 0]);
			$counts[$k]['n']++;
		}
		uasort($counts, fn($a, $b) => $b['n'] <=> $a['n']);
		$top = [];
		foreach (array_slice($counts, 0, 5) as $d) {
			$top[] = ['ip' => $d['ip'], 'port' => $d['port'], 'names' => array_values($dns_map[$d['ip']]['names'] ?? [])];
		}
		$first = isset($prev_clients[$ip]) ? strtotime($prev_clients[$ip]['first_seen']) : false;
		$clients[$ip] = [
		    'ip' => $ip,
		    'mac' => $identity['mac'][$ip] ?? null,
		    'hostname' => $identity['hostname'][$ip] ?? null,
		    'game' => $c['game'],
		    'game_id' => $c['game_id'],
		    'confidence' => $c['confidence'],
		    'score' => $c['score'],
		    'candidates' => $c['candidates'],
		    'evidence' => $c['evidence'],
		    'flows' => count($udp),
		    'first_seen' => $iso($first !== false ? $first : $now),
		    'last_seen' => $iso($now),
		    'top_destinations' => $top,
		];
	}
	foreach ($prev_clients as $ip => $pc) {
		if (isset($clients[$ip])) {
			continue;
		}
		$ls = strtotime($pc['last_seen']);
		if ($ls !== false && $ls + $idle >= $now) {
			$pc['flows'] = 0;
			$clients[$ip] = $pc;
		}
	}
	uksort($clients, fn($a, $b) => ip2long($a) <=> ip2long($b));

	$unknown = [];
	foreach ($unk_dest as $d) {
		$unknown[] = ['ip' => $d['ip'], 'port' => $d['port'],
		    'names' => array_values($dns_map[$d['ip']]['names'] ?? []), 'clients' => count($d['clients'])];
	}
	/* Most clients first, then ip, then port (keys precomputed: tens of thousands of rows). */
	if ($unknown) {
		$k_clients = array_column($unknown, 'clients');
		$k_ip = array_map(fn($u) => (int)ip2long($u['ip']), $unknown);
		$k_port = array_column($unknown, 'port');
		array_multisort($k_clients, SORT_DESC, SORT_NUMERIC, $k_ip, SORT_ASC, SORT_NUMERIC,
		    $k_port, SORT_ASC, SORT_NUMERIC, $unknown);
	}
	return ['clients' => array_values($clients), 'unknown' => array_slice($unknown, 0, $ulist), 'pending' => $pending];
}

/* Write JSON via temp file in the same directory + rename. Invalid UTF-8 (e.g. a DHCP hostname) becomes U+FFFD. */
function gd_write_json_atomic(string $path, array $data): bool {
	$json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
	if ($json === false) {
		return false;
	}
	$tmp = @tempnam(dirname($path), '.gd');
	if ($tmp === false) {
		return false;
	}
	if (realpath(dirname($tmp)) !== realpath(dirname($path))) {
		@unlink($tmp);
		return false;
	}
	if (@file_put_contents($tmp, $json . "\n") === false || !@chmod($tmp, 0644) || !@rename($tmp, $path)) {
		@unlink($tmp);
		return false;
	}
	return true;
}

/* Read a JSON file; null if missing, unreadable, or not valid JSON array/object. */
function gd_read_json(string $path): ?array {
	$s = @file_get_contents($path);
	if ($s === false) {
		return null;
	}
	$d = json_decode($s, true);
	return is_array($d) ? $d : null;
}
