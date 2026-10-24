#!/bin/sh
# Copyright 2026 Jeff Nelson
# Licensed under the Apache License, Version 2.0.
#
# pkg install hook. The build embeds this in +MANIFEST with $2 set to the phase.
# GD_ROOT prefixes every path (tests only; also skips rc.packages).
if [ "${2}" != "POST-INSTALL" ]; then
	exit 0
fi
root=${GD_ROOT:-}
share=$root/usr/local/share/pfSense-pkg-gamedetect
live=$root/usr/local/etc/gamedetect/signatures.json

# sha256 hex digest of a file: sha256(1) on FreeBSD, else sha256sum or shasum.
gd_sha256() {
	if command -v sha256 >/dev/null 2>&1; then
		sha256 -q "$1"
	elif command -v sha256sum >/dev/null 2>&1; then
		sha256sum "$1" | cut -d' ' -f1
	else
		shasum -a 256 "$1" | cut -d' ' -f1
	fi
}

# On upgrade the previous version's daemon is still running, and resync only
# sends a running daemon a HUP (which reloads settings, not code). Stop it
# here so the rc.packages resync below starts the newly installed code.
rcscript=$root/usr/local/etc/rc.d/gamedetectd.sh
if [ -x "$rcscript" ]; then
	"$rcscript" stop
fi

mkdir -p "$root/var/db/gamedetect" "$root/usr/local/etc/gamedetect"
# Seed the signatures when absent. Replace an unedited earlier default (its
# hash is in signatures.default.history) after a timestamped backup; never
# touch a file the operator edited. Done before rc.packages so the daemon's
# resync reload already sees the new file.
if [ ! -e "$live" ]; then
	cp "$share/signatures.default.json" "$live"
elif [ -f "$share/signatures.default.history" ]; then
	h=$(gd_sha256 "$live")
	if [ -n "$h" ] && grep -qx "$h" "$share/signatures.default.history"; then
		bak=$live.$(date -u +%Y%m%dT%H%M%SZ).bak
		if cp -p "$live" "$bak" && cp "$share/signatures.default.json" "$live.new.$$" \
			&& chmod 0644 "$live.new.$$" && mv -f "$live.new.$$" "$live"; then
			echo "gamedetect: updated unedited signatures.json to the new defaults (backup: $bak)"
		else
			rm -f "$live.new.$$"
			echo "gamedetect: could not update signatures.json to the new defaults" >&2
		fi
	fi
fi
if [ -z "$root" ]; then
	/usr/local/bin/php -f /etc/rc.packages pfSense-pkg-gamedetect POST-INSTALL
fi
exit 0
