<?php
/*
 * Copyright 2026 Jeff Nelson
 * Licensed under the Apache License, Version 2.0.
 *
 * gamedetect.inc (pfSense glue) against the stubs in tests/stubs/pfsense.
 */
require __DIR__ . '/assert.inc';
set_include_path(__DIR__ . '/stubs/pfsense' . PATH_SEPARATOR . get_include_path());
require_once __DIR__ . '/../files/usr/local/pkg/gamedetect.inc';

$tmp = sys_get_temp_dir() . '/gamedetect-glue-' . getmypid();
register_shutdown_function(function () use ($tmp) {
	exec('rm -rf ' . escapeshellarg($tmp));
});

/* Fresh paths, interfaces, and package config per case. */
function fresh(string $name, ?array $pkgcfg): string {
	global $tmp;
	$dir = "$tmp/$name";
	mkdir("$dir/rc.d", 0755, true);
	file_put_contents("$dir/default.json", file_get_contents(__DIR__ . '/fixtures/signatures_test.json'));
	$GLOBALS['gamedetect_test_paths'] = [
	    'conf' => "$dir/etc/gamedetectd.conf",
	    'signatures' => "$dir/etc/signatures.json",
	    'default_signatures' => "$dir/default.json",
	    'data_dir' => "$dir/db",
	    'pid' => "$dir/gamedetectd.pid",
	    'update_lock' => "$dir/gamedetect-update.lock",
	    'info_xml' => "$dir/info.xml",
	    'tmp_dir' => "$dir",
	];
	$GLOBALS['stub_ifaces'] = [
	    'wan' => ['real' => 'igc0', 'ip' => '203.0.113.1', 'subnet' => 29],
	    'lan' => ['real' => 'igc1', 'ip' => '10.10.3.1', 'subnet' => 24],
	    'opt1' => ['real' => 'igc2', 'ip' => '10.99.0.1', 'subnet' => 22],
	    'opt2' => ['real' => 'igc3', 'ip' => '', 'subnet' => null],
	];
	$GLOBALS['stub_config'] = $pkgcfg === null ? [] : ['installedpackages' => ['gamedetect' => ['config' => [$pkgcfg]]]];
	$GLOBALS['stub_mwexec'] = [];
	$GLOBALS['stub_mwexec_bg'] = [];
	$GLOBALS['stub_cron_calls'] = [];
	$GLOBALS['stub_rcfile'] = [];
	$GLOBALS['stub_rcfile_dir'] = "$dir/rc.d";
	$GLOBALS['stub_log'] = [];
	return $dir;
}

function throws(callable $f): bool {
	try {
		$f();
	} catch (InvalidArgumentException $e) {
		return true;
	}
	return false;
}

/* resolve_networks_interface_and_cidr */
fresh('resolve', null);
assert_eq(['10.10.3.0/24', '10.99.0.0/22', '192.168.50.0/24'],
	gamedetect_resolve_networks(['lan', 'opt1', '192.168.50.0/24', 'lan']), 'interfaces -> network CIDRs, CIDR passed through, deduped');
assert_eq(['10.20.0.0/16'], gamedetect_resolve_networks([' 10.20.0.0/16 ']), 'CIDR whitespace trimmed');
assert_eq([], gamedetect_resolve_networks(['opt2']), 'interface without an IPv4 address contributes nothing');

/* resolve_networks_rejects_garbage */
foreach (['lan; rm -rf /', '10.0.0.0/33', '10.0.0/8', '300.1.1.1/8', 'nosuchif', '$(reboot)', '2001:db8::/32', ''] as $bad) {
	assert_true(throws(fn() => gamedetect_resolve_networks([$bad])), 'rejected: ' . var_export($bad, true));
}

/* local_ips: every configured interface's IPv4 address */
assert_eq(['203.0.113.1', '10.10.3.1', '10.99.0.1'], gamedetect_local_ips(), 'local ips from configured interfaces');

/* validate_input_ranges */
$base = ['attendee_networks' => ['lan'], 'extra_cidrs' => '', 'poll_interval' => '5', 'idle_timeout' => '120',
    'dns_refresh' => '30', 'unknown_list_size' => '50'];
