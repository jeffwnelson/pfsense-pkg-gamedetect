<?php
/*
 * Copyright 2026 Jeff Nelson
 * Licensed under the Apache License, Version 2.0.
 *
 * pfSense-only helper run by gamedetectd: print {ip: hostname} JSON for DHCP
 * leases that carry a hostname (ISC or Kea, via system_get_dhcpleases()).
 * Prints {} when the lease API is unavailable. Active leases win over
 * expired ones for the same address.
 */
require_once('config.inc');
require_once('system.inc');

$out = [];
if (function_exists('system_get_dhcpleases')) {
	$leases = system_get_dhcpleases();
	$active = [];
	foreach ((array)($leases['lease'] ?? []) as $l) {
		$ip = (string)($l['ip'] ?? '');
		$name = trim((string)($l['hostname'] ?? ''));
		if ($name === '' || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
			continue;
		}
		$is_active = ($l['act'] ?? '') !== 'expired';
		if (!isset($out[$ip]) || ($is_active && !isset($active[$ip]))) {
			$out[$ip] = $name;
			if ($is_active) {
				$active[$ip] = true;
			}
		}
	}
}
echo json_encode((object)$out, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), "\n";
exit(0);
