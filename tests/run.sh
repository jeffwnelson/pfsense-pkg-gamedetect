#!/bin/sh
# Copyright 2026 Jeff Nelson
# Licensed under the Apache License, Version 2.0.
#
# Run all package tests and static checks. Exits non-zero if anything fails.
cd "$(dirname "$0")/.." || exit 1
rc=0

for t in tests/*.test.php; do
	[ -e "$t" ] || continue
	if php -d display_errors=1 -d error_reporting=E_ALL "$t" >/tmp/gamedetect-test.$$ 2>&1; then
		echo "PASS $t"
		grep -v '^ok ' /tmp/gamedetect-test.$$
	else
		echo "FAIL $t"
		cat /tmp/gamedetect-test.$$
		rc=1
	fi
done

for t in tests/*.test.sh; do
	[ -e "$t" ] || continue
	if sh "$t" >/tmp/gamedetect-test.$$ 2>&1; then
		echo "PASS $t"
	else
		echo "FAIL $t"
		cat /tmp/gamedetect-test.$$
		rc=1
	fi
done
rm -f /tmp/gamedetect-test.$$

while IFS= read -r f; do
	[ -n "$f" ] || continue
	if shellcheck "$f"; then
		echo "PASS shellcheck $f"
	else
		echo "FAIL shellcheck $f"
		rc=1
	fi
done <<EOT
$(find files tests pkg -name "*.sh" 2>/dev/null; [ -e build.sh ] && echo build.sh)
EOT

while IFS= read -r f; do
	[ -n "$f" ] || continue
	if php -l "$f" >/dev/null; then
		echo "PASS lint $f"
	else
		echo "FAIL lint $f"
		rc=1
	fi
done <<EOT
$(find files \( -name '*.php' -o -name '*.inc' \))
EOT

while IFS= read -r f; do
	[ -n "$f" ] || continue
	if xmllint --noout "$f"; then
		echo "PASS xmllint $f"
	else
		echo "FAIL xmllint $f"
		rc=1
	fi
done <<EOT
$(find files \( -path '*/usr/local/pkg/*.xml' -o -name info.xml \))
EOT

if command -v node >/dev/null 2>&1; then
	while IFS= read -r f; do
		[ -n "$f" ] || continue
		jsfile=$(mktemp "${TMPDIR:-/tmp}/gamedetect-js.XXXXXX") || exit 1
		php tests/js_extract.php "$f" >"$jsfile"
		if node --check --input-type=commonjs <"$jsfile" >/dev/null 2>/tmp/gamedetect-js.err.$$; then
			echo "PASS js $f"
		else
			echo "FAIL js $f"
			cat /tmp/gamedetect-js.err.$$
			rc=1
		fi
		rm -f "$jsfile" /tmp/gamedetect-js.err.$$
	done <<EOT
$(find files/usr/local/www -name '*.php')
EOT
else
	echo "SKIP js check (no node)"
fi

exit $rc
