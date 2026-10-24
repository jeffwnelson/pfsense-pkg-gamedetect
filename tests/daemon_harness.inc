# Copyright 2026 Jeff Nelson
# Licensed under the Apache License, Version 2.0.
#
# Sourced by tests/daemon_*.test.sh. Runs gamedetectd.php against the fake
# binaries in tests/fixtures/bin (via GD_BIN_DIR) with a temp data dir + conf.
GD_ROOT=$(cd "$(dirname "$0")/.." && pwd)
GD_DAEMON=$GD_ROOT/files/usr/local/libexec/gamedetect/gamedetectd.php
GD_BIN_DIR=$GD_ROOT/tests/fixtures/bin
export GD_BIN_DIR
GD_TMP=$(mktemp -d "${TMPDIR:-/tmp}/gamedetect-daemon.XXXXXX") || exit 1
GD_DATA=$GD_TMP/data
GD_CONF=$GD_TMP/gamedetectd.conf
GD_SIGS=$GD_TMP/signatures.json
trap 'rm -rf "$GD_TMP"' EXIT
mkdir -p "$GD_DATA"
cp "$GD_ROOT/tests/fixtures/signatures_test.json" "$GD_SIGS"

# gd_conf [poll_interval] [leases_cmd] [attendee_cidrs JSON] [extra keys JSON object]:
# write the daemon conf JSON.
gd_conf() {
	php -r '
		$c = ["attendee_cidrs" => json_decode($argv[7], true), "local_ips" => ["192.0.2.1"],
		    "poll_interval" => (int)$argv[1], "idle_timeout" => 120, "dns_refresh" => 30,
		    "rdns_enabled" => true, "unknown_list_size" => 50,
		    "data_dir" => $argv[2], "signatures_path" => $argv[3],
		    "default_signatures_path" => $argv[5]];
		if ($argv[4] !== "") { $c["leases_cmd"] = $argv[4]; }
		$c = array_merge($c, (array)json_decode($argv[8], true));
		file_put_contents($argv[6], json_encode($c, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
	' "${1:-5}" "$GD_DATA" "$GD_SIGS" "${2:-}" "$GD_TMP/no-default-signatures.json" "$GD_CONF" "${3:-[\"10.10.3.0/24\"]}" "${4:-null}"
}

# gd_once: one poll; exit status is the daemon's.
gd_once() {
	php -d display_errors=stderr -d error_reporting=E_ALL -f "$GD_DAEMON" -- --conf "$GD_CONF" --once
}

# gd_q <file> <php expr over $d>: print the expression's value (arrays as JSON).
gd_q() {
	php -r '
		$d = json_decode((string)@file_get_contents($argv[1]), true);
		if (!is_array($d)) { fwrite(STDERR, "not JSON: $argv[1]\n"); exit(3); }
		$v = eval("return " . $argv[2] . ";");
		echo is_array($v) ? json_encode($v) : var_export($v, true), "\n";
	' "$1" "$2"
}

# gd_client <field>: field of client 10.10.3.17 in clients.json.
gd_client() {
	gd_q "$GD_DATA/clients.json" "(array_values(array_filter(\$d['clients'], fn(\$c) => \$c['ip'] === '10.10.3.17'))[0] ?? [])['$1'] ?? null"
}

gd_fail() {
	echo "FAIL $*"
	exit 1
}

# gd_expect <got> <want> <message>
gd_expect() {
	[ "$1" = "$2" ] || gd_fail "$3: want [$2] got [$1]"
	echo "ok $3"
}
