<?php
/*
 * Copyright 2026 Jeff Nelson
 * Licensed under the Apache License, Version 2.0.
 *
 * Self-updater core (gamedetect_update_lib.inc): release parsing, SHA256SUMS,
 * version compare, hash verification, the check and run steps with injected
 * fetch/download/pkg callables (no network), the lock, the run view and the
 * POST action decision.
 */
require __DIR__ . '/assert.inc';
require __DIR__ . '/../files/usr/local/pkg/gamedetect_update_lib.inc';

$tmp = sys_get_temp_dir() . '/gamedetect-update-' . getmypid();
mkdir($tmp, 0755, true);
register_shutdown_function(function () use ($tmp) {
	exec('rm -rf ' . escapeshellarg($tmp));
});

const REPO = 'jeffwnelson/pfsense-pkg-gamedetect';
const DL = 'https://github.com/' . REPO . '/releases/download/v0.3.0/';
$release = json_decode(file_get_contents(__DIR__ . '/fixtures/github_release_latest.json'), true);
$sums = file_get_contents(__DIR__ . '/fixtures/SHA256SUMS');
$h15 = str_repeat('15', 32);
$h16 = str_repeat('16', 32);

/* parse_release: picks the versioned package for the running FreeBSD major */
$r = gd_update_parse_release($release, REPO, 16);
assert_eq(true, $r['ok'], 'parse_release ok (' . ($r['error'] ?? '') . ')');
assert_eq(null, $r['error'], 'parse_release no error');
assert_eq('0.3.0', $r['latest'], 'parse_release latest without the v');
assert_eq('pfSense-pkg-gamedetect-0.3.0-fbsd16.pkg', $r['asset_name'], 'parse_release fbsd16 asset name');
assert_eq(DL . 'pfSense-pkg-gamedetect-0.3.0-fbsd16.pkg', $r['asset_url'], 'parse_release fbsd16 asset url');
assert_eq(DL . 'SHA256SUMS', $r['sums_url'], 'parse_release SHA256SUMS url');
assert_eq('https://github.com/' . REPO . '/releases/tag/v0.3.0', $r['release_url'], 'parse_release html_url');
assert_true(str_contains((string)$r['notes'], 'self updater'), 'parse_release notes from body');
$r = gd_update_parse_release($release, REPO, 15);
assert_eq('pfSense-pkg-gamedetect-0.3.0-fbsd15.pkg', $r['asset_name'], 'parse_release fbsd15 asset name');
assert_eq(DL . 'pfSense-pkg-gamedetect-0.3.0-fbsd15.pkg', $r['asset_url'], 'parse_release fbsd15 asset url');
$r = gd_update_parse_release($release, REPO, 17);
assert_true($r['ok'] === false && str_contains((string)$r['error'], 'fbsd17'), 'parse_release no asset for the major -> error');

/* parse_release: bad tags */
foreach (['v0.2.1;rm', 'latest', '0.3.0', 'v0.3', 'v0.3.0-rc1', "v0.3.0\n", 'v0.3.0 ', 'V0.3.0', ''] as $tag) {
	$x = $release;
	$x['tag_name'] = $tag;
	$r = gd_update_parse_release($x, REPO, 16);
	assert_true($r['ok'] === false && is_string($r['error']) && $r['latest'] === null, 'parse_release rejects tag ' . json_encode($tag));
}
$x = $release;
$x['tag_name'] = ['v0.3.0'];
assert_eq(false, gd_update_parse_release($x, REPO, 16)['ok'], 'parse_release rejects a non-string tag');