$errs = [];
gamedetect_validate_input($base, $errs);
assert_eq([], $errs, 'defaults valid');
$ranges = ['poll_interval' => [2, 60], 'idle_timeout' => [30, 900], 'dns_refresh' => [10, 300], 'unknown_list_size' => [10, 500]];
foreach ($ranges as $field => [$min, $max]) {
	foreach ([$min - 1 => false, $min => true, $max => true, $max + 1 => false] as $v => $ok) {
		$errs = [];
		gamedetect_validate_input(array_merge($base, [$field => (string)$v]), $errs);
		assert_eq($ok, $errs === [], "$field=$v " . ($ok ? 'accepted' : 'rejected'));
	}
	foreach (['abc', '5.5', '-3', '1e2'] as $v) {
		$errs = [];
		gamedetect_validate_input(array_merge($base, [$field => $v]), $errs);
		assert_eq(1, count($errs), "$field=$v rejected");
	}
}
$errs = [];
gamedetect_validate_input(array_merge($base, ['attendee_networks' => [], 'extra_cidrs' => '']), $errs);
assert_eq(1, count($errs), 'at least one attendee network required');
$errs = [];
gamedetect_validate_input(array_merge($base, ['attendee_networks' => [], 'extra_cidrs' => "10.20.0.0/16\n192.168.50.0/24, 10.30.0.0/16"]), $errs);
assert_eq([], $errs, 'CIDR-only networks accepted (newline/comma separated)');
$errs = [];
gamedetect_validate_input(array_merge($base, ['attendee_networks' => ['lan', 'evil;id']]), $errs);
assert_eq(1, count($errs), 'unknown interface rejected');
$errs = [];
gamedetect_validate_input(array_merge($base, ['extra_cidrs' => "10.0.0.0/33"]), $errs);
assert_eq(1, count($errs), 'bad CIDR rejected');
$errs = [];
gamedetect_validate_input(array_merge($base, ['attendee_networks' => ['lan', ['x'], 7], 'extra_cidrs' => ['10.0.0.0/8']]), $errs);
assert_eq(['Attendee networks contain an invalid value.'], $errs, 'non-string network values rejected without PHP warnings');
$errs = [];
gamedetect_validate_input(array_merge($base, ['poll_interval' => ['5']]), $errs);
assert_eq(1, count($errs), 'array-valued number rejected');
$errs = [];
gamedetect_validate_input(array_merge($base, ['attendee_networks' => 'lan,opt1']), $errs);
assert_eq([], $errs, 'comma-separated interface string accepted');

/* write_conf_defaults: never-saved config -> all defaults, attendee_cidrs from lan */
$dir = fresh('defaults', null);
gamedetect_write_conf();
$conf = json_decode((string)@file_get_contents("$dir/etc/gamedetectd.conf"), true);
assert_eq([
    'attendee_cidrs' => ['10.10.3.0/24'],
    'local_ips' => ['203.0.113.1', '10.10.3.1', '10.99.0.1'],
    'poll_interval' => 60,
    'idle_timeout' => 600,
    'dns_refresh' => 30,
    'rdns_enabled' => true,
    'unknown_list_size' => 50,
    'data_dir' => "$dir/db",
    'signatures_path' => "$dir/etc/signatures.json",
    'default_signatures_path' => "$dir/default.json",
    'leases_cmd' => '/usr/local/libexec/gamedetect/leases.php',
], $conf, 'conf defaults');

/* saved config: values used, unchecked rDNS is off, invalid stored entries dropped with a log */
$dir = fresh('saved', ['enable' => 'on', 'attendee_networks' => 'lan,opt2,bogus', 'extra_cidrs' => "10.20.0.0/16\n",
    'poll_interval' => '10', 'idle_timeout' => '', 'dns_refresh' => '60', 'unknown_list_size' => '100']);
gamedetect_write_conf();
$conf = json_decode((string)@file_get_contents("$dir/etc/gamedetectd.conf"), true);
assert_eq(['10.10.3.0/24', '10.20.0.0/16'], $conf['attendee_cidrs'], 'saved networks resolved');
assert_eq([10, 600, 60, 100, false], [$conf['poll_interval'], $conf['idle_timeout'], $conf['dns_refresh'], $conf['unknown_list_size'], $conf['rdns_enabled']], 'saved values; empty -> default; unchecked rdns off');
assert_eq(1, count($GLOBALS['stub_log']), 'invalid stored network logged');

