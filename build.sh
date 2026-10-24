#!/bin/sh
# Copyright 2026 Jeff Nelson
# Licensed under the Apache License, Version 2.0.
#
# Build dist/pfSense-pkg-gamedetect-<version>-fbsd<major>.pkg from files/.
# Usage: build.sh <version> <freebsd-major> [--dry-run]
# --dry-run stages and renders everything but prints the pkg create command
# plus the manifest and plist instead of running it.
set -eu

usage() {
	echo "usage: $0 <version> <freebsd-major> [--dry-run]" >&2
	exit 1
}
if [ $# -lt 2 ] || [ $# -gt 3 ]; then
	usage
fi
version=$1
major=$2
dry=0
if [ "${3:-}" = "--dry-run" ]; then
	dry=1
elif [ $# -eq 3 ]; then
	usage
fi
case $major in
'' | *[!0-9]*) usage ;;
esac
name=pfSense-pkg-gamedetect
out=dist/$name-$version-fbsd$major.pkg
php=php
[ -x /usr/local/bin/php ] && php=/usr/local/bin/php

cd "$(dirname "$0")"
rm -rf stage plist manifest.json
mkdir -p stage dist

# Stage files/ (modes preserved), skipping .gitkeep placeholders.
(cd files && find . -type f ! -name .gitkeep | cpio -pdm ../stage 2>/dev/null)

for f in usr/local/pkg/gamedetect.xml usr/local/share/$name/info.xml; do
	sed "s/%%PKGVERSION%%/$version/g" "stage/$f" >"stage/$f.new"
	cat "stage/$f.new" >"stage/$f"
	rm -f "stage/$f.new"
done

find stage -type f | sed 's#^stage##' | sort >plist
"$php" pkg/gen-manifest.php "$version" "$major" >manifest.json

if [ $dry -eq 1 ]; then
	echo "pkg create -M manifest.json -p plist -r stage -o dist/"
	echo "would produce $out"
	echo "--- manifest.json"
	cat manifest.json
	echo "--- plist"
	cat plist
	exit 0
fi
pkg create -M manifest.json -p plist -r stage -o dist/
# pkg names the file <name>-<version>.pkg; add the ABI major to keep 15 and 16 apart.
mv "dist/$name-$version.pkg" "$out"
echo "$out"
