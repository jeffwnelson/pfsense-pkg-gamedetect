<?php
/*
 * Copyright 2026 Jeff Nelson
 * Licensed under the Apache License, Version 2.0.
 *
 * Test stub for pfSense's system.inc: system_get_dhcpleases() returns the
 * PHP-serialized array in the file named by GD_STUB_LEASES (raw bytes, so
 * tests can feed non-UTF-8 hostnames).
 */
function system_get_dhcpleases(): array {
	$f = (string)getenv('GD_STUB_LEASES');
	$d = $f === '' ? false : @unserialize((string)@file_get_contents($f));
	return is_array($d) ? $d : ['lease' => []];
}
