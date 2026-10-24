<?php
/*
 * Copyright 2026 Jeff Nelson
 * Licensed under the Apache License, Version 2.0.
 *
 * Update: install the release recorded in /var/db/gamedetect/update.json
 * (downloaded over HTTPS, checked against the release's SHA256SUMS, then
 * `pkg add -f`). Started detached by Update now on Status > Game Detect;
 * progress and the result go to /var/db/gamedetect/update-run.json. Takes
 * /var/run/gamedetect-update.lock so only one update runs at a time. Takes
 * no arguments: nothing from the web request reaches it.
 *
 * Usage: /usr/sbin/daemon -f /usr/local/bin/php -f /usr/local/libexec/gamedetect/update-run.php
 */
require_once(__DIR__ . '/../../pkg/gamedetect.inc');

exit(gamedetect_update_run());
