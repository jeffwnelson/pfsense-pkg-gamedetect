<?php
/*
 * Copyright 2026 Jeff Nelson
 * Licensed under the Apache License, Version 2.0.
 */
require __DIR__.'/assert.inc';
require __DIR__.'/../files/usr/local/pkg/gamedetect_lib.inc';
assert_true(function_exists('gd_ip_in_cidrs'), 'lib loads');
// The version comes from the release tag via build.sh, never from a constant.
assert_true(!defined('GD_LIB_VERSION'), 'no hard-coded lib version');
