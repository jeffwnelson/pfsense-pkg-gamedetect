<?php
/*
 * Copyright 2026 Jeff Nelson
 * Licensed under the Apache License, Version 2.0.
 *
 * Test stub for pfSense's gwlb.inc: gateways come from $GLOBALS['stub_gateways'].
 */
function return_gateways_array(bool $disabled = false, bool $localhost = false, bool $inactive = false, bool $integer_index = false): array {
	return $GLOBALS['stub_gateways'] ?? [];
}
