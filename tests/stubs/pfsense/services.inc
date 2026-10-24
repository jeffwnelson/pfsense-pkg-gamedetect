<?php
/*
 * Copyright 2026 Jeff Nelson
 * Licensed under the Apache License, Version 2.0.
 *
 * Test stub for pfSense's services.inc. install_cron_job() records its
 * arguments in $GLOBALS['stub_cron_calls'] and, like pfSense, adds, replaces
 * or removes the matching cron/item entry (matched by command substring) in
 * the stub config.
 */
function install_cron_job($command, $active = false, $minute = "0", $hour = "*", $monthday = "*", $month = "*", $weekday = "*", $who = "root", $write_config = true) {
	$GLOBALS['stub_cron_calls'][] = func_get_args();
	$items = config_get_path('cron/item', []);
	$x = null;
	foreach ($items as $i => $item) {
		if (strstr($item['command'], $command)) {
			$x = $i;
			break;
		}
	}
	if ($active) {
		$entry = ['minute' => $minute, 'hour' => $hour, 'mday' => $monthday, 'month' => $month,
		    'wday' => $weekday, 'who' => $who, 'command' => $command];
		if ($x === null) {
			$items[] = $entry;
		} else {
			$items[$x] = $entry;
		}
	} elseif ($x !== null) {
		unset($items[$x]);
	}
	config_set_path('cron/item', array_values($items));
}