/* parse_release: asset URLs on another host or repo are refused */
$bad_urls = [
    'https://evil.example/' . REPO . '/releases/download/v0.3.0/pfSense-pkg-gamedetect-0.3.0-fbsd16.pkg',
    'https://github.com/someone-else/pfsense-pkg-gamedetect/releases/download/v0.3.0/pfSense-pkg-gamedetect-0.3.0-fbsd16.pkg',
    'http://github.com/' . REPO . '/releases/download/v0.3.0/pfSense-pkg-gamedetect-0.3.0-fbsd16.pkg',
    'https://github.com.evil.example/' . REPO . '/releases/download/v0.3.0/pfSense-pkg-gamedetect-0.3.0-fbsd16.pkg',
    'https://github.com/' . REPO . '/releases/download/v0.3.0/../../../x/pfSense-pkg-gamedetect-0.3.0-fbsd16.pkg',
    'https://github.com/' . REPO . '/releases/download/v0.3.0/other.pkg',
];
foreach ($bad_urls as $u) {
	$x = $release;
	$x['assets'][1]['browser_download_url'] = $u;
	$r = gd_update_parse_release($x, REPO, 16);
	assert_true($r['ok'] === false && $r['asset_url'] === null, 'parse_release refuses asset url ' . $u);
}
$x = $release;
$x['assets'][4]['browser_download_url'] = 'https://evil.example/SHA256SUMS';
assert_eq(false, gd_update_parse_release($x, REPO, 16)['ok'], 'parse_release refuses a SHA256SUMS url on another host');
$x = $release;
$x['html_url'] = 'javascript:alert(1)';
$r = gd_update_parse_release($x, REPO, 16);
assert_true($r['ok'] === true && $r['release_url'] === null, 'parse_release drops a release_url that is not on the repo');

/* parse_release: missing assets */
$x = $release;
unset($x['assets'][1]);
$r = gd_update_parse_release($x, REPO, 16);
assert_true($r['ok'] === false && str_contains((string)$r['error'], 'pfSense-pkg-gamedetect-0.3.0-fbsd16.pkg'), 'parse_release missing fbsd16 asset -> error');
$x = $release;
unset($x['assets'][4]);
$r = gd_update_parse_release($x, REPO, 16);
assert_true($r['ok'] === false && str_contains((string)$r['error'], 'SHA256SUMS'), 'parse_release missing SHA256SUMS -> error');
$x = $release;
$x['assets'] = 'nope';
assert_eq(false, gd_update_parse_release($x, REPO, 16)['ok'], 'parse_release non-array assets -> error');
assert_eq(false, gd_update_parse_release([], REPO, 16)['ok'], 'parse_release empty payload -> error');
$x = $release;
$x['draft'] = true;
assert_eq(false, gd_update_parse_release($x, REPO, 16)['ok'], 'parse_release refuses a draft');
$x = $release;
$x['prerelease'] = true;
assert_eq(false, gd_update_parse_release($x, REPO, 16)['ok'], 'parse_release refuses a prerelease');
assert_eq(false, gd_update_parse_release($release, 'not a repo', 16)['ok'], 'parse_release invalid repo -> error');

/* notes are truncated to 4 KB on a UTF-8 boundary */
$x = $release;
$x['body'] = str_repeat('é', 3000);
$n = gd_update_parse_release($x, REPO, 16)['notes'];
assert_true(strlen($n) <= 4096 && mb_check_encoding($n, 'UTF-8'), 'parse_release notes truncated to 4 KB, valid UTF-8');
$x['body'] = null;
assert_eq('', gd_update_parse_release($x, REPO, 16)['notes'], 'parse_release null body -> empty notes');

/* parse_sums: sha256 -r and sha256sum formats */
assert_eq($h16, gd_update_parse_sums($sums, 'pfSense-pkg-gamedetect-0.3.0-fbsd16.pkg'), 'parse_sums sha256 -r format');
assert_eq($h15, gd_update_parse_sums($sums, 'pfSense-pkg-gamedetect-0.3.0-fbsd15.pkg'), 'parse_sums picks the right line');
$gnu = strtoupper($h16) . "  pfSense-pkg-gamedetect-0.3.0-fbsd16.pkg\r\n" . $h15 . " *pfSense-pkg-gamedetect-0.3.0-fbsd15.pkg\n";
assert_eq($h16, gd_update_parse_sums($gnu, 'pfSense-pkg-gamedetect-0.3.0-fbsd16.pkg'), 'parse_sums sha256sum text format (two spaces, CRLF, upper-case hex)');
assert_eq($h15, gd_update_parse_sums($gnu, 'pfSense-pkg-gamedetect-0.3.0-fbsd15.pkg'), 'parse_sums sha256sum binary format (*name)');
assert_eq($h16, gd_update_parse_sums("SHA256 (pfSense-pkg-gamedetect-0.3.0-fbsd16.pkg) = $h16\n", 'pfSense-pkg-gamedetect-0.3.0-fbsd16.pkg'), 'parse_sums BSD tag format');
assert_null(gd_update_parse_sums($sums, 'pfSense-pkg-gamedetect-0.3.0-fbsd17.pkg'), 'parse_sums missing name -> null');
assert_null(gd_update_parse_sums("$h16 xpfSense-pkg-gamedetect-0.3.0-fbsd16.pkg\n", 'pfSense-pkg-gamedetect-0.3.0-fbsd16.pkg'), 'parse_sums needs the exact name');
assert_null(gd_update_parse_sums("abc pfSense-pkg-gamedetect-0.3.0-fbsd16.pkg\n", 'pfSense-pkg-gamedetect-0.3.0-fbsd16.pkg'), 'parse_sums short hash -> null');
assert_null(gd_update_parse_sums('', 'x.pkg'), 'parse_sums empty -> null');

