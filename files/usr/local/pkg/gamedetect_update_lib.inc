<?php
/*
 * Copyright 2026 Jeff Nelson
 * Licensed under the Apache License, Version 2.0.
 *
 * Self-updater core for pfSense-pkg-gamedetect. No pfSense dependencies.
 *
 * gd_update_check() reads GitHub's releases/latest for the repo, picks the
 * package built for the running FreeBSD major and its hash from the release's
 * SHA256SUMS, and writes update.json. gd_update_run() installs the release
 * recorded in update.json after verifying the download against that hash.
 * Network access and pkg(8) are injected as callables, so tests run without
 * either; the production callables are wired up in gamedetect.inc.
 */
require_once(__DIR__ . '/gamedetect_lib.inc');

const GD_UPDATE_PKG = 'pfSense-pkg-gamedetect';
const GD_UPDATE_SUMS = 'SHA256SUMS';
const GD_UPDATE_API_MAX = 1048576;      /* releases/latest JSON */
const GD_UPDATE_SUMS_MAX = 65536;       /* SHA256SUMS */
const GD_UPDATE_PKG_MAX = 20971520;     /* package download */
const GD_UPDATE_NOTES_MAX = 4096;
const GD_UPDATE_LOG_MAX = 4096;
const GD_UPDATE_RUN_GRACE_S = 60;       /* a 'running' run file without a lock holder is dead after this */
const GD_UPDATE_LOCK_MAX_AGE_S = 3600;  /* a lock this old is stale even if its pid is alive (pid reuse) */

/* "owner/name" GitHub repo slug. */
function gd_update_valid_repo(string $repo): bool {
	return preg_match('#^[A-Za-z0-9][A-Za-z0-9-]{0,38}/[A-Za-z0-9._-]{1,100}\z#', $repo) === 1;
}

/* Strict "x.y.z" (digits only), else null. */
function gd_update_semver($v): ?string {
	return is_string($v) && preg_match('/^\d{1,6}\.\d{1,6}\.\d{1,6}\z/', $v) === 1 ? $v : null;
}

/* Prefix every accepted download URL must start with. */
function gd_update_download_prefix(string $repo): string {
	return 'https://github.com/' . $repo . '/releases/download/';
}

/*
 * True when $url is a release download of $name in $repo: under the
 * releases/download/ prefix, ending in "/<name>", with one tag path segment
 * and no characters outside a conservative URL set (no percent-encoding, so
 * no encoded "../").
 */
function gd_update_url_ok($url, string $repo, string $name): bool {
	if (!is_string($url) || !gd_update_valid_repo($repo)) {
		return false;
	}
	$prefix = gd_update_download_prefix($repo);
	if (!str_starts_with($url, $prefix)) {
		return false;
	}
	$rest = substr($url, strlen($prefix));
	return preg_match('#^[A-Za-z0-9._+-]+/[A-Za-z0-9._+-]+\z#', $rest) === 1
	    && !str_contains($rest, '..')
	    && str_ends_with($rest, '/' . $name);
}

/* Versioned package file name for a release and FreeBSD major. */
function gd_update_asset_name(string $version, int $major): string {
	return GD_UPDATE_PKG . '-' . $version . '-fbsd' . $major . '.pkg';
}

/* At most $max bytes of $s, cut on a UTF-8 character boundary. */
function gd_update_truncate(string $s, int $max): string {
	if (strlen($s) <= $max) {
		return $s;
	}
	return function_exists('mb_strcut') ? mb_strcut($s, 0, $max, 'UTF-8') : substr($s, 0, $max);
}

/* The last $max bytes of $s. */
function gd_update_tail(string $s, int $max): string {
	return strlen($s) <= $max ? $s : substr($s, -$max);
}

/*
 * Parse a GitHub releases/latest payload. Returns
 * ['ok', 'latest', 'asset_url', 'asset_name', 'sums_url', 'release_url', 'notes', 'error'];
 * on failure ok is false, error says why, and latest/asset_url/sums_url are null.
 * release_url is null unless it is a page of $repo on github.com.
 */
