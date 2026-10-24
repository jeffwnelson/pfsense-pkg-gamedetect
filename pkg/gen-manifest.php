<?php
/*
# Copyright 2026 Jeff Nelson
# Licensed under the Apache License, Version 2.0.
 *
 * Render the pkg(8) +MANIFEST (JSON) to stdout.
 * Usage: php gen-manifest.php <version> <freebsd-major>
 */
if ($argc !== 3 || !ctype_digit($argv[2])) {
	fwrite(STDERR, "usage: gen-manifest.php <version> <freebsd-major>\n");
	exit(1);
}
$name = 'pfSense-pkg-gamedetect';
$major = $argv[2];
$dir = __DIR__;

function script($file, $name, $phase) {
	// pkg runs embedded scripts without the legacy $1/$2 arguments, so set them.
	$text = file_get_contents($file);
	if ($text === false) {
		fwrite(STDERR, "cannot read $file\n");
		exit(1);
	}
	return preg_replace('/^#!\/bin\/sh\n/', "#!/bin/sh\nset -- $name $phase\n", $text, 1);
}

$manifest = [
	'name' => $name,
	'version' => $argv[1],
	'origin' => 'net/' . $name,
	'comment' => 'Per-device game traffic detection for pfSense',
	'desc' => 'Shows which LAN device is playing which game, from pf state '
		. 'and DNS metadata, on a status page.',
	'maintainer' => '1343098+jeffwnelson@users.noreply.github.com',
	'www' => 'https://github.com/jeffwnelson/pfsense-pkg-gamedetect',
	'abi' => "FreeBSD:$major:*",
	'arch' => "freebsd:$major:*",
	'prefix' => '/',
	'licenselogic' => 'single',
	'licenses' => ['APACHE20'],
	'categories' => ['net'],
	'deps' => new stdClass(),
	'scripts' => [
		'post-install' => script("$dir/pkg-install.sh", $name, 'POST-INSTALL'),
		'pre-deinstall' => script("$dir/pkg-deinstall.sh", $name, 'DEINSTALL'),
		'post-deinstall' => script("$dir/pkg-deinstall.sh", $name, 'POST-DEINSTALL'),
	],
];
echo json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