/* is_newer */
assert_eq(true, gd_update_is_newer('0.2.0', '0.3.0'), 'is_newer 0.3.0 > 0.2.0');
assert_eq(true, gd_update_is_newer('0.2.9', '0.2.10'), 'is_newer 0.2.10 > 0.2.9');
assert_eq(false, gd_update_is_newer('0.2.10', '0.2.9'), 'is_newer 0.2.9 < 0.2.10');
assert_eq(false, gd_update_is_newer('0.3.0', '0.3.0'), 'is_newer equal -> false');
assert_eq(true, gd_update_is_newer('0.9.9', '1.0.0'), 'is_newer 1.0.0 > 0.9.9');
assert_eq(false, gd_update_is_newer('0.2.0', 'latest'), 'is_newer invalid latest -> false');
assert_eq(false, gd_update_is_newer('', '0.3.0'), 'is_newer unknown installed -> false');
assert_eq(false, gd_update_is_newer('%%PKGVERSION%%', '0.3.0'), 'is_newer unsubstituted placeholder -> false');

/* verify */
$f = "$tmp/a.pkg";
file_put_contents($f, 'package bytes');
$good = hash('sha256', 'package bytes');
assert_eq(true, gd_update_verify($f, $good), 'verify matching hash');
assert_eq(true, gd_update_verify($f, strtoupper($good)), 'verify is case-insensitive');
assert_eq(false, gd_update_verify($f, $h16), 'verify wrong hash -> false');
assert_eq(false, gd_update_verify($f, ''), 'verify empty hash -> false');
assert_eq(false, gd_update_verify($f, substr($good, 0, 63)), 'verify short hash -> false');
assert_eq(false, gd_update_verify("$tmp/missing.pkg", $good), 'verify missing file -> false');

/* FreeBSD major and installed version helpers */
assert_eq(16, gd_update_freebsd_major('16.0-RELEASE-p1'), 'freebsd_major from uname -r');
assert_eq(15, gd_update_freebsd_major('15.0-CURRENT'), 'freebsd_major 15');
assert_null(gd_update_freebsd_major('garbage'), 'freebsd_major garbage -> null');
file_put_contents("$tmp/info.xml", "<?xml version=\"1.0\"?>\n<pfsensepkgs><package><name>gamedetect</name><version>0.2.0</version></package></pfsensepkgs>\n");
assert_eq('0.2.0', gd_update_info_version("$tmp/info.xml"), 'info_version from a built info.xml');
assert_null(gd_update_info_version(__DIR__ . '/../files/usr/local/share/pfSense-pkg-gamedetect/info.xml'), 'info_version unsubstituted placeholder -> null');
assert_null(gd_update_info_version("$tmp/none.xml"), 'info_version missing file -> null');

