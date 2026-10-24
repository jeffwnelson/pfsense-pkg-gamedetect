<?php
/*
 * Copyright 2026 Jeff Nelson
 * Licensed under the Apache License, Version 2.0.
 *
 * Signatures tab "Reset to defaults": gd_status_signatures_differ() and
 * gamedetect_reset_signatures() (gamedetect_status_lib.inc) against a temp dir.
 */
require __DIR__ . '/assert.inc';
require __DIR__ . '/../files/usr/local/pkg/gamedetect_status_lib.inc';

$tmp = sys_get_temp_dir() . '/gamedetect-reset-' . getmypid();
register_shutdown_function(function () use ($tmp) {
	exec('rm -rf ' . escapeshellarg($tmp));
});
mkdir("$tmp/etc", 0755, true);
$default = "$tmp/default.json";
$live = "$tmp/etc/signatures.json";
file_put_contents($default, "{\"default\":true}\n");

/* signatures_differ */
assert_true(gd_status_signatures_differ($live, $default), 'signatures_differ missing live file -> true (offer reset)');
copy($default, $live);
if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
	echo "skip signatures_differ unreadable live (running as root)\n";
} else {
	chmod($live, 0000);
	assert_true(gd_status_signatures_differ($live, $default), 'signatures_differ unreadable live file -> true (offer reset)');
	chmod($live, 0644);
}
assert_true(!gd_status_signatures_differ($live, $default), 'signatures_differ identical -> false');
file_put_contents($live, "{\"custom\":true}\n");
assert_true(gd_status_signatures_differ($live, $default), 'signatures_differ edited -> true');
assert_true(!gd_status_signatures_differ($live, "$tmp/nope.json"), 'signatures_differ missing default -> false');

/* reset_signatures_backs_up_and_replaces */
$now = gmmktime(21, 4, 5, 10, 23, 2026);
$r = gamedetect_reset_signatures($live, $default, $now);
assert_true($r['ok'] === true && $r['error'] === null, 'reset_signatures ok (' . ($r['error'] ?? '') . ')');
assert_eq("$live.20261023T210405Z.bak", $r['backup'], 'reset_signatures backup path is UTC timestamped');
assert_eq("{\"custom\":true}\n", @file_get_contents((string)$r['backup']), 'reset_signatures backup holds the edited file');
assert_eq("{\"default\":true}\n", file_get_contents($live), 'reset_signatures live now equals default');
assert_eq('0644', substr(sprintf('%o', fileperms($live)), -4), 'reset_signatures live mode 0644');
assert_eq([], glob("$tmp/etc/.gd*") ?: [], 'reset_signatures leaves no temp file');

/* A second reset in the same second must not overwrite the first backup. */
file_put_contents($live, "{\"custom\":2}\n");
$r2 = gamedetect_reset_signatures($live, $default, $now);
assert_true($r2['ok'] && $r2['backup'] !== $r['backup'], 'reset_signatures same-second backup gets a new name');
assert_eq("{\"custom\":true}\n", file_get_contents((string)$r['backup']), 'reset_signatures first backup kept');
assert_eq("{\"custom\":2}\n", @file_get_contents((string)$r2['backup']), 'reset_signatures second backup holds second edit');

/* Live file absent: installs the default, no backup. */
unlink($live);
$r = gamedetect_reset_signatures($live, $default, $now);
assert_true($r['ok'] && $r['backup'] === null, 'reset_signatures absent live -> no backup');
assert_eq("{\"default\":true}\n", file_get_contents($live), 'reset_signatures absent live -> default installed');

/* Missing default: error, live untouched, no backup. */
file_put_contents($live, "{\"custom\":3}\n");
$before = glob("$live.*.bak");
$r = gamedetect_reset_signatures($live, "$tmp/nope.json", $now);
assert_true($r['ok'] === false && is_string($r['error']) && $r['error'] !== '', 'reset_signatures missing default -> error');
assert_eq("{\"custom\":3}\n", file_get_contents($live), 'reset_signatures missing default -> live untouched');
assert_eq($before, glob("$live.*.bak"), 'reset_signatures missing default -> no new backup');

/* Unwritable directory: error, nothing changed. */
if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
	echo "skip reset_signatures unwritable dir (running as root)\n";
} else {
	chmod("$tmp/etc", 0555);
	$r = gamedetect_reset_signatures($live, $default, $now + 1);
	chmod("$tmp/etc", 0755);
	assert_true($r['ok'] === false && is_string($r['error']), 'reset_signatures unwritable dir -> error');
	assert_eq("{\"custom\":3}\n", file_get_contents($live), 'reset_signatures unwritable dir -> live untouched');
}
