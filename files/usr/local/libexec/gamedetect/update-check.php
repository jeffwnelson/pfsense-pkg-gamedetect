<?php
/*
 * Copyright 2026 Jeff Nelson
 * Licensed under the Apache License, Version 2.0.
 *
 * Update check: ask GitHub for the latest release of GAMEDETECT_UPDATE_REPO
 * and record the result in /var/db/gamedetect/update.json. Never installs
 * anything. Run daily by cron (when "Check daily for new releases" is on) and
 * by Check now on Status > Game Detect.
 *
 * Usage: /usr/local/bin/php -f /usr/local/libexec/gamedetect/update-check.php
 */
require_once(__DIR__ . '/../../pkg/gamedetect.inc');

$s = gamedetect_update_check();
exit($s['error'] === null ? 0 : 1);