/* check: injected fetcher, update.json written atomically */
$state = "$tmp/db/update.json";
$calls = [];
$fetch_ok = function (string $url, int $max) use (&$calls, $release, $sums): array {
	$calls[] = [$url, $max];
	if ($url === 'https://api.github.com/repos/' . REPO . '/releases/latest') {
		return ['ok' => true, 'body' => json_encode($release), 'error' => null];
	}
	if ($url === DL . 'SHA256SUMS') {
		return ['ok' => true, 'body' => $sums, 'error' => null];
	}
	return ['ok' => false, 'body' => '', 'error' => "unexpected $url"];
};
$opts = ['repo' => REPO, 'major' => 16, 'installed' => '0.2.0', 'state_path' => $state, 'fetch' => $fetch_ok, 'now' => 1793000000];
$s = gd_update_check($opts);
$disk = json_decode((string)@file_get_contents($state), true);
assert_eq($s, $disk, 'check writes the returned state to update.json');
assert_eq([
    'checked_at' => date(DATE_ATOM, 1793000000),
    'installed' => '0.2.0',
    'latest' => '0.3.0',
    'update_available' => true,
    'release_url' => 'https://github.com/' . REPO . '/releases/tag/v0.3.0',
    'notes' => $release['body'],
    'asset_url' => DL . 'pfSense-pkg-gamedetect-0.3.0-fbsd16.pkg',
    'asset_name' => 'pfSense-pkg-gamedetect-0.3.0-fbsd16.pkg',
    'sha256' => $h16,
    'error' => null,
], $s, 'check success state');
assert_eq(['https://api.github.com/repos/' . REPO . '/releases/latest', DL . 'SHA256SUMS'], array_column($calls, 0), 'check fetches releases/latest then SHA256SUMS');
assert_eq(65536, $calls[1][1], 'check caps SHA256SUMS at 64 KB');
assert_eq([], glob("$tmp/db/.gd*") ?: [], 'check leaves no temp file');

/* check: network failure keeps the previous result and sets error */
$fetch_down = fn(string $url, int $max): array => ['ok' => false, 'body' => '', 'error' => 'connection timed out'];
$s = gd_update_check(array_merge($opts, ['fetch' => $fetch_down, 'now' => 1793086400]));
assert_eq(['0.3.0', true, DL . 'pfSense-pkg-gamedetect-0.3.0-fbsd16.pkg', $h16, date(DATE_ATOM, 1793086400)],
    [$s['latest'], $s['update_available'], $s['asset_url'], $s['sha256'], $s['checked_at']], 'check failure keeps previous latest/update_available, new checked_at');
assert_true(is_string($s['error']) && str_contains($s['error'], 'connection timed out'), 'check failure sets error');
/* ...but update_available follows the installed version (updated meanwhile) */
$s = gd_update_check(array_merge($opts, ['fetch' => $fetch_down, 'installed' => '0.3.0']));
assert_eq([false, '0.3.0'], [$s['update_available'], $s['installed']], 'check failure after an update -> update_available recomputed false');

/* check: bad JSON / bad release / missing sums line / sums download failure */
$s = gd_update_check(array_merge($opts, ['fetch' => fn($u, $m) => ['ok' => true, 'body' => '<html>', 'error' => null]]));
assert_true(is_string($s['error']) && $s['latest'] === '0.3.0', 'check bad JSON -> error, previous kept');
$x = $release;
$x['tag_name'] = 'v0.4.0;reboot';
$s = gd_update_check(array_merge($opts, ['fetch' => fn($u, $m) => ['ok' => true, 'body' => json_encode($x), 'error' => null]]));
assert_true(is_string($s['error']) && $s['latest'] === '0.3.0', 'check bad tag -> error, previous kept');
$s = gd_update_check(array_merge($opts, ['fetch' => function ($u, $m) use ($release) {
	return str_ends_with($u, 'SHA256SUMS') ? ['ok' => true, 'body' => "deadbeef x\n", 'error' => null]
	    : ['ok' => true, 'body' => json_encode($release), 'error' => null];
}]));
assert_true(is_string($s['error']) && str_contains($s['error'], 'SHA256SUMS'), 'check hash missing from SHA256SUMS -> error');
$s = gd_update_check(array_merge($opts, ['fetch' => function ($u, $m) use ($release) {
	return str_ends_with($u, 'SHA256SUMS') ? ['ok' => false, 'body' => '', 'error' => 'HTTP 404']
	    : ['ok' => true, 'body' => json_encode($release), 'error' => null];
}]));
assert_true(is_string($s['error']) && str_contains($s['error'], 'HTTP 404'), 'check SHA256SUMS download failure -> error');

