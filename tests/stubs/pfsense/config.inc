<?php
/*
 * Copyright 2026 Jeff Nelson
 * Licensed under the Apache License, Version 2.0.
 *
 * Test stub for pfSense's config.inc: config lives in $GLOBALS['stub_config'].
 */
$GLOBALS['stub_config'] = $GLOBALS['stub_config'] ?? [];

function config_get_path(string $path, $default = null) {
	$node = $GLOBALS['stub_config'];
	foreach (explode('/', trim($path, '/')) as $key) {
		if (!is_array($node) || !array_key_exists($key, $node)) {
			return $default;
		}
		$node = $node[$key];
	}
	return $node;
}

function config_set_path(string $path, $value, $default = null) {
	$node = &$GLOBALS['stub_config'];
	foreach (explode('/', trim($path, '/')) as $key) {
		if (!isset($node[$key]) || !is_array($node[$key])) {
			$node[$key] = [];
		}
		$node = &$node[$key];
	}
	$node = $value;
	return $value;
}

/* Same contract as pfSense: true when $path/$enable_key is set. */
function config_path_enabled(string $path, string $enable_key = 'enable'): bool {
	$arr = config_get_path($path);
	return is_array($arr) && isset($arr[$enable_key]);
}

function write_config(string $desc = '', bool $backup = true): void {
	$GLOBALS['stub_write_config'][] = $desc;
}
