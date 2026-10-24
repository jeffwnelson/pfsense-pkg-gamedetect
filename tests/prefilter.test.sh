#!/bin/sh
# Copyright 2026 Jeff Nelson
# Licensed under the Apache License, Version 2.0.
cd "$(dirname "$0")/.." || exit 1
fix=tests/fixtures/pfctl_ss.txt
awkf=files/usr/local/libexec/gamedetect/prefilter.awk
out=$(awk -f "$awkf" "$fix")
want=$(grep -E ' (udp|tcp) .* -> ' "$fix" | grep -vc '\[')
got=$(printf '%s\n' "$out" | wc -l | tr -d ' ')
[ "$got" = "$want" ] || { echo "FAIL line count: want $want got $got"; exit 1; }
printf '%s\n' "$out" | grep -q '\[' && { echo "FAIL ipv6 kept"; exit 1; }
printf '%s\n' "$out" | grep -q ' <- ' && { echo "FAIL inbound kept"; exit 1; }
printf '%s\n' "$out" | grep -q ' icmp ' && { echo "FAIL icmp kept"; exit 1; }
printf '%s\n' "$out" | grep -q 'ALTQ' && { echo "FAIL header kept"; exit 1; }
printf '%s\n' "$out" | grep -q '10.10.3.17:51234 -> 3.120.1.5:9017' || { echo "FAIL missing flow"; exit 1; }
echo "ok prefilter"