function gd_update_parse_release(array $release, string $repo, int $major): array {
	$out = ['ok' => false, 'latest' => null, 'asset_url' => null, 'asset_name' => null, 'sums_url' => null,
	    'release_url' => null, 'notes' => '', 'error' => null];
	$fail = function (string $why) use ($out): array {
		$out['error'] = $why;
		return $out;
	};
	if (!gd_update_valid_repo($repo)) {
		return $fail('invalid repository name');
	}
	$tag = $release['tag_name'] ?? null;
	if (!is_string($tag) || preg_match('/^v(\d{1,6})\.(\d{1,6})\.(\d{1,6})\z/', $tag, $m) !== 1) {
		return $fail('release tag is not vX.Y.Z');
	}
	if (!empty($release['draft']) || !empty($release['prerelease'])) {
		return $fail('latest release is a draft or prerelease');
	}
	$latest = "{$m[1]}.{$m[2]}.{$m[3]}";
	$name = gd_update_asset_name($latest, $major);
	$assets = $release['assets'] ?? null;
	if (!is_array($assets)) {
		return $fail('release has no asset list');
	}
	$pkg_url = $sums_url = null;
	foreach ($assets as $a) {
		if (!is_array($a) || !is_string($a['name'] ?? null)) {
			continue;
		}
		if ($a['name'] === $name) {
			$pkg_url = gd_update_url_ok($a['browser_download_url'] ?? null, $repo, $name) ? $a['browser_download_url'] : false;
		} elseif ($a['name'] === GD_UPDATE_SUMS) {
			$sums_url = gd_update_url_ok($a['browser_download_url'] ?? null, $repo, GD_UPDATE_SUMS) ? $a['browser_download_url'] : false;
		}
	}
	if ($pkg_url === null) {
		return $fail("release v{$latest} has no {$name} (fbsd{$major})");
	}
	if ($pkg_url === false) {
		return $fail("{$name} download URL is not a release download of {$repo}");
	}
	if ($sums_url === null) {
		return $fail("release v{$latest} has no " . GD_UPDATE_SUMS);
	}
	if ($sums_url === false) {
		return $fail(GD_UPDATE_SUMS . " download URL is not a release download of {$repo}");
	}
	$html = $release['html_url'] ?? null;
	$out['release_url'] = is_string($html) && str_starts_with($html, 'https://github.com/' . $repo . '/releases/')
	    && preg_match('#^[A-Za-z0-9./:_%+-]+\z#', $html) === 1 ? $html : null;
	$out['notes'] = gd_update_truncate(is_string($release['body'] ?? null) ? $release['body'] : '', GD_UPDATE_NOTES_MAX);
	$out['ok'] = true;
	$out['latest'] = $latest;
	$out['asset_name'] = $name;
	$out['asset_url'] = $pkg_url;
	$out['sums_url'] = $sums_url;
	return $out;
}

/*
 * Lower-case sha256 of $asset_name from a checksum list, or null. Accepts
 * `sha256 -r` ("<hex> <name>"), sha256sum ("<hex>  <name>" / "<hex> *<name>")
 * and BSD tag ("SHA256 (<name>) = <hex>") lines.
 */
function gd_update_parse_sums(string $sums, string $asset_name): ?string {
	foreach (preg_split('/\r?\n/', $sums) as $line) {
		if (preg_match('/^([0-9A-Fa-f]{64}) [ *]?(.+)\z/', $line, $m) === 1 && $m[2] === $asset_name) {
			return strtolower($m[1]);
		}
		if (preg_match('/^SHA256 \((.+)\) = ([0-9A-Fa-f]{64})\z/', $line, $m) === 1 && $m[1] === $asset_name) {
			return strtolower($m[2]);
		}
	}
	return null;
}

/* True when both are strict x.y.z and $latest is the higher version. */
function gd_update_is_newer(string $installed, string $latest): bool {
	return gd_update_semver($installed) !== null && gd_update_semver($latest) !== null
	    && version_compare($latest, $installed, '>');
}

/* True when $file's sha256 equals $sha256 (64 hex digits, any case). */
function gd_update_verify(string $file, string $sha256): bool {
	if (preg_match('/^[0-9A-Fa-f]{64}\z/', $sha256) !== 1 || !is_file($file)) {
		return false;
	}
	$h = @hash_file('sha256', $file);
	return is_string($h) && hash_equals(strtolower($sha256), $h);
}

/* FreeBSD major version from a uname -r string ("16.0-RELEASE-p1" -> 16). */
function gd_update_freebsd_major(string $release): ?int {
	return preg_match('/^(\d{1,3})\./', $release, $m) === 1 ? (int)$m[1] : null;
}

