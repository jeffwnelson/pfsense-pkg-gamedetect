<?php
/*
 * Copyright 2026 Jeff Nelson
 * Licensed under the Apache License, Version 2.0.
 *
 * Status page update banner: gd_status_update_html() (gamedetect_status_lib.inc).
 */
require __DIR__ . '/assert.inc';
require __DIR__ . '/../files/usr/local/pkg/gamedetect_status_lib.inc';

$now = 1793000000;
$check = [
    'checked_at' => date(DATE_ATOM, $now - 3600), 'installed' => '0.2.0', 'latest' => '0.3.0', 'update_available' => true,
    'release_url' => 'https://github.com/jeffwnelson/pfsense-pkg-gamedetect/releases/tag/v0.3.0', 'error' => null,
];
$idle = ['state' => 'idle'];
$act = 'status_gamedetect.php?tab=signatures';

/* update available, admin */
$h = gd_status_update_html($check, $idle, true, $act, $now);
assert_true(str_contains($h, 'Game Detect v0.3.0 is available (installed v0.2.0).'), 'available: message');
assert_true(str_contains($h, 'href="https://github.com/jeffwnelson/pfsense-pkg-gamedetect/releases/tag/v0.3.0"') && str_contains($h, 'rel="noopener"'), 'available: release notes link, rel=noopener');
assert_true(str_contains($h, 'name="act" value="update"') && str_contains($h, 'name="act" value="update_check"'), 'available + admin: Update now and Check now');
assert_eq(2, substr_count($h, '<form method="post" action="status_gamedetect.php?tab=signatures"'), 'available + admin: two POST forms back to the current tab');
assert_true(str_contains($h, 'text-muted') && str_contains($h, date('Y-m-d H:i', $now - 3600)), 'available: checked_at shown quietly');

/* update available, no settings privilege: banner without buttons */
$h = gd_status_update_html($check, $idle, false, $act, $now);
assert_true(str_contains($h, 'is available') && !str_contains($h, '<form') && !str_contains($h, 'value="update"'), 'available, no privilege: no buttons');

/* nothing available: admins get a quiet line with Check now, others nothing */
$none = array_merge($check, ['latest' => '0.2.0', 'update_available' => false, 'error' => 'cannot fetch the latest release: timed out']);
$h = gd_status_update_html($none, $idle, true, $act, $now);
assert_true(!str_contains($h, 'is available') && str_contains($h, 'value="update_check"') && !str_contains($h, 'value="update"'), 'none + admin: Check now only');
assert_true(str_contains($h, 'text-muted') && str_contains($h, 'cannot fetch the latest release: timed out'), 'none + admin: error shown quietly');
assert_eq('', gd_status_update_html($none, $idle, false, $act, $now), 'none, no privilege: nothing');
assert_true(str_contains(gd_status_update_html([], $idle, true, $act, $now), 'value="update_check"'), 'never checked + admin: Check now');

/* running */
$run = ['state' => 'running', 'from' => '0.2.0', 'to' => '0.3.0', 'started_at' => date(DATE_ATOM, $now - 5), 'finished_at' => null, 'log' => '', 'error' => null];
$h = gd_status_update_html($check, $run, true, $act, $now);
assert_true(str_contains($h, 'Updating to v0.3.0') && str_contains($h, 'this page will reload when done') && str_contains($h, 'id="gd-update-running"'), 'running: message');
assert_true(!str_contains($h, 'value="update"') && !str_contains($h, 'is available'), 'running: no Update now, no available banner');

/* done / failed results, shown for a day */
$done = array_merge($run, ['state' => 'done', 'finished_at' => date(DATE_ATOM, $now - 60), 'log' => 'ok']);
$h = gd_status_update_html(array_merge($check, ['installed' => '0.3.0', 'update_available' => false]), $done, true, $act, $now);
assert_true(str_contains($h, 'alert-success') && str_contains($h, 'v0.3.0'), 'done: success');
$h = gd_status_update_html($check, array_merge($done, ['finished_at' => date(DATE_ATOM, $now - 90000)]), true, $act, $now);
assert_true(!str_contains($h, 'alert-success'), 'done: result hidden after a day');
$failed = array_merge($run, ['state' => 'failed', 'finished_at' => date(DATE_ATOM, $now - 60), 'error' => 'checksum mismatch', 'log' => "pkg: <b>boom</b>"]);
$h = gd_status_update_html($check, $failed, true, $act, $now);
assert_true(str_contains($h, 'alert-danger') && str_contains($h, 'checksum mismatch'), 'failed: error shown');
assert_true(str_contains($h, '<details>') && str_contains($h, '<pre>pkg: &lt;b&gt;boom&lt;/b&gt;</pre>'), 'failed: log in a collapsed, escaped <pre>');
assert_true(str_contains($h, 'value="update"'), 'failed: Update now offered again while still available');

/* everything escaped; only github release URLs become links */
$evil = array_merge($check, [
    'latest' => '0.3.0', 'installed' => '<script>x</script>',
    'release_url' => 'javascript:alert(1)', 'error' => '"><img src=x onerror=alert(1)>',
]);
$h = gd_status_update_html($evil, $idle, true, '"><x', $now);
assert_true(!str_contains($h, '<script>') && !str_contains($h, '<img') && !str_contains($h, '"><x'), 'hostile values escaped');
assert_true(!str_contains($h, 'javascript:'), 'non-GitHub release_url not linked');
$h = gd_status_update_html(array_merge($check, ['latest' => '0.3.0"><b>']), $idle, true, $act, $now);
assert_true(!str_contains($h, '<b>') && !str_contains($h, 'is available'), 'invalid latest -> no available banner');
$h = gd_status_update_html($check, array_merge($failed, ['error' => '<i>x</i>', 'to' => '<u>']), true, $act, $now);
assert_true(!str_contains($h, '<i>') && !str_contains($h, '<u>'), 'failed error/to escaped');