/* check: no previous state + failure -> nothing available */
$s = gd_update_check(array_merge($opts, ['state_path' => "$tmp/db2/update.json", 'fetch' => $fetch_down]));
assert_eq([null, false], [$s['latest'], $s['update_available']], 'check first-ever failure -> latest null, no update');
assert_true(is_file("$tmp/db2/update.json"), 'check creates the state dir');

/* check: same version -> no update; invalid repo -> no fetch */
$s = gd_update_check(array_merge($opts, ['state_path' => "$tmp/db3/update.json", 'installed' => '0.3.0']));
assert_eq([false, null], [$s['update_available'], $s['error']], 'check installed == latest -> no update');
$calls = [];
$s = gd_update_check(array_merge($opts, ['state_path' => "$tmp/db3/update.json", 'repo' => 'bad repo']));
assert_true($calls === [] && is_string($s['error']), 'check invalid repo -> error, nothing fetched');

/* run: happy path, then the failure modes */
$pkg_bytes = 'fake package bytes';
$good_state = [
    'checked_at' => date(DATE_ATOM), 'installed' => '0.2.0', 'latest' => '0.3.0', 'update_available' => true,
    'release_url' => null, 'notes' => '', 'asset_url' => DL . 'pfSense-pkg-gamedetect-0.3.0-fbsd16.pkg',
    'asset_name' => 'pfSense-pkg-gamedetect-0.3.0-fbsd16.pkg', 'sha256' => hash('sha256', $pkg_bytes), 'error' => null,
];
function run_case(string $dir, array $state, string $bytes, int $pkg_rc = 0, array $extra = []): array {
	@mkdir("$dir/tmp", 0755, true);
	file_put_contents("$dir/update.json", json_encode($state));
	$log = ['download' => [], 'pkg' => [], 'recheck' => 0, 'existed' => []];
	$o = array_merge([
	    'state_path' => "$dir/update.json",
	    'run_path' => "$dir/update-run.json",
	    'repo' => REPO,
	    'major' => 16,
	    'tmp_dir' => "$dir/tmp",
	    'now' => 1793000000,
	    'download' => function (string $url, string $dest, int $max) use (&$log, $bytes): array {
		    $log['download'][] = [$url, $dest, $max];
		    file_put_contents($dest, $bytes);
		    return ['ok' => true, 'error' => null];
	    },
	    'pkg_add' => function (string $file) use (&$log, $pkg_rc): array {
		    $log['pkg'][] = $file;
		    $log['existed'][] = is_file($file);
		    return ['rc' => $pkg_rc, 'output' => "Installing pfSense-pkg-gamedetect-0.3.0...\n" . ($pkg_rc ? "pkg: boom\n" : "done\n")];
	    },
	    'recheck' => function () use (&$log): void {
		    $log['recheck']++;
	    },
	], $extra);
	$r = gd_update_run($o);
	$log['result'] = $r;
	$log['disk'] = json_decode((string)@file_get_contents("$dir/update-run.json"), true);
	$log['leftover'] = glob("$dir/tmp/*") ?: [];
	return $log;
}

$l = run_case("$tmp/run-ok", $good_state, $pkg_bytes);
assert_eq('done', $l['result']['state'], 'run ok -> done (' . ($l['result']['error'] ?? '') . ')');
assert_eq($l['result'], $l['disk'], 'run writes update-run.json');
assert_eq(['0.2.0', '0.3.0', null], [$l['disk']['from'], $l['disk']['to'], $l['disk']['error']], 'run ok from/to, no error');
assert_true(is_string($l['disk']['started_at']) && is_string($l['disk']['finished_at']), 'run ok timestamps');
assert_eq(DL . 'pfSense-pkg-gamedetect-0.3.0-fbsd16.pkg', $l['download'][0][0], 'run downloads asset_url');
assert_eq(20971520, $l['download'][0][2], 'run caps the download at 20 MB');
assert_true(preg_match('#^' . preg_quote("$tmp/run-ok/tmp/", '#') . 'gamedetect-update\.[0-9a-f]{16}\.pkg$#', $l['download'][0][1]) === 1, 'run temp file name gamedetect-update.<random>.pkg: ' . $l['download'][0][1]);
assert_eq([$l['download'][0][1]], $l['pkg'], 'run pkg add gets the verified temp file');
assert_eq([true], $l['existed'], 'run temp file exists during pkg add');
assert_eq([], $l['leftover'], 'run deletes the temp file');
assert_eq(1, $l['recheck'], 'run re-runs the check after installing');
assert_true(str_contains($l['disk']['log'], 'Installing pfSense-pkg-gamedetect-0.3.0'), 'run captures pkg output in log');

