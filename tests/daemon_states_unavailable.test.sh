#!/bin/sh
# Copyright 2026 Jeff Nelson
# Licensed under the Apache License, Version 2.0.
#
# A failed state read (pfctl or prefilter) is a warning and keeps clients.json.
# shellcheck source=tests/daemon_harness.inc
. "$(dirname "$0")/daemon_harness.inc"

# collect.sh without its prefilter must fail, not print an empty table.
mkdir "$GD_TMP/lx"
cp "$GD_ROOT/files/usr/local/libexec/gamedetect/collect.sh" "$GD_TMP/lx/"
if out=$(sh "$GD_TMP/lx/collect.sh" states 2>/dev/null); then
	gd_fail "collect.sh states succeeded without prefilter.awk (output: $out)"
fi
echo "ok collect.sh states fails without prefilter.awk"
# A prefilter that reads everything and then fails must fail the source too.
echo 'END { exit 3 }' >"$GD_TMP/lx/prefilter.awk"
if sh "$GD_TMP/lx/collect.sh" states >/dev/null 2>&1; then
	gd_fail "collect.sh states succeeded although awk exited 3"
fi
echo "ok collect.sh states fails when awk fails"

gd_conf 5
gd_once || gd_fail "first --once exited $?"
before=$(cat "$GD_DATA/clients.json")
GD_FAKE_PFCTL_FILE=$GD_TMP/missing.txt; export GD_FAKE_PFCTL_FILE
gd_once || gd_fail "second --once exited $?"
unset GD_FAKE_PFCTL_FILE
gd_expect "$(gd_q "$GD_DATA/daemon.json" "in_array('states_unavailable', \$d['warnings'], true)")" "true" "states_unavailable warning"
[ "$(cat "$GD_DATA/clients.json")" = "$before" ] || gd_fail "clients.json rewritten after a failed state read"
echo "ok clients.json kept"