/* Package version from the installed info.xml (substituted at build time), or null. */
function gd_update_info_version(string $path): ?string {
	$s = @file_get_contents($path, false, null, 0, 65536);
	if (!is_string($s) || preg_match('#<version>\s*([^<]*?)\s*</version>#', $s, $m) !== 1) {
		return null;
	}
	return gd_update_semver($m[1]);
}

/*
 * update.json as it applies now: installed set to $installed and
 * update_available recomputed from it and latest (the stored bool goes stale
 * after a manual upgrade).
 */
function gd_update_effective(array $check, string $installed): array {
	$check['installed'] = $installed;
	$latest = gd_update_semver($check['latest'] ?? null);
	$check['update_available'] = $latest !== null && gd_update_is_newer($installed, $latest);
	return $check;
}

/* gd_write_json_atomic(), creating the directory first. */
function gd_update_write(string $path, array $data): bool {
	$dir = dirname($path);
	if (!is_dir($dir)) {
		@mkdir($dir, 0755, true);
	}
	return gd_write_json_atomic($path, $data);
}

/*
 * One update check. $o: repo, major (int), installed (version string),
 * state_path (update.json), fetch (callable(string $url, int $max_bytes):
 * array{ok, body, error, status}), now (unix time, optional). HTTP 403/429
 * from the API is reported as GitHub rate limiting. Writes and returns
 * {checked_at, installed, latest, update_available, release_url, notes,
 * asset_url, asset_name, sha256, error}. On failure error is set and the
 * previous release fields are kept; update_available is recomputed from the
 * previous latest and the current installed version.
 */
function gd_update_check(array $o): array {
	$now = (int)($o['now'] ?? time());
	$installed = (string)($o['installed'] ?? '');
	$prev = gd_read_json((string)$o['state_path']) ?? [];
	$keys = ['latest', 'release_url', 'notes', 'asset_url', 'asset_name', 'sha256'];
	$s = ['checked_at' => date(DATE_ATOM, $now), 'installed' => $installed];
	foreach ($keys as $k) {
		$s[$k] = is_string($prev[$k] ?? null) ? $prev[$k] : null;
	}
	$s['latest'] = gd_update_semver($s['latest']);
	$s['notes'] ??= '';
	$s['update_available'] = $s['latest'] !== null && gd_update_is_newer($installed, $s['latest']);
	$s['error'] = null;
	$finish = function (?string $error) use (&$s, $o): array {
		$s['error'] = $error === null ? null : gd_update_truncate($error, 300);
		$order = ['checked_at', 'installed', 'latest', 'update_available', 'release_url', 'notes',
		    'asset_url', 'asset_name', 'sha256', 'error'];
		$s = array_merge(array_flip($order), $s);
		gd_update_write((string)$o['state_path'], $s);
		return $s;
	};

	$repo = (string)($o['repo'] ?? '');
	$major = (int)($o['major'] ?? 0);
	if (!gd_update_valid_repo($repo)) {
		return $finish('invalid repository name');
	}
	if ($major <= 0) {
		return $finish('cannot determine the FreeBSD version');
	}
	$fetch = $o['fetch'];
	$res = $fetch("https://api.github.com/repos/{$repo}/releases/latest", GD_UPDATE_API_MAX);
	if (empty($res['ok'])) {
		if (in_array((int)($res['status'] ?? 0), [403, 429], true)) {
			return $finish('GitHub rate limit reached, try again later');
		}
		return $finish('cannot fetch the latest release: ' . (string)($res['error'] ?? 'unknown error'));
	}
	$rel = json_decode((string)($res['body'] ?? ''), true);
	if (!is_array($rel)) {
		return $finish('the latest release response is not JSON');
	}
	$p = gd_update_parse_release($rel, $repo, $major);
	if (!$p['ok']) {
		return $finish((string)$p['error']);
	}
	$res = $fetch($p['sums_url'], GD_UPDATE_SUMS_MAX);
	if (empty($res['ok'])) {
		return $finish('cannot fetch ' . GD_UPDATE_SUMS . ': ' . (string)($res['error'] ?? 'unknown error'));
	}
	$sha = gd_update_parse_sums((string)($res['body'] ?? ''), $p['asset_name']);
	if ($sha === null) {
		return $finish(GD_UPDATE_SUMS . " has no hash for {$p['asset_name']}");
	}
	$s['latest'] = $p['latest'];
	$s['release_url'] = $p['release_url'];
	$s['notes'] = $p['notes'];
	$s['asset_url'] = $p['asset_url'];
	$s['asset_name'] = $p['asset_name'];
	$s['sha256'] = $sha;
	$s['update_available'] = gd_update_is_newer($installed, $p['latest']);
	return $finish(null);
}