/* rcfile: exact daemon command, pid-file stop, valid sh */
$dir = fresh('rcfile', null);
gamedetect_write_rcfile();
$p = $GLOBALS['stub_rcfile'][0];
assert_eq('gamedetectd.sh', $p['file'], 'rcfile name');
assert_true(str_contains($p['start'], '/usr/sbin/daemon -f -p /var/run/gamedetectd.pid -r -R 5 /usr/local/bin/php -f /usr/local/libexec/gamedetect/gamedetectd.php'), 'start command');
assert_true(str_contains($p['stop'], '/var/run/gamedetectd.pid'), 'stop via pid file');
exec('sh -n ' . escapeshellarg("$dir/rc.d/gamedetectd.sh") . ' 2>&1', $o, $rc);
assert_eq(0, $rc, 'generated rc script parses: ' . implode(' ', $o));
if (trim((string)shell_exec('command -v shellcheck')) !== '') {
	exec('shellcheck -s sh -e SC2317 ' . escapeshellarg("$dir/rc.d/gamedetectd.sh") . ' 2>&1', $o2, $rc2);
	assert_eq(0, $rc2, 'generated rc script shellcheck clean: ' . implode("\n", $o2));
}

/* resync: enabled + not running -> defaults installed, conf + rcfile, start */
$dir = fresh('resync-start', null);
gamedetect_resync();
assert_true(is_file("$dir/etc/signatures.json") && is_dir("$dir/db") && is_file("$dir/etc/gamedetectd.conf"), 'resync: signatures, data dir, conf');
assert_eq(1, count($GLOBALS['stub_rcfile']), 'resync: rcfile written');
assert_eq(['/usr/local/etc/rc.d/gamedetectd.sh start'], $GLOBALS['stub_mwexec'], 'resync: daemon started');

/* resync keeps an existing live signatures file */
file_put_contents("$dir/etc/signatures.json", '{"mine":1}');
$GLOBALS['stub_mwexec'] = [];
file_put_contents("$dir/gamedetectd.pid", (string)getmypid());
gamedetect_resync();
assert_eq('{"mine":1}', file_get_contents("$dir/etc/signatures.json"), 'resync: live signatures never overwritten');
assert_eq(['/bin/kill -HUP ' . getmypid()], $GLOBALS['stub_mwexec'], 'resync: running daemon gets SIGHUP');

/* resync disabled -> stop */
$dir = fresh('resync-stop', ['attendee_networks' => 'lan', 'poll_interval' => '5']);
gamedetect_resync();
assert_eq(['/usr/local/etc/rc.d/gamedetectd.sh stop'], $GLOBALS['stub_mwexec'], 'resync: disabled -> stop');

/* resync enabled but no usable network -> stop + log */
$dir = fresh('resync-nonet', ['enable' => 'on', 'attendee_networks' => 'opt2']);
gamedetect_resync();
assert_eq(['/usr/local/etc/rc.d/gamedetectd.sh stop'], $GLOBALS['stub_mwexec'], 'resync: no attendee network -> stop');
assert_true(count($GLOBALS['stub_log']) >= 1, 'resync: no attendee network logged');

/* status */
$dir = fresh('status', null);
assert_eq(['running' => false], gamedetect_status(), 'status: nothing yet');
mkdir("$dir/db", 0755, true);
file_put_contents("$dir/db/daemon.json", json_encode(['state' => 'running', 'warnings' => []]));
file_put_contents("$dir/gamedetectd.pid", (string)getmypid());
assert_eq(['state' => 'running', 'warnings' => [], 'running' => true], gamedetect_status(), 'status: daemon.json + running');
assert_eq(true, gamedetect_is_running(), 'is_running: live pid');
file_put_contents("$dir/gamedetectd.pid", "garbage\n");
assert_eq(false, gamedetect_status()['running'], 'status: bad pid file -> not running');
assert_eq(false, gamedetect_is_running(), 'is_running: bad pid file');

