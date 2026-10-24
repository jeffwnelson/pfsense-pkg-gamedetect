#!/bin/sh
# Copyright 2026 Jeff Nelson
# Licensed under the Apache License, Version 2.0.
#
# pkg-install.sh seeds signatures.json when absent and replaces it (after a
# timestamped backup) when it is an unedited earlier default listed in
# signatures.default.history; an edited file is left alone. pkg-deinstall.sh
# removes the state dir (except during upgrades) but never the operator's
# signatures.json.
set -u
base=$(cd "$(dirname "$0")/.." && pwd)
tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT
rc=0
fail() { echo "FAIL $1"; rc=1; }

hash() {
	if command -v sha256 >/dev/null 2>&1; then
		sha256 -q "$1"
	elif command -v sha256sum >/dev/null 2>&1; then
		sha256sum "$1" | cut -d' ' -f1
	else
		shasum -a 256 "$1" | cut -d' ' -f1
	fi
}

share=$tmp/usr/local/share/pfSense-pkg-gamedetect
mkdir -p "$share"
echo '{"default":true}' >"$share/signatures.default.json"
echo '{"old_default":true}' >"$tmp/old.json"
{
	echo '# test history'
	hash "$tmp/old.json"
} >"$share/signatures.default.history"
sig=$tmp/usr/local/etc/gamedetect/signatures.json

GD_ROOT=$tmp sh "$base/pkg/pkg-install.sh" x POST-INSTALL || fail "install failed"
[ -d "$tmp/var/db/gamedetect" ] || fail "state dir not created"
cmp -s "$sig" "$share/signatures.default.json" || fail "signatures not seeded"

ls "$sig".*.bak >/dev/null 2>&1 && fail "seeding made a backup"

# An unedited earlier default (hash in the history file) is replaced, with a backup.
cp "$tmp/old.json" "$sig"
GD_ROOT=$tmp sh "$base/pkg/pkg-install.sh" x POST-INSTALL || fail "upgrade install failed"
cmp -s "$sig" "$share/signatures.default.json" || fail "unedited old default not replaced"
n=0
for b in "$sig".*.bak; do
	[ -e "$b" ] || continue
	n=$((n + 1))
	cmp -s "$b" "$tmp/old.json" || fail "backup $b does not hold the old default"
	case $b in
	"$sig".[0-9]*T[0-9]*Z.bak) ;;
	*) fail "backup name $b is not signatures.json.<UTC timestamp>.bak" ;;
	esac
done
[ "$n" -eq 1 ] || fail "expected 1 backup of the old default, found $n"
rm -f "$sig".*.bak

# The current default is not in the history: reinstalling leaves it (and makes no backup).
GD_ROOT=$tmp sh "$base/pkg/pkg-install.sh" x POST-INSTALL || fail "same-version reinstall failed"
cmp -s "$sig" "$share/signatures.default.json" || fail "current default changed on reinstall"
ls "$sig".*.bak >/dev/null 2>&1 && fail "reinstall of current default made a backup"

# An edited file is never touched.
echo '{"custom":true}' >"$sig"
GD_ROOT=$tmp sh "$base/pkg/pkg-install.sh" x POST-INSTALL || fail "reinstall failed"
grep -q custom "$sig" || fail "existing signatures.json overwritten"
ls "$sig".*.bak >/dev/null 2>&1 && fail "edited signatures.json was backed up"

# No history file: leave the live file alone.
mv "$share/signatures.default.history" "$tmp/history.saved"
cp "$tmp/old.json" "$sig"
GD_ROOT=$tmp sh "$base/pkg/pkg-install.sh" x POST-INSTALL || fail "install without history failed"
cmp -s "$sig" "$tmp/old.json" || fail "replaced live file without a history file"
mv "$tmp/history.saved" "$share/signatures.default.history"
echo '{"custom":true}' >"$sig"

GD_ROOT=$tmp sh "$base/pkg/pkg-install.sh" x PRE-INSTALL || fail "other phase failed"

# Upgrade: a daemon left running from the previous version must be stopped
# before rc.packages, so the resync starts the new code instead of sending
# the old process a HUP. The rc script is generated at resync; fake it here.
rcd=$tmp/usr/local/etc/rc.d
mkdir -p "$rcd"
# The fake must expand $1 when it runs, not now.
# shellcheck disable=SC2016
printf '#!/bin/sh\necho "$1" >>"%s"\n' "$tmp/rc.calls" >"$rcd/gamedetectd.sh"
chmod 0755 "$rcd/gamedetectd.sh"
GD_ROOT=$tmp sh "$base/pkg/pkg-install.sh" x POST-INSTALL || fail "install with existing rc script failed"
[ "$(cat "$tmp/rc.calls" 2>/dev/null)" = "stop" ] || fail "POST-INSTALL stops a running daemon before rc.packages (got: $(cat "$tmp/rc.calls" 2>/dev/null))"
rm -f "$rcd/gamedetectd.sh" "$tmp/rc.calls"
GD_ROOT=$tmp sh "$base/pkg/pkg-install.sh" x POST-INSTALL || fail "install without rc script failed"
[ ! -e "$tmp/rc.calls" ] || fail "no rc script: nothing to stop"

GD_ROOT=$tmp sh "$base/pkg/pkg-deinstall.sh" x DEINSTALL || fail "deinstall failed"
[ -d "$tmp/var/db/gamedetect" ] || fail "DEINSTALL removed state dir"
PKG_UPGRADE=1 GD_ROOT=$tmp sh "$base/pkg/pkg-deinstall.sh" x POST-DEINSTALL || fail "upgrade post-deinstall failed"
[ -d "$tmp/var/db/gamedetect" ] || fail "POST-DEINSTALL removed state dir during upgrade"
grep -q custom "$sig" || fail "upgrade deinstall touched signatures.json"
(unset PKG_UPGRADE; GD_ROOT=$tmp sh "$base/pkg/pkg-deinstall.sh" x POST-DEINSTALL) || fail "post-deinstall failed"
[ ! -e "$tmp/var/db/gamedetect" ] || fail "POST-DEINSTALL kept state dir"
grep -q custom "$sig" || fail "deinstall touched signatures.json"

[ "$rc" -eq 0 ] && echo "ok pkg scripts"
exit "$rc"