/*
 * Install the release recorded in update.json. $o: state_path, run_path
 * (update-run.json), repo, major, tmp_dir, download (callable(string $url,
 * string $dest, int $max_bytes): array{ok, error}), pkg_add
 * (callable(string $file): array{rc, output}), recheck (callable(): void),
 * installed (optional: the version installed now; default update.json's),
 * now (optional), max_bytes (optional, default 20 MB). Never uses request
 * data: everything comes from update.json and is re-validated. The download
 * goes to tmp_dir/gamedetect-update.<random>.pkg, is checked against the
 * recorded sha256, installed, and deleted. Writes and returns
 * {state: running|done|failed, started_at, finished_at, from, to, log, error}.
 */
function gd_update_run(array $o): array {
	$now = fn(): string => date(DATE_ATOM, (int)($o['now'] ?? time()));
	$st = gd_read_json((string)$o['state_path']) ?? [];
	$repo = (string)($o['repo'] ?? '');
	$major = (int)($o['major'] ?? 0);
	$from = $o['installed'] ?? $st['installed'] ?? null;
	$from = is_string($from) ? $from : null;
	$to = gd_update_semver($st['latest'] ?? null);
	$run = ['state' => 'running', 'started_at' => $now(), 'finished_at' => null,
	    'from' => $from, 'to' => $to, 'log' => '', 'error' => null];
	$finish = function (string $state, ?string $error, string $log = '') use (&$run, $o, $now): array {
		$run['state'] = $state;
		$run['finished_at'] = $now();
		$run['error'] = $error;
		$run['log'] = gd_update_tail($log, GD_UPDATE_LOG_MAX);
		gd_update_write((string)$o['run_path'], $run);
		return $run;
	};

	$name = $to === null ? '' : gd_update_asset_name($to, $major);
	$url = $st['asset_url'] ?? null;
	$sha = is_string($st['sha256'] ?? null) ? strtolower($st['sha256']) : '';
	if ($to === null || $major <= 0) {
		return $finish('failed', 'no valid release recorded; run the update check first');
	}
	if ($from === null || !gd_update_is_newer($from, $to)) {
		return $finish('failed', "v{$to} is not newer than the installed version");
	}
	if (($st['asset_name'] ?? null) !== $name || !gd_update_url_ok($url, $repo, $name)) {
		return $finish('failed', 'the recorded download is not ' . $name . ' from ' . $repo . '; run the update check again');
	}
	if (preg_match('/^[0-9a-f]{64}\z/', $sha) !== 1) {
		return $finish('failed', 'no valid checksum recorded; run the update check again');
	}
	gd_update_write((string)$o['run_path'], $run);

	$dir = rtrim((string)$o['tmp_dir'], '/');
	$file = null;
	for ($i = 0; $i < 5 && $file === null; $i++) {
		$cand = $dir . '/gamedetect-update.' . bin2hex(random_bytes(8)) . '.pkg';
		$fh = @fopen($cand, 'x');
		if ($fh !== false) {
			fclose($fh);
			@chmod($cand, 0600);
			$file = $cand;
		}
	}
	if ($file === null) {
		return $finish('failed', "cannot create a temporary file in {$dir}");
	}
	$max = (int)($o['max_bytes'] ?? GD_UPDATE_PKG_MAX);
	$download = $o['download'];
	$dl = $download($url, $file, $max);
	clearstatcache(true, $file);
	if (empty($dl['ok'])) {
		@unlink($file);
		return $finish('failed', 'download failed: ' . (string)($dl['error'] ?? 'unknown error'));
	}
	if ((int)@filesize($file) > $max) {
		@unlink($file);
		return $finish('failed', "download is larger than {$max} bytes");
	}
	if (!gd_update_verify($file, $sha)) {
		@unlink($file);
		return $finish('failed', "checksum mismatch: {$name} does not match its SHA256SUMS entry; nothing was installed");
	}
	$pkg_add = $o['pkg_add'];
	$r = $pkg_add($file);
	@unlink($file);
	$log = (string)($r['output'] ?? '');
	if ((int)($r['rc'] ?? -1) !== 0) {
		return $finish('failed', 'pkg add exited with status ' . (int)($r['rc'] ?? -1), $log);
	}
	$recheck = $o['recheck'];
	$recheck();
	return $finish('done', null, $log);
}

