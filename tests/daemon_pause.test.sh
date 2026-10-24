#!/bin/sh
# Copyright 2026 Jeff Nelson
# Licensed under the Apache License, Version 2.0.
#
# Loop mode: a poll slower than pause_threshold_s pauses the daemon; SIGHUP
# resumes it at the configured interval. A signatures.json broken while
# running raises signatures_invalid until it is fixed. SIGTERM exits 0.
# shellcheck source=tests/daemon_harness.inc
. "$(dirname "$0")/daemon_harness.inc"

pid=
# On any exit: stop the daemon (SIGTERM, then SIGKILL after ~3 s) before removing its dir.
gd_cleanup() {
	if [ -n "$pid" ]; then
		kill "$pid" 2>/dev/null
		n=0
		while kill -0 "$pid" 2>/dev/null && [ "$n" -lt 15 ]; do
			sleep 0.2
			n=$((n + 1))
		done
		kill -9 "$pid" 2>/dev/null
	fi
	rm -rf "$GD_TMP"
}
trap gd_cleanup EXIT
trap 'exit 1' INT TERM

# gd_wait <seconds> <file> <php expr over $d>: wait until the expression is true.
gd_wait() {
	n=0
	while [ "$n" -lt $(($1 * 5)) ]; do
		if [ "$(gd_q "$2" "$3" 2>/dev/null)" = true ]; then
			return 0
		fi
		kill -0 "$pid" 2>/dev/null || gd_fail "daemon exited early: $(cat "$GD_TMP/daemon.err")"
		sleep 0.2
		n=$((n + 1))
	done
	return 1
}

gd_conf 2 "" "" '{"pause_threshold_s": 1}'
GD_FAKE_PFCTL_SLEEP_FILE=$GD_TMP/pfctl-sleep
export GD_FAKE_PFCTL_SLEEP_FILE
echo 1.5 >"$GD_FAKE_PFCTL_SLEEP_FILE"

php -d display_errors=stderr -d error_reporting=E_ALL -f "$GD_DAEMON" -- --conf "$GD_CONF" 2>"$GD_TMP/daemon.err" &
pid=$!

D=$GD_DATA/daemon.json
C=$GD_DATA/clients.json
gd_wait 6 "$D" "\$d['state'] === 'paused'" || gd_fail "not paused within 6 s: $(cat "$D" 2>/dev/null)"
echo "ok slow poll pauses the daemon"
gd_expect "$(gd_q "$D" "str_starts_with(\$d['reason'] ?? '', 'poll took ') && str_contains(\$d['reason'], 'limit 1 s')")" "true" "pause reason names the poll time and limit"
paused_at=$(gd_q "$C" "\$d['generated_at']")
sleep 3
gd_expect "$(gd_q "$C" "\$d['generated_at']")" "$paused_at" "paused: clients.json not advancing"
gd_expect "$(gd_q "$D" "\$d['state']")" "'paused'" "still paused"

: >"$GD_FAKE_PFCTL_SLEEP_FILE"
kill -HUP "$pid"
gd_wait 6 "$D" "\$d['state'] === 'running'" || gd_fail "not running within 6 s of SIGHUP: $(cat "$D")"
echo "ok SIGHUP resumes"
gd_expect "$(gd_q "$D" "\$d['effective_interval']")" "2" "effective interval back to configured"
gd_wait 6 "$C" "\$d['generated_at'] !== $paused_at" || gd_fail "clients.json did not advance after SIGHUP"
echo "ok clients.json advances after resume"

# In-memory signature path: break the live file while running.
cp "$GD_SIGS" "$GD_TMP/signatures.good"
printf '{' >"$GD_SIGS"
warn="count(array_filter(\$d['warnings'], fn(\$w) => str_starts_with(\$w, 'signatures_invalid: '))) === 1"
gd_wait 6 "$D" "$warn" || gd_fail "no signatures_invalid warning: $(cat "$D")"
echo "ok broken signatures raise signatures_invalid"
for i in 1 2; do
	last=$(gd_q "$D" "\$d['last_poll_at']")
	gd_wait 6 "$D" "\$d['last_poll_at'] !== $last" || gd_fail "no poll $i after the warning"
	gd_expect "$(gd_q "$D" "$warn")" "true" "warning persists on poll $i"
done
gd_expect "$(gd_client game_id)" "'fortnite'" "still classified with the in-memory signatures"
cp "$GD_TMP/signatures.good" "$GD_SIGS"
gd_wait 6 "$D" "\$d['warnings'] === []" || gd_fail "warning not cleared: $(cat "$D")"
echo "ok restored signatures clear the warning"

kill -TERM "$pid"
rc=0
wait "$pid" || rc=$?
pid=
gd_expect "$rc" "0" "SIGTERM exits 0"
[ ! -s "$GD_TMP/daemon.err" ] || gd_fail "daemon stderr: $(cat "$GD_TMP/daemon.err")"
echo "ok no PHP errors"
