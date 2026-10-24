#!/bin/sh
# Copyright 2026 Jeff Nelson
# Licensed under the Apache License, Version 2.0.
#
# build.sh --dry-run stages the tree, substitutes the version, renders plist+manifest.
set -eu
root=$(cd "$(dirname "$0")/.." && pwd)
cd "$root"
fail() { echo "FAIL: $1"; exit 1; }

for major in 15 16; do
	out=$(sh build.sh 9.8.7 "$major" --dry-run) || fail "build.sh $major failed: $out"
	case $out in *"pkg create"*) ;; *) fail "no pkg create line: $out" ;; esac
	case $out in *"dist/pfSense-pkg-gamedetect-9.8.7-fbsd$major.pkg"*) ;; *) fail "no output name for $major: $out" ;; esac
	case $out in *"FreeBSD:$major:*"*) ;; *) fail "dry run lacks abi for $major" ;; esac
	case $out in *"freebsd:$major:*"*) ;; *) fail "dry run lacks arch for $major" ;; esac
	grep -q "\"abi\": \"FreeBSD:$major:\\*\"" manifest.json || fail "manifest abi $major"
	grep -q "\"arch\": \"freebsd:$major:\\*\"" manifest.json || fail "manifest arch $major"
done

(cd files && find . -type f ! -name .gitkeep) | while IFS= read -r f; do
	[ -f "stage/$f" ] || fail "missing from stage: $f"
done
if grep -rq '%%PKGVERSION%%' stage; then fail "unsubstituted %%PKGVERSION%% left"; fi
grep -q '<version>9.8.7</version>' stage/usr/local/pkg/gamedetect.xml || fail "version not substituted"
[ -x stage/usr/local/libexec/gamedetect/collect.sh ] || fail "collect.sh lost its exec bit"
[ ! -x stage/usr/local/pkg/gamedetect.inc ] || fail "gamedetect.inc should not be executable"

for p in usr/local/libexec/gamedetect/collect.sh usr/local/libexec/gamedetect/gamedetectd.php \
	usr/local/share/pfSense-pkg-gamedetect/signatures.default.json \
	usr/local/share/pfSense-pkg-gamedetect/signatures.default.history \
	usr/local/share/pfSense-pkg-gamedetect/info.xml \
	usr/local/www/status_gamedetect.php usr/local/pkg/gamedetect.xml; do
	grep -qx "/$p" plist || fail "plist lacks $p"
done
# The rc.d script is generated at resync by write_rcfile(), not shipped.
if grep -q 'rc.d' plist; then fail "plist must not ship an rc.d script"; fi
if grep -q '^stage' plist; then fail "plist has stage prefix"; fi

# shellcheck disable=SC2016
php -r '
$m = json_decode(file_get_contents("manifest.json"), true);
if (!is_array($m)) { fwrite(STDERR, "invalid JSON\n"); exit(1); }
if ($m["name"] !== "pfSense-pkg-gamedetect" || $m["version"] !== "9.8.7") { exit(2); }
foreach (["post-install", "pre-deinstall", "post-deinstall"] as $k) {
	if (strpos($m["scripts"][$k], "set -- pfSense-pkg-gamedetect") === false) { exit(3); }
}
' || fail "manifest check failed (rc $?)"
rm -rf stage dist plist manifest.json
echo "ok"