/* hash mismatch: pkg add never runs */
$l = run_case("$tmp/run-mismatch", $good_state, 'tampered bytes');
assert_eq('failed', $l['result']['state'], 'run hash mismatch -> failed');
assert_true(str_contains((string)$l['result']['error'], 'checksum'), 'run hash mismatch error mentions the checksum');
assert_eq([], $l['pkg'], 'run hash mismatch -> pkg add never called');
assert_eq(0, $l['recheck'], 'run hash mismatch -> no recheck');
assert_eq([], $l['leftover'], 'run hash mismatch -> temp file deleted');

/* oversize download */
$l = run_case("$tmp/run-big", $good_state, str_repeat('x', 2048), 0, ['max_bytes' => 1024]);
assert_eq(['failed', []], [$l['result']['state'], $l['pkg']], 'run oversize download -> failed, no pkg add');

/* update.json tampered or stale: refused before anything is downloaded */
$tampered = [
    'asset on another host' => ['asset_url' => 'https://evil.example/pfSense-pkg-gamedetect-0.3.0-fbsd16.pkg'],
    'asset in another repo' => ['asset_url' => 'https://github.com/x/y/releases/download/v0.3.0/pfSense-pkg-gamedetect-0.3.0-fbsd16.pkg'],
    'asset name for other version' => ['asset_name' => 'pfSense-pkg-gamedetect-0.2.0-fbsd16.pkg'],
    'asset name for other major' => ['asset_name' => 'pfSense-pkg-gamedetect-0.3.0-fbsd15.pkg',
        'asset_url' => DL . 'pfSense-pkg-gamedetect-0.3.0-fbsd15.pkg'],
    'url not matching the name' => ['asset_url' => DL . 'pfSense-pkg-gamedetect-fbsd16.pkg'],
    'bad hash' => ['sha256' => 'abc'],
    'bad latest' => ['latest' => '0.3.0;id'],
    'not newer' => ['latest' => '0.2.0', 'asset_name' => 'pfSense-pkg-gamedetect-0.2.0-fbsd16.pkg',
        'asset_url' => 'https://github.com/' . REPO . '/releases/download/v0.2.0/pfSense-pkg-gamedetect-0.2.0-fbsd16.pkg'],
];
$i = 0;
foreach ($tampered as $what => $patch) {
	$l = run_case("$tmp/run-t" . $i++, array_merge($good_state, $patch), $pkg_bytes);
	assert_true($l['result']['state'] === 'failed' && $l['download'] === [] && $l['pkg'] === [], "run refuses update.json with $what");
}
$l = run_case("$tmp/run-nostate", [], $pkg_bytes);
assert_true($l['result']['state'] === 'failed' && $l['download'] === [], 'run refuses without update.json content');

/* download failure and pkg add failure */
$l = run_case("$tmp/run-dlfail", $good_state, $pkg_bytes, 0, ['download' => fn($u, $d, $m) => ['ok' => false, 'error' => 'HTTP 404']]);
assert_true($l['result']['state'] === 'failed' && str_contains((string)$l['result']['error'], 'HTTP 404') && $l['leftover'] === [], 'run download failure -> failed, temp removed');
$l = run_case("$tmp/run-pkgfail", $good_state, $pkg_bytes, 1);
assert_true($l['result']['state'] === 'failed' && str_contains($l['result']['log'], 'pkg: boom') && $l['leftover'] === [], 'run pkg add failure -> failed with log, temp removed');

/* log is the last 4 KB */
$l = run_case("$tmp/run-biglog", $good_state, $pkg_bytes, 0, ['pkg_add' => fn($f) => ['rc' => 0, 'output' => str_repeat('a', 10000) . 'TAIL']]);
assert_true(strlen($l['result']['log']) <= 4096 && str_ends_with($l['result']['log'], 'TAIL'), 'run keeps the last 4 KB of output');

