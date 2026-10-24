<?php
/*
 * Copyright 2026 Jeff Nelson
 * Licensed under the Apache License, Version 2.0.
 *
 * status_gamedetect.php run in a subprocess against the pfSense stubs:
 * ?refresh=clients is the page-internal feed (only what the Clients table
 * script renders); ?format=json is no longer a data endpoint.
 */
require __DIR__ . '/assert.inc';

$tmp = sys_get_temp_dir() . '/gamedetect-page-' . getmypid();
mkdir("$tmp/db", 0755, true);
register_shutdown_function(function () use ($tmp) {
	exec('rm -rf ' . escapeshellarg($tmp));
});
$pkg = realpath(__DIR__ . '/../files/usr/local/pkg');
$src = file_get_contents(__DIR__ . '/../files/usr/local/www/status_gamedetect.php');
file_put_contents("$tmp/page.php", str_replace("'/usr/local/pkg/", "'$pkg/", $src));
file_put_contents("$tmp/drive.php", '<?php
$d = getenv("GD_TEST_DIR");
$GLOBALS["gamedetect_test_paths"] = ["data_dir" => "$d/db", "update_lock" => "$d/lock", "info_xml" => "$d/info.xml",
    "signatures" => "$d/sig.json", "default_signatures" => "$d/sig.json", "pid" => "$d/pid"];
$_SERVER["REQUEST_METHOD"] = "GET";
parse_str((string)getenv("GD_TEST_QUERY"), $_GET);
$_POST = [];
require "$d/page.php";
');
file_put_contents("$tmp/sig.json", '{}');
file_put_contents("$tmp/pid", getmypid() . "\n");  /* a live pid: daemon "running", so no banner */
$client = [
    'ip' => '10.10.3.17', 'mac' => 'a4:5e:60:12:34:56', 'hostname' => 'DESKTOP-K9', 'game' => 'Fortnite',
    'game_id' => 'fortnite', 'confidence' => 'high', 'score' => 85,
    'candidates' => [['id' => 'fortnite', 'name' => 'Fortnite', 'score' => 85]],
    'evidence' => ['port:9000-9100', 'dns:*.epicgames.com'], 'flows' => 3,
    'first_seen' => '2026-10-23T20:41:10-05:00', 'last_seen' => '2026-10-23T21:04:10-05:00',
    'top_destinations' => [['ip' => '3.120.1.5', 'port' => 9045, 'names' => ['a.example'], 'extra' => 1]],
];
file_put_contents("$tmp/db/clients.json", json_encode(['generated_at' => '2026-10-23T21:04:10-05:00', 'poll_interval' => 60, 'clients' => [$client]]));
file_put_contents("$tmp/db/daemon.json", json_encode(['state' => 'running', 'pid' => 1, 'last_poll_ms' => 17, 'warnings' => []]));

function sorted_keys(array $a): array {
	$k = array_keys($a);
	sort($k);
	return $k;
}

function page(string $query): string {
	global $tmp;
	$inc = __DIR__ . '/stubs/www' . PATH_SEPARATOR . __DIR__ . '/stubs/pfsense';
	$cmd = 'GD_TEST_DIR=' . escapeshellarg($tmp) . ' GD_TEST_QUERY=' . escapeshellarg($query) . ' '
	    . escapeshellarg(PHP_BINARY) . ' -d display_errors=1 -d error_reporting=-1 -d include_path=' . escapeshellarg($inc)
	    . ' -f ' . escapeshellarg("$tmp/drive.php") . ' 2>&1';
	return (string)shell_exec($cmd);
}

/* ?format=json no longer returns data: it is just the HTML page */
$out = page('format=json');
assert_true(!is_array(json_decode($out, true)), '?format=json does not return JSON');
assert_true(str_contains($out, '<!-- head -->') && str_contains($out, '<!-- foot -->'), '?format=json falls through to the HTML page');

/* ?refresh=clients: exactly what the Clients script renders */
$out = page('refresh=clients');
$j = json_decode($out, true);
assert_true(is_array($j), 'refresh=clients returns JSON: ' . substr($out, 0, 200));
$j = is_array($j) ? $j : [];
assert_eq(['banner', 'clients', 'generated_at', 'poll_interval'], sorted_keys($j), 'refresh feed top-level keys');
assert_true(!array_key_exists('daemon', $j), 'refresh feed has no daemon object');
$c = $j['clients'][0] ?? [];
assert_eq(['confidence', 'flows', 'game', 'game_id', 'hostname', 'ip', 'last_seen', 'mac', 'top_destinations'],
    sorted_keys($c), 'refresh feed client keys are the rendered ones');
foreach (['candidates', 'evidence', 'score', 'first_seen'] as $k) {
	assert_true(!array_key_exists($k, $c), "refresh feed client has no $k");
}
assert_eq([['ip' => '3.120.1.5', 'port' => 9045, 'names' => ['a.example']]], $c['top_destinations'] ?? null, 'refresh feed top_destinations as rendered');
assert_eq([60, '2026-10-23T21:04:10-05:00', null], [$j['poll_interval'] ?? null, $j['generated_at'] ?? null, array_key_exists('banner', $j) ? $j['banner'] : 'missing'], 'refresh feed poll_interval, generated_at, banner');

/* the HTML page embeds the same trimmed payload and polls the internal feed */
$html = page('');
assert_true(str_contains($html, "status_gamedetect.php?refresh=clients") && !str_contains($html, 'format=json'), 'Clients script polls ?refresh=clients');
assert_true(!str_contains($html, 'candidates') && !str_contains($html, 'evidence') && !str_contains($html, 'last_poll_ms'), 'page embeds no candidates/evidence/daemon data');
assert_true(!str_contains($html, 'PHP Warning') && !str_contains($html, 'Fatal'), 'page renders without PHP errors');
