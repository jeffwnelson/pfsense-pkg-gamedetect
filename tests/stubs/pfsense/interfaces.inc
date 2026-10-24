<?php
/*
 * Copyright 2026 Jeff Nelson
 * Licensed under the Apache License, Version 2.0.
 *
 * Test stub for pfSense's interfaces.inc. $GLOBALS['stub_ifaces'] maps a
 * friendly name (wan, opt1) to ['real' => 'ixl5', 'ip' => '1.2.3.4'|'',
 * 'subnet' => 24]. Every key is a configured interface.
 */
function get_real_interface(string $interface = 'wan', string $family = 'all', bool $realv6iface = false, bool $flush = true) {
	return $GLOBALS['stub_ifaces'][$interface]['real'] ?? $interface;
}

function get_interface_ip(string $interface = 'wan') {
	$ip = $GLOBALS['stub_ifaces'][$interface]['ip'] ?? '';
	return $ip === '' ? null : $ip;
}

function get_interface_subnet(string $interface = 'wan') {
	$bits = $GLOBALS['stub_ifaces'][$interface]['subnet'] ?? null;
	return $bits === null ? null : (string)$bits;
}

/* pfSense returns friendly => friendly for configured (enabled) interfaces. */
function get_configured_interface_list(bool $withdisabled = false): array {
	$names = array_keys($GLOBALS['stub_ifaces'] ?? []);
	return array_combine($names, $names);
}
