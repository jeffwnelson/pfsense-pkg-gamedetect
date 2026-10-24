<?php
/*
 * Copyright 2026 Jeff Nelson
 * Licensed under the Apache License, Version 2.0.
 */
require __DIR__.'/assert.inc';
require __DIR__.'/../files/usr/local/pkg/gamedetect_lib.inc';
assert_true(GD_LIB_VERSION === '0.2.0', 'lib version');
