<?php
/*
 * Copyright 2026 Jeff Nelson
 * Licensed under the Apache License, Version 2.0.
 *
 * Test stub for pfSense's guiconfig.inc (status page tests only).
 * GD_TEST_ADMIN=1 grants the settings privilege.
 */
require_once('config.inc');
if (!function_exists('gettext')) {
	function gettext($s) {
		return $s;
	}
}
function isAllowedPage($page) {
	return getenv('GD_TEST_ADMIN') === '1';
}
function display_top_tabs($tabs) {
	echo "<!-- tabs -->\n";
}
