#!/bin/sh
# Copyright 2026 Jeff Nelson
# Licensed under the Apache License, Version 2.0.
#
# One full poll against the fixtures; a second poll keeps first_seen.
# shellcheck source=tests/daemon_harness.inc
. "$(dirname "$0")/daemon_harness.inc"

gd_conf 5 "$GD_ROOT/tests/fixtures/fake_leases.php"
gd_once || gd_fail "first --once exited $?"
[ -f "$GD_DATA/clients.json" ] || gd_fail "clients.json missing"
[ -f "$GD_DATA/unknown.json" ] || gd_fail "unknown.json missing"
[ -f "$GD_DATA/daemon.json" ] || gd_fail "daemon.json missing"

gd_expect "$(gd_client game_id)" "'fortnite'" "10.10.3.17 is fortnite"
gd_expect "$(gd_client mac)" "'a4:5e:60:aa:bb:cc'" "10.10.3.17 mac from arp"
gd_expect "$(gd_client hostname)" "'gamer-pc'" "10.10.3.17 hostname from leases"
gd_expect "$(gd_client confidence)" "'high'" "dns + port + prefix gives high"
gd_expect "$(gd_q "$GD_DATA/clients.json" "\$d['poll_interval']")" "5" "clients.json poll_interval"
gd_expect "$(gd_q "$GD_DATA/clients.json" "date_create_from_format(DATE_ATOM, \$d['generated_at']) !== false")" "true" "clients.json generated_at is ISO8601"
gd_expect "$(gd_q "$GD_DATA/unknown.json" "is_array(\$d['unknown']) && isset(\$d['generated_at'])")" "true" "unknown.json shape"
gd_expect "$(gd_q "$GD_DATA/unknown.json" "in_array('example.test', \$d['unknown'][0]['names'] ?? [], true)")" "true" "rDNS PTR name appended to unknown destination"

gd_expect "$(gd_q "$GD_DATA/daemon.json" "\$d['state']")" "'running'" "daemon state running"
gd_expect "$(gd_q "$GD_DATA/daemon.json" "\$d['warnings']")" "[]" "no warnings"
gd_expect "$(gd_q "$GD_DATA/daemon.json" "\$d['effective_interval']")" "5" "effective interval = configured"
gd_expect "$(gd_q "$GD_DATA/daemon.json" "is_int(\$d['pid']) && is_int(\$d['last_poll_ms']) && \$d['state_lines'] > 0 && isset(\$d['started_at'], \$d['last_poll_at'])")" "true" "daemon.json fields"

first=$(gd_client first_seen)
sleep 1
gd_once || gd_fail "second --once exited $?"
gd_expect "$(gd_client first_seen)" "$first" "first_seen survives a restart"
[ "$(gd_client last_seen)" != "$first" ] || gd_fail "last_seen did not advance"
echo "ok last_seen advanced"
