<?php
/*
 * Copyright 2026 Jeff Nelson
 * Licensed under the Apache License, Version 2.0.
 *
 * signatures.default.history lists the sha256 of every earlier shipped
 * signatures.default.json, so pkg-install.sh can tell an unedited live file
 * (safe to replace on upgrade) from an operator's edits. The current default
 * need not be listed. When git history is available, every earlier committed
 * version of the default must be listed: change the default, add the hash of
 * the version it replaces.
 */
require __DIR__.'/assert.inc';

$share = __DIR__.'/../files/usr/local/share/pfSense-pkg-gamedetect';
$rel = 'files/usr/local/share/pfSense-pkg-gamedetect/signatures.default.json';
$lines = is_file("$share/signatures.default.history") ? file("$share/signatures.default.history", FILE_IGNORE_NEW_LINES) : [];
$hashes = [];
$bad = [];
foreach ($lines as $l) {
	if ($l === '' || $l[0] === '#') {
		continue;
	}
	if (preg_match('/^[0-9a-f]{64}$/', $l)) {
		$hashes[$l] = true;
	} else {
		$bad[] = $l;
	}
}
assert_true(count($hashes) > 0, 'history_non_empty');
assert_eq([], $bad, 'history_lines_are_hashes_or_comments');
assert_true(isset($hashes['9d947295f713775470379dda81a2c4830e2d7857dafb6eb7930951d55e50f628']), 'history_has_v0_1_0_live_seed');
$current = hash_file('sha256', "$share/signatures.default.json");
assert_true(is_string($current), 'history_current_default_readable');

$root = realpath(__DIR__.'/..');
$git = trim((string)@shell_exec('command -v git 2>/dev/null'));
$inside = $git !== '' ? trim((string)@shell_exec('git -C ' . escapeshellarg($root) . ' rev-parse --is-inside-work-tree 2>/dev/null')) : '';
if ($inside !== 'true') {
	echo "skip history_lists_every_committed_default (no git work tree)\n";
	exit;
}
$commits = preg_split('/\s+/', trim((string)shell_exec('git -C ' . escapeshellarg($root) . ' log --format=%H -- ' . escapeshellarg($rel) . ' 2>/dev/null')), -1, PREG_SPLIT_NO_EMPTY);
foreach ($commits as $c) {
	$body = shell_exec('git -C ' . escapeshellarg($root) . ' show ' . escapeshellarg("$c:$rel") . ' 2>/dev/null');
	if (!is_string($body) || $body === '') {
		continue;
	}
	$h = hash('sha256', $body);
	if ($h === $current) {
		continue;
	}
	assert_true(isset($hashes[$h]), 'history_lists_every_committed_default ' . substr($c, 0, 7) . " ($h)");
}
