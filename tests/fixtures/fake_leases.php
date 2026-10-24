<?php
/* Copyright 2026 Jeff Nelson. Licensed under the Apache License, Version 2.0. */
/* Fake leases helper for daemon tests: prints {ip: hostname} JSON like leases.php. */
echo json_encode(['10.10.3.17' => 'gamer-pc']), "\n";
