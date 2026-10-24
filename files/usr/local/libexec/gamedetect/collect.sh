#!/bin/sh
# Copyright 2026 Jeff Nelson
# Licensed under the Apache License, Version 2.0.
#
# Usage: collect.sh <states|dns|arp|rdns <ipv4>>
#
# The only place gamedetectd's external commands are spelled out. Each source
# runs exactly one fixed command and exits with that command's status.
# GD_BIN_DIR (tests only) replaces the directory pfctl, unbound-control, arp
# and host are resolved from; awk always comes from /usr/bin.
here=$(dirname "$0")

bin() {
	if [ -n "${GD_BIN_DIR:-}" ]; then
		echo "$GD_BIN_DIR/${1##*/}"
	else
		echo "$1"
	fi
}

case "${1:-}" in
states)
	# Pipe through the prefilter; fail if either pfctl or awk fails (no
	# pipefail in POSIX sh, so each side reports its status on fd 3).
	pfctl=$(bin /sbin/pfctl)
	[ -r "$here/prefilter.awk" ] || { echo "collect.sh: missing $here/prefilter.awk" >&2; exit 1; }
	{ st=$( { { "$pfctl" -ss 2>/dev/null; echo "p$?" >&3; } | { /usr/bin/awk -f "$here/prefilter.awk" >&4; echo "a$?" >&3; }; } 3>&1); } 4>&1
	st=$(printf '%s' "$st" | tr '\n' ' ')
	case "$st" in
	'p0 a0' | 'a0 p0') exit 0 ;;
	esac
	echo "collect.sh: states failed ($st)" >&2
	exit 1
	;;
dns)
	exec "$(bin /usr/local/sbin/unbound-control)" -c /var/unbound/unbound.conf dump_cache
	;;
arp)
	exec "$(bin /usr/sbin/arp)" -an
	;;
rdns)
	case "${2:-}" in
	'' | *[!0-9.]*) echo "collect.sh: rdns needs an IPv4 address" >&2; exit 2 ;;
	esac
	exec "$(bin /usr/bin/host)" -W 1 "$2"
	;;
*)
	echo "usage: collect.sh <states|dns|arp|rdns <ipv4>>" >&2
	exit 2
	;;
esac
