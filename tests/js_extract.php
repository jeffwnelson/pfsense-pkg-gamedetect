<?php
/*
 * Copyright 2026 Jeff Nelson
 * Licensed under the Apache License, Version 2.0.
 *
 * Print the <script> bodies of a PHP template, with <?...?> blocks replaced by 0,
 * so `node --check` can syntax-check the embedded JavaScript.
 */
$src = (string)file_get_contents($argv[1] ?? '');
preg_match_all('#<script[^>]*>(.*?)</script>#s', $src, $m);
foreach ($m[1] as $body) {
	echo preg_replace('/<\?.*?\?>/s', '0', $body), "\n";
}
