<?php
/*
 * Copyright 2026 Jeff Nelson
 * Licensed under the Apache License, Version 2.0.
 *
 * Test stub for pfSense's util.inc. mwexec_bg() records the command in
 * $GLOBALS['stub_mwexec_bg'] instead of running it.
 */
function mwexec_bg(string $command, bool $clearsigmask = false, bool $nohup = true): void {
	$GLOBALS['stub_mwexec_bg'][] = $command;
}

function mwexec(string $command, bool $nologentry = false, bool $clearsigmask = false, bool $background = false): int {
	$GLOBALS['stub_mwexec'][] = $command;
	return 0;
}

function log_error(string $error): void {
	$GLOBALS['stub_log'][] = $error;
}


function is_ipaddrv4($ipaddr): bool {
	return is_string($ipaddr) && filter_var($ipaddr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
}

/* pfSense: 4 for a valid IPv4 subnet, 6 for IPv6, false otherwise. */
function is_subnet($subnet) {
	if (!is_string($subnet) || !preg_match('#^(.+)/(\d+)$#', $subnet, $m)) {
		return false;
	}
	if (is_ipaddrv4($m[1])) {
		return (int)$m[2] <= 32 ? 4 : false;
	}
	if (filter_var($m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
		return (int)$m[2] <= 128 ? 6 : false;
	}
	return false;
}