/* deinstall: PKG_UPGRADE set (pkg upgrade) keeps the data dir; a real removal drops it */
$run_recorded = function (): void {
	foreach ($GLOBALS['stub_mwexec'] as $cmd) {
		if (str_starts_with($cmd, '/bin/rm ')) {
			exec($cmd);
		}
	}
};
$dir = fresh('deinstall-upgrade', null);
mkdir("$dir/db", 0755, true);
file_put_contents("$dir/db/clients.json", '{}');
putenv('PKG_UPGRADE=1');
gamedetect_deinstall();
$run_recorded();
putenv('PKG_UPGRADE');
assert_eq([], array_values(array_filter($GLOBALS['stub_mwexec'], fn($c) => str_contains($c, 'rm '))), 'deinstall: upgrade issues no rm');
assert_true(is_file("$dir/db/clients.json"), 'deinstall: upgrade keeps the data dir');
$dir = fresh('deinstall-remove', null);
mkdir("$dir/db", 0755, true);
file_put_contents("$dir/db/clients.json", '{}');
gamedetect_deinstall();
$run_recorded();
assert_eq(['/bin/rm -rf ' . escapeshellarg("$dir/db")], array_values(array_filter($GLOBALS['stub_mwexec'], fn($c) => str_contains($c, 'rm '))), 'deinstall: removal rm -rf of the data dir');
assert_true(!file_exists("$dir/db"), 'deinstall: removal drops the data dir');

/* leases.php: one non-UTF-8 DHCP hostname does not blank every hostname */
$lf = "$tmp/leases.ser";
file_put_contents($lf, serialize(['lease' => [
    ['ip' => '10.10.3.17', 'hostname' => 'gamer-pc', 'act' => 'active'],
    ['ip' => '10.10.3.18', 'hostname' => "caf\xe9-pc", 'act' => 'active'],
]]));
$cmd = 'GD_STUB_LEASES=' . escapeshellarg($lf) . ' ' . escapeshellarg(PHP_BINARY)
    . ' -d include_path=' . escapeshellarg(__DIR__ . '/stubs/pfsense')
    . ' -f ' . escapeshellarg(__DIR__ . '/../files/usr/local/libexec/gamedetect/leases.php');
$lout = (string)shell_exec($cmd);
assert_eq(['10.10.3.17' => 'gamer-pc', '10.10.3.18' => "caf\u{FFFD}-pc"], json_decode($lout, true), 'leases.php: invalid UTF-8 hostname substituted, others kept');

/* update_check setting: on when never saved, and for configs saved before the field existed */
const CRON_CMD = '/usr/local/bin/php -f /usr/local/libexec/gamedetect/update-check.php';
fresh('upd-setting', null);
assert_eq(true, gamedetect_settings()['update_check'], 'update_check defaults on (never saved)');
fresh('upd-setting2', ['enable' => 'on', 'attendee_networks' => 'lan']);
assert_eq(true, gamedetect_settings()['update_check'], 'update_check on for a config saved before the field existed');
fresh('upd-setting3', ['enable' => 'on', 'attendee_networks' => 'lan', 'update_check' => '']);
assert_eq(false, gamedetect_settings()['update_check'], 'update_check unchecked -> off');
fresh('upd-setting4', ['enable' => 'on', 'attendee_networks' => 'lan', 'update_check' => 'on']);
assert_eq(true, gamedetect_settings()['update_check'], 'update_check checked -> on');
assert_eq('jeffwnelson/pfsense-pkg-gamedetect', GAMEDETECT_UPDATE_REPO, 'update repo constant');

