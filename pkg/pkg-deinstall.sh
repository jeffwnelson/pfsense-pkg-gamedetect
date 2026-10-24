#!/bin/sh
# Copyright 2026 Jeff Nelson
# Licensed under the Apache License, Version 2.0.
#
# pkg deinstall hook. The build embeds this in +MANIFEST with $2 set to the phase.
# GD_ROOT prefixes every path (tests only; also skips rc.packages).
# usr/local/etc/gamedetect/signatures.json is operator data and is never removed.
# pkg(8) exports PKG_UPGRADE=true to scripts run as part of an upgrade
# (pkg-script(5) ENVIRONMENT); the state dir (first_seen history) is kept then.
root=${GD_ROOT:-}
case "${2}" in
DEINSTALL)
	if [ -z "$root" ]; then
		/usr/local/bin/php -f /etc/rc.packages pfSense-pkg-gamedetect DEINSTALL
	fi
	;;
POST-DEINSTALL)
	if [ -z "$root" ]; then
		/usr/local/bin/php -f /etc/rc.packages pfSense-pkg-gamedetect POST-DEINSTALL
	fi
	if [ -n "${PKG_UPGRADE:-}" ]; then
		exit 0
	fi
	rm -rf "$root/var/db/gamedetect"
	rm -f "$root/var/run/gamedetect-update.lock"
	;;
esac
exit 0
