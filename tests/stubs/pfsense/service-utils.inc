<?php
/*
 * Copyright 2026 Jeff Nelson
 * Licensed under the Apache License, Version 2.0.
 *
 * Test stub for pfSense's service-utils.inc. write_rcfile() records its
 * params in $GLOBALS['stub_rcfile'] and, like pfSense, writes the generated
 * script to $GLOBALS['stub_rcfile_dir'] . '/' . $params['file'] when set.
 */
function write_rcfile($params) {
	$GLOBALS['stub_rcfile'][] = $params;
	$dir = $GLOBALS['stub_rcfile_dir'] ?? null;
	if ($dir === null || empty($params['start'])) {
		return;
	}
	$towrite = "#!/bin/sh\n# This file was automatically generated\n# by the pfSense service handler.\n\n";
	$towrite .= "rc_start() {\n\t{$params['start']}\n}\n\n";
	$towrite .= "rc_stop() {\n\t{$params['stop']}\n}\n\n";
	$towrite .= "case \$1 in\n\tstart)\n\t\trc_start\n\t\t;;\n\tstop)\n\t\trc_stop\n\t\t;;\n\trestart)\n\t\trc_stop\n\t\trc_start\n\t\t;;\nesac\n\n";
	file_put_contents("$dir/{$params['file']}", $towrite);
	chmod("$dir/{$params['file']}", 0755);
}
