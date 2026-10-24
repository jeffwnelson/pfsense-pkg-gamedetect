# Copyright 2026 Jeff Nelson
# Licensed under the Apache License, Version 2.0.
#
# Reduce `pfctl -ss` output to outbound IPv4 udp/tcp state lines before PHP parsing.
# pfctl prints IPv6 endpoints as addr[port], so lines containing "[" are dropped.
/ (udp|tcp) / && / -> / && !/\[/