/* True if $pid is a running process (EPERM counts as running). */
function gd_update_pid_alive(int $pid): bool {
	if ($pid <= 0) {
		return false;
	}
	if (function_exists('posix_kill')) {
		return posix_kill($pid, 0) || posix_get_last_error() === 1;
	}
	exec('/bin/kill -0 ' . $pid . ' 2>/dev/null', $out, $rc);
	return $rc === 0;
}

/* Pid holding the lock file, or null when there is no lock or it is stale. */
function gd_update_lock_holder(string $path): ?int {
	clearstatcache(true, $path);
	$mtime = @filemtime($path);
	$s = trim((string)@file_get_contents($path));
	if ($mtime === false || $mtime < time() - GD_UPDATE_LOCK_MAX_AGE_S || !ctype_digit($s) || (int)$s <= 0) {
		return null;
	}
	return gd_update_pid_alive((int)$s) ? (int)$s : null;
}

/*
 * Create the lock file holding $pid. A lock whose pid is dead, whose content
 * is not a pid, or that is over an hour old is stale and replaced. A lock
 * created in the last few seconds that has no pid yet counts as held.
 */
function gd_update_lock_acquire(string $path, int $pid): bool {
	for ($i = 0; $i < 2; $i++) {
		$fh = @fopen($path, 'x');
		if ($fh !== false) {
			fwrite($fh, $pid . "\n");
			fclose($fh);
			return true;
		}
		clearstatcache(true, $path);
		$mtime = @filemtime($path);
		if ($mtime !== false && $mtime >= time() - 5 && trim((string)@file_get_contents($path)) === '') {
			return false;
		}
		if (gd_update_lock_holder($path) !== null) {
			return false;
		}
		@unlink($path);
	}
	return false;
}

/* Remove the lock if $pid holds it. */
function gd_update_lock_release(string $path, int $pid): void {
	if (trim((string)@file_get_contents($path)) === (string)$pid) {
		@unlink($path);
	}
}

/*
 * update-run.json as the status page shows it: state idle|running|done|failed
 * plus from, to, started_at, finished_at, log, error (strings or null). A
 * 'running' file with no lock holder more than GD_UPDATE_RUN_GRACE_S after it
 * started is reported as failed (the updater died).
 */
function gd_update_run_view(?array $run, ?int $lock_pid, int $now): array {
	$state = $run['state'] ?? null;
	if (!in_array($state, ['running', 'done', 'failed'], true)) {
		return ['state' => 'idle'];
	}
	$v = ['state' => $state];
	foreach (['from', 'to', 'started_at', 'finished_at', 'log', 'error'] as $k) {
		$v[$k] = is_scalar($run[$k] ?? null) ? (string)$run[$k] : null;
	}
	if ($state === 'running' && $lock_pid === null) {
		$t = $v['started_at'] === null ? false : strtotime($v['started_at']);
		if ($t === false || $now - $t > GD_UPDATE_RUN_GRACE_S) {
			$v['state'] = 'failed';
			$v['error'] = 'The updater stopped before finishing; see the system log.';
		}
	}
	return $v;
}

/*
 * Which POST action of the status page to run: 'none' (no or unknown act),
 * 'forbidden' (a known act without the settings privilege), or the act
 * itself ('update', 'update_check', 'reset_signatures'). The privilege is
 * decided here, before any action code runs.
 */
function gd_update_post_action($method, $act, bool $can_settings): string {
	if ($method !== 'POST' || !is_string($act) || !in_array($act, ['update', 'update_check', 'reset_signatures'], true)) {
		return 'none';
	}
	return $can_settings ? $act : 'forbidden';
}

/*
 * Run $argv (array, no shell) with stdin from /dev/null, stdout+stderr
 * captured, killed (SIGKILL) after $timeout seconds, including a child that
 * closed its output but is still running. $env replaces the environment
 * when given. Returns ['rc' => int (-1 on failure/timeout), 'output' => string,
 * 'timed_out' => bool]; output is capped at the last 1 MB.
 */
