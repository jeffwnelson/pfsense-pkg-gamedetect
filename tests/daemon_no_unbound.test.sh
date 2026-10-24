#!/bin/sh
# Copyright 2026 Jeff Nelson
# Licensed under the Apache License, Version 2.0.
#
# unbound-control failing is a warning, not a crash; port/prefix rules still classify.
# shellcheck source=tests/daemon_harness.inc
. "$(dirname "$0")/daemon_harness.inc"

gd_conf 5
GD_FAKE_UNBOUND_FAIL=1; export GD_FAKE_UNBOUND_FAIL
gd_once || gd_fail "--once exited $?"
unset GD_FAKE_UNBOUND_FAIL
gd_expect "$(gd_q "$GD_DATA/daemon.json" "in_array('dns_cache_unavailable', \$d['warnings'], true)")" "true" "dns_cache_unavailable warning"
gd_expect "$(gd_client game_id)" "'fortnite'" "fortnite without DNS"
gd_expect "$(gd_client confidence)" "'low'" "low confidence without DNS"
