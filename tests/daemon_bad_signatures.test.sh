#!/bin/sh
# Copyright 2026 Jeff Nelson
# Licensed under the Apache License, Version 2.0.
#
# A broken signatures.json keeps the last good set and raises a warning.
# shellcheck source=tests/daemon_harness.inc
. "$(dirname "$0")/daemon_harness.inc"

gd_conf 5
gd_once || gd_fail "first --once exited $?"
gd_expect "$(gd_client game_id)" "'fortnite'" "good signatures classify"
printf '{' >"$GD_SIGS"
gd_once || gd_fail "second --once exited $?"
gd_expect "$(gd_client game_id)" "'fortnite'" "still classified with last good signatures"
gd_expect "$(gd_q "$GD_DATA/daemon.json" "count(array_filter(\$d['warnings'], fn(\$w) => str_starts_with(\$w, 'signatures_invalid: ')))")" "1" "signatures_invalid warning"

# No usable signatures at all (live, last good, defaults): keep the old table.
before=$(gd_client first_seen)
printf '{' >"$GD_DATA/signatures.lastgood.json"
sleep 1
gd_once || gd_fail "third --once exited $?"
gd_expect "$(gd_client first_seen)" "$before" "no signatures: previous clients kept"
gd_expect "$(gd_client game_id)" "'fortnite'" "no signatures: clients.json not wiped"