function gd_update_exec(array $argv, int $timeout, ?array $env = null): array {
	$pipes = [];
	$p = @proc_open($argv, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, null, $env);
	if (!is_resource($p)) {
		return ['rc' => -1, 'output' => '', 'timed_out' => false];
	}
	stream_set_blocking($pipes[1], false);
	$out = '';
	$deadline = microtime(true) + $timeout;
	$timed_out = false;
	$open = true;
	while ($open) {
		$left = $deadline - microtime(true);
		if ($left <= 0) {
			$timed_out = true;
			break;
		}
		$r = [$pipes[1]];
		$w = $e = null;
		$n = @stream_select($r, $w, $e, 0, (int)(min($left, 0.5) * 1000000));
		if ($n === false) {
			break;
		}
		if ($n > 0) {
			$chunk = fread($pipes[1], 65536);
			if ($chunk === false || ($chunk === '' && feof($pipes[1]))) {
				$open = false;
			} else {
				$out = gd_update_tail($out . $chunk, 1048576);
			}
		}
	}
	fclose($pipes[1]);
	/* Output closed is not exited: wait for the exit (proc_close would block) up to the deadline. */
	$rc = -1;
	while (!$timed_out) {
		$st = proc_get_status($p);
		if (!$st['running']) {
			$rc = (int)$st['exitcode'];
			break;
		}
		if (microtime(true) >= $deadline) {
			$timed_out = true;
			break;
		}
		usleep(50000);
	}
	if ($timed_out) {
		proc_terminate($p, 9);
	}
	proc_close($p);
	return ['rc' => $timed_out ? -1 : $rc, 'output' => $out, 'timed_out' => $timed_out];
}

/*
 * HTTPS GET of $url with a User-Agent, following up to 5 redirects (HTTPS
 * only), $timeout seconds overall, at most $max bytes. The body is returned,
 * or written to $dest when given. Needs PHP's curl extension: there is
 * deliberately no stream-wrapper fallback, because that would follow a
 * redirect to plain http:// and let a man in the middle serve both
 * SHA256SUMS and the package. $have_curl is for tests only. Returns
 * ['ok', 'body', 'error', 'status' (HTTP code, 0 if none)].
 */
function gd_update_http_get(string $url, string $ua, int $timeout, int $max, ?string $dest = null, array $headers = [], ?bool $have_curl = null): array {
	$fail = fn(string $e, int $code = 0): array => ['ok' => false, 'body' => '', 'error' => $e, 'status' => $code];
	if (!($have_curl ?? function_exists('curl_init'))) {
		return $fail('PHP curl extension required');
	}
	if (!str_starts_with($url, 'https://')) {
		return $fail('not an https URL');
	}
	$fh = null;
	if ($dest !== null && ($fh = @fopen($dest, 'wb')) === false) {
		return $fail("cannot write {$dest}");
	}
	$body = '';
	$size = 0;
	$over = false;
	$sink = function (string $chunk) use (&$body, &$size, &$over, $fh, $max): bool {
		$size += strlen($chunk);
		if ($size > $max) {
			$over = true;
			return false;
		}
		if ($fh !== null) {
			return fwrite($fh, $chunk) === strlen($chunk);
		}
		$body .= $chunk;
		return true;
	};
	$headers[] = 'User-Agent: ' . $ua;
	$error = null;
	$ch = curl_init($url);
	$opts = [
	    CURLOPT_FOLLOWLOCATION => true,
	    CURLOPT_MAXREDIRS => 5,
	    CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
	    CURLOPT_TIMEOUT => $timeout,
	    CURLOPT_HTTPHEADER => $headers,
	    CURLOPT_SSL_VERIFYPEER => true,
	    CURLOPT_SSL_VERIFYHOST => 2,
	    CURLOPT_WRITEFUNCTION => fn($c, string $chunk): int => $sink($chunk) ? strlen($chunk) : 0,
	];
	if (defined('CURLOPT_PROTOCOLS_STR')) {
		$opts[CURLOPT_PROTOCOLS_STR] = 'https';
		$opts[CURLOPT_REDIR_PROTOCOLS_STR] = 'https';
	} else {
		$opts[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
		$opts[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTPS;
	}
	curl_setopt_array($ch, $opts);
	$ok = curl_exec($ch);
	$code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
	if ($over) {
		$error = "response larger than {$max} bytes";
	} elseif ($ok === false) {
		$error = curl_error($ch) ?: 'request failed';
	} elseif ($code !== 200) {
		$error = "HTTP {$code}";
	}
	unset($ch);
	if ($fh !== null && !fclose($fh) && $error === null) {
		$error = 'write failed';
	}
	return $error === null ? ['ok' => true, 'body' => $body, 'error' => null, 'status' => $code] : $fail($error, $code);
}
