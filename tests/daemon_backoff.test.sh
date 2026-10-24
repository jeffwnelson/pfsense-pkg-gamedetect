#!/bin/sh
# Copyright 2026 Jeff Nelson
# Licensed under the Apache License, Version 2.0.
#
# A poll slower than half the interval doubles the effective interval.
# shellcheck source=tests/daemon_harness.inc
. "$(dirname "$0")/daemon_harness.inc"

gd_conf 2
GD_FAKE_PFCTL_SLEEP=1.5; export GD_FAKE_PFCTL_SLEEP
gd_once || gd_fail "--once exited $?"
unset GD_FAKE_PFCTL_SLEEP
gd_expect "$(gd_q "$GD_DATA/daemon.json" "\$d['effective_interval']")" "4" "effective interval doubled"
gd_expect "$(gd_q "$GD_DATA/daemon.json" "\$d['state']")" "'running'" "still running"
gd_expect "$(gd_q "$GD_DATA/clients.json" "\$d['poll_interval']")" "2" "clients.json keeps configured interval"
