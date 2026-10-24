#!/bin/sh
# Copyright 2026 Jeff Nelson
# Licensed under the Apache License, Version 2.0.
#
# Slow PTR lookups are bounded (1 s budget per poll) and never count toward
# the poll time that drives the backoff.
# shellcheck source=tests/daemon_harness.inc
. "$(dirname "$0")/daemon_harness.inc"

# One unknown client talking to 8 unclassified destinations.
states=$GD_TMP/pfctl_unknown.txt
for i in 1 2 3 4 5 6 7 8; do
	echo "all udp 10.10.3.40:5000$i -> 5.6.8.$i:27015       MULTIPLE:MULTIPLE"
done >"$states"

gd_conf 2
GD_FAKE_PFCTL_FILE=$states; GD_FAKE_HOST_SLEEP=0.3; export GD_FAKE_PFCTL_FILE GD_FAKE_HOST_SLEEP
gd_once || gd_fail "--once exited $?"
unset GD_FAKE_PFCTL_FILE GD_FAKE_HOST_SLEEP
gd_expect "$(gd_q "$GD_DATA/unknown.json" "count(\$d['unknown'])")" "8" "8 unknown destinations"
named=$(gd_q "$GD_DATA/unknown.json" "count(array_filter(\$d['unknown'], fn(\$u) => in_array('example.test', \$u['names'], true)))")
[ "$named" -ge 1 ] && [ "$named" -lt 8 ] || gd_fail "rDNS budget: want 1..7 destinations named, got $named"
echo "ok rDNS stopped at the 1 s budget ($named of 8 looked up)"
gd_expect "$(gd_q "$GD_DATA/daemon.json" "\$d['effective_interval']")" "2" "rDNS time does not trigger backoff"
gd_expect "$(gd_q "$GD_DATA/daemon.json" "\$d['last_poll_ms'] < 2400")" "true" "last_poll_ms excludes the lookups (8 x 0.3 s)"