/* lock: acquire, refuse while held, stale recovery */
$lock = "$tmp/update.lock";
assert_eq(true, gd_update_lock_acquire($lock, getmypid()), 'lock acquired');
assert_eq(getmypid(), gd_update_lock_holder($lock), 'lock holder is the live pid');
assert_eq(false, gd_update_lock_acquire($lock, getmypid()), 'lock refused while held by a live pid');
gd_update_lock_release($lock, 12345);
assert_true(is_file($lock), 'lock release by another pid is ignored');
gd_update_lock_release($lock, getmypid());
assert_true(!file_exists($lock), 'lock released');
$dead = 999999;
while (gd_update_pid_alive($dead)) {
	$dead++;
}
file_put_contents($lock, "$dead\n");
assert_null(gd_update_lock_holder($lock), 'lock with a dead pid has no holder');
assert_eq(true, gd_update_lock_acquire($lock, getmypid()), 'stale lock (dead pid) recovered');
assert_eq(getmypid(), (int)trim(file_get_contents($lock)), 'recovered lock holds the new pid');
file_put_contents($lock, "garbage\n");
assert_eq(true, gd_update_lock_acquire($lock, getmypid()), 'stale lock (garbage) recovered');
file_put_contents($lock, getmypid() . "\n");
touch($lock, time() - 7200);
assert_null(gd_update_lock_holder($lock), 'lock older than an hour is stale even with a live pid');
unlink($lock);

/* run view */
$now = 1793000000;
$run = ['state' => 'running', 'started_at' => date(DATE_ATOM, $now - 10), 'finished_at' => null, 'from' => '0.2.0', 'to' => '0.3.0', 'log' => '', 'error' => null];
assert_eq('idle', gd_update_run_view(null, null, $now)['state'], 'view no run file -> idle');
assert_eq('idle', gd_update_run_view(['state' => 'weird'], null, $now)['state'], 'view unknown state -> idle');
assert_eq('running', gd_update_run_view($run, 4242, $now)['state'], 'view running with lock -> running');
assert_eq('running', gd_update_run_view($run, null, $now)['state'], 'view running, no lock yet, within grace -> running');
$v = gd_update_run_view(array_merge($run, ['started_at' => date(DATE_ATOM, $now - 600)]), null, $now);
assert_true($v['state'] === 'failed' && is_string($v['error']), 'view running without lock after grace -> failed');
assert_eq('done', gd_update_run_view(array_merge($run, ['state' => 'done']), null, $now)['state'], 'view done');
assert_eq('0.3.0', gd_update_run_view($run, 4242, $now)['to'], 'view carries to');

/* POST action decision: privilege first */
assert_eq('none', gd_update_post_action('GET', 'update', true), 'decision GET -> none');
assert_eq('none', gd_update_post_action('POST', null, true), 'decision no act -> none');
assert_eq('none', gd_update_post_action('POST', 'bogus', false), 'decision unknown act -> none');
assert_eq('none', gd_update_post_action('POST', ['update'], true), 'decision array act -> none');
foreach (['update', 'update_check', 'reset_signatures'] as $act) {
	assert_eq('forbidden', gd_update_post_action('POST', $act, false), "decision $act without settings privilege -> forbidden");
	assert_eq($act, gd_update_post_action('POST', $act, true), "decision $act with settings privilege -> $act");
}

/* exec: fixed argv, captured output, timeout */
$e = gd_update_exec(['/bin/sh', '-c', 'echo out; echo err >&2; exit 3'], 5);
assert_eq([3, false], [$e['rc'], $e['timed_out']], 'exec exit status');
assert_true(str_contains($e['output'], 'out') && str_contains($e['output'], 'err'), 'exec captures stdout and stderr');
$t0 = microtime(true);
$e = gd_update_exec(['/bin/sleep', '5'], 1);
assert_true($e['timed_out'] === true && microtime(true) - $t0 < 4, 'exec kills after the timeout');
$e = gd_update_exec(['/bin/echo', '$(id); rm -rf /'], 5);
assert_eq("\$(id); rm -rf /\n", $e['output'], 'exec passes arguments verbatim, no shell');
