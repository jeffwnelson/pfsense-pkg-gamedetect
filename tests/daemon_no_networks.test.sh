#!/bin/sh
# Copyright 2026 Jeff Nelson
# Licensed under the Apache License, Version 2.0.
#
# No attendee networks: stay up paused (no restart loop), poll nothing.
# shellcheck source=tests/daemon_harness.inc
. "$(dirname "$0")/daemon_harness.inc"

gd_conf 5 "" "[]"
gd_once || gd_fail "--once exited $?"
gd_expect "$(gd_q "$GD_DATA/daemon.json" "\$d['state']")" "'paused'" "paused"
gd_expect "$(gd_q "$GD_DATA/daemon.json" "\$d['reason']")" "'no_attendee_networks'" "reason no_attendee_networks"
[ ! -e "$GD_DATA/clients.json" ] || gd_fail "clients.json written without networks"
echo "ok no clients.json"

# Loop mode stays alive and picks up a conf with networks within ~5 s.
php -f "$GD_DAEMON" -- --conf "$GD_CONF" >/dev/null 2>&1 &
pid=$!
sleep 1
kill -0 "$pid" 2>/dev/null || gd_fail "daemon exited without networks"
echo "ok daemon stays up"
gd_conf 5
n=0
while [ ! -e "$GD_DATA/clients.json" ] && [ $n -lt 80 ]; do
	sleep 0.1
	n=$((n + 1))
done
kill "$pid"
wait "$pid"
rc=$?
[ -e "$GD_DATA/clients.json" ] || gd_fail "conf change not picked up"
echo "ok conf change picked up"
gd_expect "$rc" "0" "SIGTERM exits 0"
