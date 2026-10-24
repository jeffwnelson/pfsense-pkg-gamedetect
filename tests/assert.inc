<?php
/*
 * Copyright 2026 Jeff Nelson
 * Licensed under the Apache License, Version 2.0.
 *
 * Minimal assertion helpers. Each test file exits non-zero if any assertion failed.
 */
$GLOBALS['FAILS'] = 0;

/* Any PHP warning/notice/deprecation is a test failure (unless silenced with @). */
set_error_handler(function (int $no, string $str, string $file, int $line): bool {
	if (!(error_reporting() & $no)) {
		return false;
	}
	$GLOBALS['FAILS']++;
	echo "FAIL php error [$no] $str at $file:$line\n";
	return true;
});
register_shutdown_function(function () {
	if ($GLOBALS['FAILS'] > 0) {
		fwrite(STDERR, "{$GLOBALS['FAILS']} assertion(s) failed\n");
		exit(1);
	}
});

function assert_eq($exp, $got, string $msg): void {
	if ($exp === $got) {
		echo "ok $msg\n";
	} else {
		$GLOBALS['FAILS']++;
		echo "FAIL $msg: expected " . var_export($exp, true) . ", got " . var_export($got, true) . "\n";
	}
}

function assert_true(bool $c, string $msg): void {
	if ($c) {
		echo "ok $msg\n";
	} else {
		$GLOBALS['FAILS']++;
		echo "FAIL $msg\n";
	}
}

function assert_null($v, string $msg): void {
	if ($v === null) {
		echo "ok $msg\n";
	} else {
		$GLOBALS['FAILS']++;
		echo "FAIL $msg: expected null, got " . var_export($v, true) . "\n";
	}
}
