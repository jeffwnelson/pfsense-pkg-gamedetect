<?php
/*
 * Copyright 2026 Jeff Nelson
 * Licensed under the Apache License, Version 2.0.
 *
 * gamedetect.xml / priv / info.xml structure.
 */
require __DIR__ . '/assert.inc';

$root = __DIR__ . '/../files';
$x = simplexml_load_file("$root/usr/local/pkg/gamedetect.xml");
assert_true($x !== false, 'gamedetect.xml parses');
assert_eq('gamedetect', (string)$x->name, 'package name');
assert_eq('%%PKGVERSION%%', (string)$x->version, 'version placeholder');
assert_eq('/usr/local/pkg/gamedetect.inc', (string)$x->include_file, 'include file');
assert_eq('Game Detect: settings saved', (string)$x->addedit_string, 'addedit_string (write_config description on save)');
assert_eq('Game Detect: settings deleted', (string)$x->delete_string, 'delete_string');

$menus = [];
foreach ($x->menu as $m) {
	$menus[(string)$m->section] = [(string)$m->name, (string)$m->url];
}
assert_eq(['Game Detect', '/pkg_edit.php?xml=gamedetect.xml'], $menus['Services'] ?? null, 'Services menu');
assert_eq(['Game Detect', '/status_gamedetect.php'], $menus['Status'] ?? null, 'Status menu');

assert_eq('gamedetectd', (string)$x->service->name, 'service name');
assert_eq('gamedetectd.sh', (string)$x->service->rcfile, 'service rcfile');
assert_true(!isset($x->service->executable), 'service has no executable (daemon runs as php; pid file decides)');
$cmd = trim((string)$x->service->custom_php_service_status_command);
assert_true(str_contains($cmd, '/var/run/gamedetectd.pid'), 'status command reads the pid file');
assert_true(str_contains($cmd, 'posix_kill'), 'status command probes with posix_kill');
assert_true(!str_contains($cmd, 'gamedetect_is_running'), 'status command does not need gamedetect.inc');

/*
 * pfSense evaluates the command as eval("\$rc={$cmd};") from service-utils.inc
 * without loading the package include, so run it the same way in a fresh php
 * process with no includes, the pid path pointed at a temp file.
 */
function gd_eval_status(string $cmd, string $pidfile): string
{
	$c = str_replace('/var/run/gamedetectd.pid', $pidfile, $cmd);
	$code = 'eval("\$rc={$argv[1]};"); echo $rc ? "true" : "false";';
	$out = [];
	$st = 0;
	exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=1 -d error_reporting=-1 -r ' . escapeshellarg($code) . ' ' . escapeshellarg($c) . ' 2>&1', $out, $st);
	return $st . ':' . implode("\n", $out);
}
$pf = tempnam(sys_get_temp_dir(), 'gdpid');
unlink($pf);
assert_eq('0:false', gd_eval_status($cmd, $pf), 'status eval: no pid file -> false');
file_put_contents($pf, getmypid() . "\n");
assert_eq('0:true', gd_eval_status($cmd, $pf), 'status eval: live pid -> true');
file_put_contents($pf, "999999\n");
assert_eq('0:false', gd_eval_status($cmd, $pf), 'status eval: dead pid -> false');
file_put_contents($pf, "garbage\n");
assert_eq('0:false', gd_eval_status($cmd, $pf), 'status eval: garbage pid file -> false');
unlink($pf);
assert_eq('Game Detect classifier', (string)$x->service->description, 'service description');

$want = [
    'enable' => ['checkbox', 'on'],
    'attendee_networks' => ['interfaces_selection', 'lan'],
    'extra_cidrs' => ['textarea', ''],
    'poll_interval' => ['input', '60'],
    'idle_timeout' => ['input', '600'],
    'dns_refresh' => ['input', '30'],
    'rdns_enabled' => ['checkbox', 'on'],
    'unknown_list_size' => ['input', '50'],
    'update_check' => ['checkbox', 'on'],
];
$have = [];
foreach ($x->fields->field as $f) {
	$have[(string)$f->fieldname] = $f;
}
foreach ($want as $name => [$type, $def]) {
	$f = $have[$name] ?? null;
	assert_true($f !== null, "field $name present");
	if ($f === null) {
		continue;
	}
	assert_eq($type, (string)$f->type, "$name type");
	assert_eq($def, (string)$f->default_value, "$name default");
}
assert_true(isset($have['attendee_networks']->multiple), 'attendee_networks is multiple');
assert_true(!isset($have['extra_cidrs']->encoding), 'extra_cidrs not base64-encoded');
foreach (['poll_interval' => '2-60', 'idle_timeout' => '30-900', 'dns_refresh' => '10-300', 'unknown_list_size' => '10-500'] as $n => $r) {
	assert_true(str_contains((string)$have[$n]->description, $r), "$n description states range $r");
}

$ud = (string)($have['update_check']->description ?? '');
assert_true(str_contains($ud, 'api.github.com') && str_contains($ud, 'never installs'), 'update_check description: what it contacts, never installs by itself');

$hooks = [
    'custom_php_validation_command' => 'gamedetect_validate_input($_POST, $input_errors);',
    'custom_php_resync_config_command' => 'gamedetect_resync();',
    'custom_php_install_command' => 'gamedetect_resync();',
    'custom_php_deinstall_command' => 'gamedetect_deinstall();',
];
foreach ($hooks as $h => $cmd) {
	assert_true(str_contains((string)$x->$h, $cmd), "$h calls $cmd");
}

$priv = file_get_contents("$root/etc/inc/priv/gamedetect.priv.inc");
assert_true(str_contains($priv, "page-services-gamedetect") && str_contains($priv, 'pkg_edit.php?xml=gamedetect.xml*'), 'services priv');
assert_true(str_contains($priv, "page-status-gamedetect") && str_contains($priv, 'status_gamedetect.php*'), 'status priv');

$i = simplexml_load_file("$root/usr/local/share/pfSense-pkg-gamedetect/info.xml");
assert_true($i !== false, 'info.xml parses');
assert_eq('gamedetect', (string)$i->package->name, 'info name');
assert_eq('gamedetect.xml', (string)$i->package->configurationfile, 'info configurationfile');