/* resync installs the daily cron job at 04:xx and keeps its minute */
$cron_items = fn(): array => array_values(array_filter((array)config_get_path('cron/item', []), fn($i) => ($i['command'] ?? '') === CRON_CMD));
fresh('upd-cron', null);
gamedetect_resync();
$items = $cron_items();
assert_eq(1, count($items), 'resync installs one update-check cron job');
assert_true(ctype_digit((string)($items[0]['minute'] ?? '')) && (int)$items[0]['minute'] >= 0 && (int)$items[0]['minute'] <= 59, 'cron minute 0-59');
assert_eq(['4', '*', '*', '*', 'root'], [$items[0]['hour'], $items[0]['mday'], $items[0]['month'], $items[0]['wday'], $items[0]['who']], 'cron daily at 04:xx as root');
$minute = $items[0]['minute'];
gamedetect_resync();
$items = $cron_items();
assert_eq([1, $minute], [count($items), $items[0]['minute']], 'second resync keeps one job and its minute (no config churn)');
$GLOBALS['stub_config']['installedpackages']['gamedetect']['config'][0] = ['enable' => 'on', 'attendee_networks' => 'lan', 'update_check' => ''];
gamedetect_resync();
assert_eq([], $cron_items(), 'resync with update_check off removes the cron job');
$last = end($GLOBALS['stub_cron_calls']);
assert_eq([CRON_CMD, false], [$last[0], $last[1]], 'removal goes through install_cron_job(cmd, false)');

/* deinstall removes the cron job and the lock; an upgrade keeps both */
$dir = fresh('upd-deinstall', null);
gamedetect_resync();
file_put_contents("$dir/gamedetect-update.lock", "1\n");
putenv('PKG_UPGRADE=1');
gamedetect_deinstall();
putenv('PKG_UPGRADE');
assert_eq(1, count($cron_items()), 'deinstall during upgrade keeps the cron job');
assert_true(is_file("$dir/gamedetect-update.lock"), 'deinstall during upgrade keeps the lock (the updater holds it)');
gamedetect_deinstall();
assert_eq([], $cron_items(), 'deinstall removes the cron job');
assert_true(!file_exists("$dir/gamedetect-update.lock"), 'deinstall removes the lock');

/* installed version comes from the built info.xml */
$dir = fresh('upd-version', null);
file_put_contents("$dir/info.xml", "<pfsensepkgs><package><version>0.2.0</version></package></pfsensepkgs>\n");
assert_eq('0.2.0', gamedetect_installed_version(), 'installed version from info.xml');

/* Update now: refuses without an update, starts the fixed detached command, refuses while running */
$dir = fresh('upd-start', null);
file_put_contents("$dir/info.xml", "<pfsensepkgs><package><version>0.2.0</version></package></pfsensepkgs>\n");
$r = gamedetect_update_start();
assert_true($r['ok'] === false && is_string($r['error']) && $GLOBALS['stub_mwexec_bg'] === [], 'update start without update.json -> refused, nothing started');
mkdir("$dir/db", 0755, true);
file_put_contents("$dir/db/update.json", json_encode(['installed' => '0.2.0', 'latest' => '0.3.0', 'update_available' => true]));
$r = gamedetect_update_start();
assert_eq(true, $r['ok'], 'update start ok (' . ($r['error'] ?? '') . ')');
assert_eq(['/usr/sbin/daemon -f /usr/local/bin/php -f /usr/local/libexec/gamedetect/update-run.php'], $GLOBALS['stub_mwexec_bg'], 'update start runs the fixed detached updater command');
$run = json_decode((string)@file_get_contents("$dir/db/update-run.json"), true);
assert_eq(['running', '0.2.0', '0.3.0'], [$run['state'] ?? null, $run['from'] ?? null, $run['to'] ?? null], 'update start records a running state');
assert_eq('running', gamedetect_update_status()['run']['state'], 'status shows the update running');
$r = gamedetect_update_start();
assert_true($r['ok'] === false && count($GLOBALS['stub_mwexec_bg']) === 1, 'update start refused while an update is running');
file_put_contents("$dir/db/update-run.json", json_encode(['state' => 'done', 'to' => '0.3.0']));
file_put_contents("$dir/gamedetect-update.lock", getmypid() . "\n");
$r = gamedetect_update_start();
assert_true($r['ok'] === false && count($GLOBALS['stub_mwexec_bg']) === 1, 'update start refused while the lock is held by a live pid');
$dead = 999999;
while (gd_update_pid_alive($dead)) {
	$dead++;
}
file_put_contents("$dir/gamedetect-update.lock", "$dead\n");
$r = gamedetect_update_start();
assert_true($r['ok'] === true && count($GLOBALS['stub_mwexec_bg']) === 2, 'update start proceeds past a stale lock');
