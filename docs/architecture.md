# Architecture

How pfSense-pkg-gamedetect works, and why it is built the way it is.

## Purpose

The package answers one question for a LAN: which device is playing which
game right now. It was built for LAN parties, to map the games being played
to seats; pushing the per-client result to an external seating or event
system is a possible future integration, which is why the result is also
available as JSON.

Most multiplayer games talk to cloud-hosted servers over UDP, so every game
session crosses the firewall as an outbound, NATted UDP flow. pfSense
already tracks each of those flows in its pf state table, and its DNS
resolver (Unbound) already knows which names resolved to which addresses.
Classifying from those two sources needs no packet capture, no port mirror
and no payload inspection.

Design priorities, in order:

1. Never slow down or destabilise the firewall. A classifier that costs
   forwarding performance is worse than none.
2. Never inspect payloads. The daemon only sees connection 5-tuples, DNS
   names, ARP entries and DHCP hostnames.
3. Prefer `unknown` with evidence over a confident wrong answer.
4. Signatures are a plain JSON file anyone can edit, reloaded without a
   restart.

## Pipeline

One daemon, `gamedetectd` (PHP CLI), runs this loop every poll interval
(default 60 s):

```
pfctl -ss | awk prefilter --> parse attendee flows --+
unbound-control dump_cache --> ip -> names map ------+--> classify per client --> aggregate --> clients.json
arp -an -------------------> ip -> MAC --------------+                                     \-> unknown.json (+ rDNS)
DHCP leases ---------------> ip -> hostname ---------+                                     \-> daemon.json
```

1. **pf states.** `pfctl -ss` is piped through `prefilter.awk`, which keeps
   only IPv4 `udp`/`tcp` lines with a `->` (outbound) direction; IPv6
   endpoints (`addr[port]`) are dropped. PHP then parses the survivors
   (`gd_parse_states`): the source is the pre-NAT address in parentheses
   when present (so the LAN address and LAN-side source port survive NAT),
   and only flows whose source is in an attendee network are kept.
   Destinations in RFC 1918, CGNAT (100.64.0.0/10), loopback, link-local,
   multicast or broadcast space, and the firewall's own addresses, are
   dropped. TCP is kept only for destination port 443, as DNS evidence.
   UDP to 53 (DNS), 67/68 (DHCP), 123 (NTP), 1900 (SSDP), 5353 (mDNS) and
   3478-3481 (STUN) is dropped. Flows are deduplicated.
2. **DNS cache.** `unbound-control -c /var/unbound/unbound.conf dump_cache`
   is parsed (RRSET section) into `ip -> {names, expires}`. CNAME chains (up
   to depth 8) are flattened so an address carries every name that resolves
   to it. New dumps are merged into the previous map; entries are dropped
   when their TTL expires. The dump is the most expensive call, so it runs
   at most every DNS cache refresh interval (default 30 s). Unbound query
   logging is never enabled.
3. **Identity.** Every 30 s: `arp -an` gives `ip -> MAC`; `leases.php`
   gives `ip -> hostname` from pfSense's `system_get_dhcpleases()` (ISC or
   Kea; an active lease wins over an expired one for the same address).
4. **Classify** each client from its flows and the DNS names behind every
   destination it talks to (see [Rule model](#rule-model-and-scoring)).
5. **Aggregate** into per-client records, keep recently seen clients until
   the idle timeout, rank unclassified destinations, and write the JSON
   files atomically (temp file in the same directory, then rename).
6. **Reverse DNS** (optional) is looked up for unclassified destinations
   after `clients.json` is written.

The web UI only ever reads the JSON files; it never runs `pfctl` itself.

## Rule model and scoring

A signature file lists titles; each title has rules of two scopes.

- **Flow scope** (one UDP flow): `port` (destination port ranges),
  `src_port` (the client's LAN-side source port, before NAT), `prefix`
  (destination in a named CIDR set), `port_and_prefix` (both on the same
  flow).
- **Client scope**: `dns` globs, matched against the DNS names behind any
  destination the client is talking to, UDP or TCP/443. Matchmaking hands
  out raw server addresses nobody resolved, so DNS rarely attaches to the
  game flow itself; it attaches to the client's launcher and API sessions.

Per title and client:

1. Flow score = the best single UDP flow's sum of that title's matching
   flow-scope rule weights.
2. A title with flow score 0 is not a candidate. DNS alone never marks a
   client as playing (everyone has a launcher open), and TCP flows never
   score.
3. Client score = the sum of the weights of the title's `dns` rules with at
   least one matching glob (each rule counts once).
4. Total = flow score + client score.

Confidence comes from the file's `thresholds` (shipped: `high` >= 80,
`medium` >= 50, `low` >= 30); below `low` the client is `unknown`, with its
candidates still listed.

UDP 443 (QUIC) is dropped in the aggregator (not the parser) unless some
`port` / `port_and_prefix` rule claims 443.

### Weight policy

A flow-scope rule that is not prefix-scoped weighs less than `low` (shipped:
25) unless its port is unique to the title (shipped: Minecraft Bedrock
19132-19133 at 35). Published game port ranges are broad and shared, so on
their own they must not put a title on the board; below `low` they still make
the title a candidate and unlock its DNS points. A `src_port` rule on a fixed
client port weighs 40 (shipped: Source engine 27005 only). See
[signatures.md](signatures.md) for the full weight table.

### Ambiguity

Candidates are ranked by score. When the runner-up is within 10 points of
the top and itself at least `low`:

- Same publisher: `game_id` is `<publisher>:ambiguous` (for example
  `epic:ambiguous`), `game` is `<Publisher> (A or B)` (for example
  `Epic Games (Fortnite or Rocket League)`), and confidence is capped at
  `medium`.
- Different publishers: the client is `unknown`.

Every client record carries `candidates[]` (`id`, `name`, `score`, best
first), including unknown clients, so consumers can apply their own policy.

### Publisher-generic titles

Some evidence belongs to a publisher rather than one game. Valve's network
and 27000-27250 range carry every Steam title; Riot's network, ports
(League of Legends' game servers use 7000-8000 like Valorant) and its client
/ anti-cheat DNS are shared by League of Legends and Valorant. Putting that
evidence on each title makes the first title in the file win. The shipped
defaults therefore use generic titles:

- **`steam` "Steam / Valve game"** (publisher `valve`) owns the Valve flow
  rules: `port` 27000-27250 weight 25 and `prefix valve` 15 (flow-only 40,
  low). It has no `dns` rule and carries `"dns_optional": true`. CS2 and
  Dota 2 keep the same flow rules at weight 5 each (flow-only 10, never
  labelled) and are named by their game-specific `dns` rule at 60
  (60 + 10 = 70, medium), which keeps them more than 10 points ahead of
  `source_engine`'s 55.
- **`riot` "Riot game"** owns the shared Riot evidence: full flow weights
  (25 + 15), the Riot client DNS (`*.riotgames.com`, `*.riotcdn.net`,
  `*.pvp.net`, `*.rgpub.io*`) at 20, and the Riot voice `port_and_prefix`
  at 20. League of Legends and Valorant keep the flow rules at 5 + 5, carry
  the generic Riot DNS rule (20), and are named by a game-specific `dns` rule
  at 60 (LoL `*.leagueoflegends.com`, `lolstatic*.akamaihd.net*`; Valorant
  `*.playvalorant.com`, `*.valorant.*`). A LoL game flow on the Riot network
  scores: generic Riot names only -> Riot game 60 (medium); no DNS -> Riot
  game 40 (low); with a LoL host -> League of Legends 90 against Riot game
  60. LoL and Valorant are only told apart while the client holds a
  connection to a game-specific host.
- **`source_engine` "Source engine game"** (publisher `source`,
  `dns_optional`): `src_port` 27005 weight 40 (low) plus `port`
  27015-27150 weight 15 (55, medium). The destination range covers Valve's
  relay ports (27015-27140), so a Source client on a relay beats `steam`
  (55 against 40). A TF2 community-server flow (client port 27005 to a bare
  hosting address) reads as Source engine game, low. It cannot tell TF2,
  Garry's Mod, CS:S and L4D2 apart.

`src_port` rules are precompiled into their own segment table and sum with
the other flow rules on the same flow; their evidence reads
`src_port:27005`.

## Signature file

Format and authoring guide: [signatures.md](signatures.md). Loader rules:

- Top-level keys: `version` (must be `1`), `thresholds` (required; integers
  `high > medium > low > 0`, shipped 80/50/30), `prefix_sets` (required
  object, may be `{}`; IPv4 CIDRs or bare addresses), `games` (required,
  non-empty), optional `publishers` (publisher id -> display name, used in
  ambiguous labels; otherwise the id is capitalised). Keys starting with `_`
  (`_sources`, `_notes`) are documentation and are ignored and dropped by
  the loader; any other key is an error.
- Each title needs `id`, `name` and `publisher` (`id` and `publisher` match
  `^[a-z0-9_-]+$`) and a non-empty `rules` list. Rule types: `dns` (`match`
  globs), `port` (`dst_ports`), `src_port` (`src_ports`), `prefix` (`set`),
  `port_and_prefix` (`dst_ports` and `set`); ports are `"N"` or `"N-M"`
  strings; weights are integers 1-100. `dns_optional` is an optional boolean
  on a title (`null` or any non-boolean is rejected); the shipped-defaults
  test allows a title without a `dns` rule only with that flag and a
  `_notes` entry naming it.
- The loader precompiles CIDRs (indexed by first octet) and port ranges
  (binary-searchable port segments) under `_compiled`; the classifier never
  re-parses strings per flow, and prefix-set membership is memoised per
  (set, address) for the whole poll.

### Hot reload, lastgood and fallbacks

The daemon checks the live file's mtime and size every poll. A changed file
is parsed and validated as a whole:

- Valid: the new set is used from that poll, and a copy is written to
  `/var/db/gamedetect/signatures.lastgood.json`.
- Invalid: the in-memory set is kept, the error is logged once, and
  `daemon.json` carries the warning `signatures_invalid: <error>` until the
  file validates again.
- A freshly started daemon with a broken live file falls back to
  `signatures.lastgood.json`, then to the shipped defaults.

## Files and paths

| Path | Contents |
|---|---|
| `/usr/local/libexec/gamedetect/gamedetectd.php` | The daemon. |
| `/usr/local/libexec/gamedetect/collect.sh` | The only place external commands are spelled out (`states`, `dns`, `arp`, `rdns <ipv4>`). |
| `/usr/local/libexec/gamedetect/prefilter.awk` | The `pfctl -ss` prefilter. |
| `/usr/local/libexec/gamedetect/leases.php` | Prints `{ip: hostname}` JSON from the DHCP leases. |
| `/usr/local/libexec/gamedetect/update-check.php` | Update check (cron and Check now): writes `update.json`. |
| `/usr/local/libexec/gamedetect/update-run.php` | Updater (Update now): download, verify, `pkg add -f`. |
| `/usr/local/pkg/gamedetect.xml` | Package descriptor and settings form. |
| `/usr/local/pkg/gamedetect.inc` | pfSense glue: validation, conf and rc script, start/reload/stop, updater wiring. |
| `/usr/local/pkg/gamedetect_update_lib.inc` | Updater core: release parsing, checksums, check and run steps, lock. |
| `/usr/local/pkg/gamedetect_lib.inc` | Pure functions: parsers, loader, classifier, aggregator. |
| `/usr/local/pkg/gamedetect_status_lib.inc` | Status page helpers (rows, banners, reset). |
| `/usr/local/www/status_gamedetect.php` | Status > Game Detect and the JSON endpoint. |
| `/etc/inc/priv/gamedetect.priv.inc` | Page privileges. |
| `/usr/local/share/pfSense-pkg-gamedetect/signatures.default.json` | Shipped default signatures (read-only seed). |
| `/usr/local/share/pfSense-pkg-gamedetect/signatures.default.history` | sha256 of every earlier shipped default. |
| `/usr/local/etc/gamedetect/signatures.json` | Live, editable signatures. |
| `/usr/local/etc/gamedetect/gamedetectd.conf` | Daemon conf (JSON), generated from the settings. |
| `/usr/local/etc/rc.d/gamedetectd.sh` | rc script, generated at resync. |
| `/var/run/gamedetectd.pid` | Daemon pid. |
| `/var/run/gamedetect-update.lock` | Pid of a running updater. |
| `/var/db/gamedetect/` | `clients.json`, `unknown.json`, `daemon.json`, `signatures.lastgood.json`, `update.json`, `update-run.json`. |

## Daemon behaviour

- **Supervision.** The rc script (generated by pfSense's `write_rcfile()`)
  starts `daemon -f -p /var/run/gamedetectd.pid -r -R 5 php -f
  gamedetectd.php`, so daemon(8) restarts the PHP process if it dies. Stop
  signals the supervisor and waits up to 5 s for the child to exit.
- **Timing.** `last_poll_ms` covers the state table, DNS refresh and
  classification; identity refresh and rDNS are excluded. The daemon sleeps
  the remainder of the effective interval.
- **Backoff.** A poll slower than half the effective interval doubles it
  (max 60 s) and logs; a poll faster than a quarter of the configured
  interval restores it.
- **Pause.** A poll slower than the pause threshold (60 s) pauses polling
  with the reason `poll took X s (limit N s); send SIGHUP or restart to
  resume`, shown on the status page, rather than keep hammering the kernel.
  `pause_threshold_s` is a hidden conf key (1-3600, default 60) used by the
  tests; the settings page does not expose it.
- **No networks.** With no usable attendee CIDR the daemon reports
  `paused` / `no_attendee_networks` and re-reads its conf every 5 s instead
  of exiting, so daemon(8) does not restart-loop it.
- **Signals.** SIGHUP re-reads the conf (keeping the previous one if the
  new one is unusable) and the signatures, forces DNS and identity
  refreshes, and clears a pause. SIGTERM and SIGINT exit 0.
- **Warnings** in `daemon.json`: `states_unavailable`,
  `dns_cache_unavailable`, `signatures_invalid: <error>`, `arp_unavailable`,
  `leases_unavailable`. Each is turned into a banner on the status page.
- **Fail closed.** If the state read fails, or no signature set has ever
  loaded, the previous `clients.json` and `unknown.json` (and the
  aggregation history) are left untouched and only `daemon.json` is
  updated; the table never blanks because a source failed.
- **Restarts.** `first_seen` survives restarts: the daemon rebuilds its
  previous-poll state from `clients.json` on start.
- **rDNS.** At most 20 lookups and 1 s of wall-clock per poll (`host -W 1`),
  cached for an hour; destinations skipped are looked up on later polls.

## Client lifecycle

- A client appears when it has at least one UDP flow (after the filters
  above).
- A client with UDP but no candidate title at all is held one poll
  (`pending`) before it is listed as `unknown`, so a single stray flow does
  not add a row. A client already listed as unknown skips the hold.
- A client absent from the current poll but seen within the idle timeout is
  kept as a stale snapshot with `flows: 0`. Each poll is a snapshot of open
  states, and game UDP states expire about 60 s after the last packet, so
  the idle timeout (default 600 s) keeps the last known game through
  lobbies, queues and map changes.
- The Unclassified ranking (`unknown.json`) counts UDP destinations only of
  clients whose result is `unknown` (including first-poll pending clients),
  never the non-matching flows of classified clients. It is ranked by
  client count, then address, then port, and cut to the Unclassified list
  size.

## JSON outputs

### clients.json

```json
{
  "generated_at": "2026-10-23T21:04:10-05:00",
  "poll_interval": 60,
  "clients": [
    {
      "ip": "10.10.3.17",
      "mac": "a4:5e:60:12:34:56",
      "hostname": "DESKTOP-K9",
      "game": "Fortnite",
      "game_id": "fortnite",
      "confidence": "high",
      "score": 85,
      "candidates": [{"id": "fortnite", "name": "Fortnite", "score": 85}],
      "evidence": ["port:9000-9100", "dns:*.epicgames.com"],
      "flows": 3,
      "first_seen": "2026-10-23T20:41:10-05:00",
      "last_seen": "2026-10-23T21:04:10-05:00",
      "top_destinations": [{"ip": "3.120.1.5", "port": 9045, "names": []}]
    }
  ]
}
```

Clients are sorted by address. `mac` and `hostname` are `null` when
unknown. `flows` is the number of UDP flows in the current poll (0 for a
stale snapshot). `top_destinations` holds up to 5 UDP destinations, most
flows first, with their DNS-cache names. Unknown clients have
`"game": "unknown"`, `"game_id": "unknown"`, `"confidence": "unknown"`.
Timestamps are ISO 8601 in the firewall's local time.

### unknown.json

```json
{"generated_at": "<ISO 8601>",
 "unknown": [{"ip": "34.89.12.201", "port": 7777, "names": ["gs-uw1.example.net"], "clients": 38}]}
```

`names` are DNS-cache names plus the reverse DNS name when enabled.

### daemon.json

```json
{"state": "running|paused", "pid": 123, "started_at": "<ISO 8601>",
 "last_poll_at": "<ISO 8601>|null", "last_poll_ms": 41,
 "effective_interval": 60, "state_lines": 20512, "warnings": [],
 "reason": "<only when paused>"}
```

`state_lines` is the number of state lines after the awk prefilter, not the
raw `pfctl -ss` count.

### Status endpoint

`status_gamedetect.php?format=json` returns `clients.json` (normalised:
`clients` is always a list, `poll_interval` and `generated_at` always set)
plus:

- `daemon`: `daemon.json` plus `running` (from the pid file and a
  signal-0 check).
- `banner`: the plain-text operator message the page shows (daemon not
  running, paused, or a warning), or `null`.

It is served with `Cache-Control: no-store` and requires a logged-in
pfSense session with the Status: Game Detect privilege. The Clients tab
uses the same endpoint to refresh in place every poll interval.

## Settings

Services > Game Detect, stored in `config.xml` under
`installedpackages/gamedetect`. A package whose settings were never saved
runs with all defaults (enabled, reverse DNS on, attendee interface LAN).

| Setting | Range | Default | Purpose |
|---|---|---|---|
| Enable | checkbox | on | Run the daemon. |
| Attendee interfaces | interfaces | LAN | Each interface's IPv4 network is an attendee network. |
| Additional attendee networks | IPv4 CIDRs | empty | Extra attendee networks, one per line. |
| Poll interval | 2-60 s | 60 | Seconds between state-table polls. |
| Idle timeout | 30-900 s | 600 | How long a client stays listed after its last matching state. |
| DNS cache refresh | 10-300 s | 30 | Seconds between Unbound cache reads. |
| Reverse DNS | checkbox | on | Look up PTR names for unclassified destinations. |
| Unclassified list size | 10-500 | 50 | Destinations kept in `unknown.json`. |
| Update check | checkbox | on | Daily update-check cron job (see [Self-updater](#self-updater)). |

At least one attendee interface or CIDR is required. Saving writes
`gamedetectd.conf` (attendee CIDRs resolved from the interfaces, the
firewall's own interface addresses as `local_ips`, and the values above),
regenerates the rc script, then sends a running daemon SIGHUP, starts a
stopped one, or stops it when disabled, and installs or removes the
update-check cron job. Install never changes firewall rules, NAT or
Unbound configuration.

**Update check** is also treated as on for a configuration saved before
the field existed (v0.2.x), where the key is absent; `pkg_edit.php`
stores an unticked box as an empty value, which turns it off.

The 60 s poll default (since v0.1.3; it was 5 s) catches a game switch
within a minute at a twelfth of the CPU of a 5 s poll; the 600 s idle
timeout (was 120 s) keeps the last known game through lobbies and map
changes. Existing installs keep their saved values.

## Security model

- **No shell interpolation.** Every external command runs from
  `collect.sh` with a fixed command line, started via `proc_open()` with an
  argv array (no shell). The only argument that varies is the rDNS address,
  validated as IPv4 in PHP and again in `collect.sh`.
- **Validated settings.** Numbers must be whole numbers in range; attendee
  networks must be configured interface names or IPv4 CIDRs. Anything else
  (for example `10.0.0.0/8; id`) is rejected and never reaches the conf.
- **No payload inspection.** The daemon reads only the state table, the DNS
  cache, the ARP table and DHCP leases. Nothing about the network leaves
  the firewall; the only outbound traffic besides optional rDNS is the
  update check to GitHub (below).
- **Web UI.** All output is escaped (`htmlspecialchars` server-side, an
  equivalent escaper in the page script). Viewing the page and the JSON
  needs `page-status-gamedetect` ("WebCfg - Status: Game Detect"); the
  settings page needs `page-services-gamedetect` ("WebCfg - Services: Game
  Detect"). **Reset to defaults** is a POST guarded by pfSense's CSRF token
  and also needs the settings privilege (else HTTP 403); it is done in PHP
  (backup, temp file, rename), with no shell. **Update now** and **Check
  now** follow the same rules.
- **Privileges.** The daemon runs as root, as pfSense package daemons do,
  because `pfctl -ss` and `unbound-control` need it.
- **Updates.** See [Self-updater](#self-updater): nothing is installed
  without an administrator's click, the updater takes no input from the
  request, and downloads are pinned to the repo's release URLs and
  checked against the release's `SHA256SUMS`.

## Performance

- Real polls on a Xeon D-2123IT with ~500 state lines take 16-18 ms; polls
  that include the Unbound cache dump take about 300 ms. See
  [testing.md](testing.md#measured-results).
- A synthetic 300k-line state table took 3.7 s (prefilter + PHP) before the
  v0.1.1 classifier precompile. PHP reads the whole prefiltered output
  before parsing, so the pipeline is effectively serial; at that scale the
  backoff absorbs the cost.
- The precompiled classifier brought `gd_aggregate` for 700 clients x 40
  flows from 4.5 s to 0.33 s.
- The daemon is a single PHP process, uses at most one core, and runs
  outside the forwarding path.

## Packaging and upgrades

- There is no ports `Makefile` or `pkg-plist`. `build.sh <version>
  <freebsd-major>` stages `files/`, substitutes the version, generates the
  plist and `+MANIFEST` with `pkg/gen-manifest.php` (embedding
  `pkg/pkg-install.sh` and `pkg/pkg-deinstall.sh`), and runs `pkg create`,
  producing `pfSense-pkg-gamedetect-<version>-fbsd<major>.pkg`. The package
  is pure PHP and sh: its ABI is `FreeBSD:<major>:*`, so it is not tied to a
  pfSense point release. Builds are produced for FreeBSD 15 and 16. CI also
  attaches version-less copies (`pfSense-pkg-gamedetect-fbsd16.pkg`,
  `pfSense-pkg-gamedetect-fbsd15.pkg`) to each release so
  `releases/latest/download/` URLs always point at the newest build.
- The rc script is not shipped; `gamedetect.inc` generates it with
  `write_rcfile()` at resync.
- **Install / upgrade** (`pkg-install.sh`, POST-INSTALL): stop a running
  daemon (on upgrade the old code is still running, and a resync would only
  HUP it, which reloads settings, not code); create the data and conf
  directories; seed or refresh the signatures (below); then run pfSense's
  `rc.packages ... POST-INSTALL`, whose resync writes the conf and rc script
  and starts the new daemon. The signature step runs before `rc.packages`,
  so the daemon starts on the new file.
- **Signature refresh.** `signatures.default.history` lists the sha256 of
  every earlier shipped default. An absent live file is seeded; a live file
  whose hash is listed (an unedited earlier default) is copied to
  `signatures.json.<UTC YYYYmmddTHHMMSSZ>.bak` and replaced (temp file +
  `mv`); any other file (edited by the operator) is left alone. Hashing
  uses `sha256 -q`, falling back to `sha256sum` / `shasum -a 256` for test
  runs. `tests/signatures_history.test.php` checks, when git is available,
  that every earlier committed default is listed, so changing the default
  means adding the replaced version's hash.
- **Reset to defaults.** When the live file differs from the shipped
  default (or is missing), the Signatures tab shows an info box with a
  **Reset to defaults** button (POST `act=reset_signatures`). The handler
  checks the settings privilege (`isAllowedPage('pkg_edit.php?xml=gamedetect.xml')`),
  calls `gamedetect_reset_signatures()` (backup + temp/rename) and redirects
  to `?tab=signatures&reset=ok`. The daemon picks the file up on its next
  poll.
- **Uninstall** (`pkg-deinstall.sh`): DEINSTALL runs `rc.packages ...
  DEINSTALL` (stop the daemon, remove the rc script, the data dir, the
  update-check cron job and the update lock);
  POST-DEINSTALL runs `rc.packages ... POST-DEINSTALL`, which removes the
  menu, service and package registration from the config, and removes
  `/var/db/gamedetect` and `/var/run/gamedetect-update.lock`.
  `signatures.json` (operator data), its backups and
  `gamedetectd.conf` are kept. When pkg runs the deinstall hooks as part of
  an upgrade (`PKG_UPGRADE` set), `/var/db/gamedetect`, the cron job and
  the lock are kept, both in the shell hook and in `gamedetect_deinstall()`.
- The service status check in `gamedetect.xml` is a self-contained
  expression (pid file + signal 0), because Status > Services evaluates it
  without loading the package include.

## Self-updater

Check automatically, install only on click.

**Check** (`update-check.php`, `gamedetect_update_check()` ->
`gd_update_check()`). Run daily by a cron job that `gamedetect_resync()`
installs with pfSense's `install_cron_job()` (`/usr/local/bin/php -f
/usr/local/libexec/gamedetect/update-check.php`, hour 4, a random minute
chosen once and then kept so resyncs do not rewrite `config.xml`), and
synchronously by **Check now** (killed after 30 s, which covers the
`pkg query` fallback plus both requests; the POST always redirects, a
failure carrying only a fixed `?update_error=<code>` that the page maps to
a fixed message, so no text from the URL is shown). The job is removed when
**Update check** is off and on package removal (kept during an upgrade,
where the post-install resync re-asserts it).

1. GET `https://api.github.com/repos/<GAMEDETECT_UPDATE_REPO>/releases/latest`
   (8 s timeout, at most 1 MB, `User-Agent: pfSense-pkg-gamedetect/<version>`).
   The repo is a constant in `gamedetect.inc`, not a setting. HTTP 403 or
   429 is reported as "GitHub rate limit reached, try again later"
   (unauthenticated API calls are limited per source IP).
2. `gd_update_parse_release()`: `tag_name` must be exactly `vX.Y.Z`
   (`\z`-anchored, so a trailing newline fails too); drafts and prereleases
   are refused. The asset must be named exactly
   `pfSense-pkg-gamedetect-X.Y.Z-fbsd<major>.pkg`, and it and the
   `SHA256SUMS` asset must have a `browser_download_url` under
   `https://github.com/<repo>/releases/download/`, one tag segment, ending
   in `/<name>`, with no percent-encoding (so no encoded `../`). `html_url` is kept only if it is a release page of the repo.
   `body` is kept as `notes`, cut to 4 KB.
3. GET `SHA256SUMS` (8 s, at most 64 KB) and take the hash for that asset
   (`gd_update_parse_sums()`: `sha256 -r`, `sha256sum` text/binary and BSD
   tag lines).
4. Write `/var/db/gamedetect/update.json` atomically (temp file + rename):
   `checked_at, installed, latest, update_available, release_url, notes,
   asset_url, asset_name, sha256, error`. `update_available` is
   `version_compare(latest, installed, '>')` on strict `x.y.z` values. On any
   failure `error` is set and the previous release fields are kept;
   `update_available` is recomputed from the previous `latest` and the
   current installed version.

`update.json`'s stored `update_available` is not trusted later either:
`gamedetect_update_status()` (the banner and **Update now**) recomputes it
from the version installed at that moment (`gd_update_effective()`), and
every resync (so every install or upgrade) rewrites `update.json` the same
way, so a manual `pkg add` clears the banner straight away.

The FreeBSD major is `php_uname('r')` ("16.0-RELEASE" -> 16), with no
subprocess; it is the running kernel's release, which on pfSense matches
the `FreeBSD:<major>` package ABI. The installed version is read from
`info.xml`, into which `build.sh` substitutes the package version, so it is
always the version of the files on disk and needs no subprocess; `pkg query
%v pfSense-pkg-gamedetect` (argv, no shell) is the fallback.

HTTP needs PHP's curl extension (present on pfSense): HTTPS only, also for
redirects; up to 5 redirects; certificate and host verification on; the
size cap enforced while streaming. There is deliberately no fallback to
PHP's `https://` stream wrapper, which would follow a redirect to plain
`http://` and let a man in the middle serve both `SHA256SUMS` and the
package to a root updater; without curl the check fails with "PHP curl
extension required". No shell is involved.

Commands (`pkg add`, `pkg query`, the re-check) run through
`gd_update_exec()`: `proc_open()` with an argv array, output captured,
and a hard deadline that also covers a child that closed its output but
keeps running (the exit is polled with `proc_get_status()`, then SIGKILL),
so `proc_close()` can never block past it.

**Show.** `gd_status_update_html()` renders the banner on every tab of the
status page from `update.json` and `gd_update_run_view()` of
`update-run.json`: the available release with a release-notes link
(`rel="noopener"`), **Update now** / **Check now** for users allowed on
`pkg_edit.php?xml=gamedetect.xml`, and the last check time and error in
`text-muted`. Every value is escaped.

**Update now** (POST `act=update`). `gd_update_post_action()` decides the
action from the method, `act` and the settings privilege before any action
code runs; without the privilege the response is 403 and nothing happens.
csrf-magic guards the form as for every pfSense POST.
`gamedetect_update_start()` refuses when an update is running (lock held,
or `update-run.json` says running within its grace period) or none is
available, writes a `running` state, and starts
`/usr/sbin/daemon -f /usr/local/bin/php -f /usr/local/libexec/gamedetect/update-run.php`
with `mwexec_bg()`. The command line is a constant: nothing from the
request reaches it. `daemon(8)` detaches the updater, so it survives the web
request ending and the package files being replaced under it.

**Run** (`update-run.php`, `gamedetect_update_run()` -> `gd_update_run()`):

1. Take `/var/run/gamedetect-update.lock` (created with `O_EXCL`, holds the
   pid). A lock whose pid is dead, whose content is not a pid, or that is
   over an hour old (pid reuse) is stale and replaced. A second updater
   exits without touching anything.
2. Re-read `update.json`; never trust the request. Re-validate: `latest` is
   `x.y.z` and newer than the version installed now, `asset_name` is exactly
   the name for `latest` and the running major, `asset_url` passes the same
   prefix check as the check, `sha256` is 64 hex digits.
3. Download to `/tmp/gamedetect-update.<16 random hex>.pkg` (created
   exclusively, mode 0600; HTTPS, 120 s, at most 20 MB).
4. Verify the sha256 (`hash_equals`); on mismatch delete the file and stop:
   `pkg` never runs.
5. `pkg add -f <file>` via `proc_open()` with an argv array and a fixed
   environment (`PATH`, `HOME`, `LANG`; the package scripts call `cp`,
   `mkdir`, `php`), 15 min cap, output captured. Delete the file.
6. Re-run the check in a fresh PHP process (the new code), so
   `update.json` shows the new installed version.

Progress and the result go to `/var/db/gamedetect/update-run.json`:
`state (running|done|failed), started_at, finished_at, from, to, log (last
4 KB of pkg output), error`. While it says running, the status page polls
`?format=update_status` (the run view without the log) every 3 s and
navigates to itself once (`location.replace`, no resubmission) when the
state changes; the reloaded page shows the result, so there is no reload
loop. A `running` file with no lock holder 60 s after it started is shown
as failed (the updater died). Results are shown for a day.

**Security notes.**

- The updater never runs without an administrator clicking **Update now**;
  the daily job only reads release metadata.
- `SHA256SUMS` comes from the same GitHub release as the package. The check
  protects against a corrupted, truncated or swapped-in-transit download,
  not against a compromised release or repository: anyone who can publish
  a release in the repo can publish a matching checksum. Releases are built
  by the repository's CI workflow.
- Downloads are pinned to `https://github.com/<repo>/releases/download/`
  (GitHub then redirects to its download host); redirects are HTTPS-only,
  and there is no non-curl code path.
- No user input reaches a command line: the detached command is a constant,
  `pkg` and the check are run with argv arrays, the temp file name is
  generated, and everything the updater uses is re-read from `update.json`
  and re-validated.
- `pkg add` runs the package's own install scripts as root, as any manual
  `pkg add` does.

## Known limitations

- IPv4 only; IPv6 flows are ignored.
- UDP only for classification. TCP-only games (Minecraft Java Edition, most
  World of Warcraft gameplay) are not detected.
- Only traffic that crosses pfSense to the internet is seen. Servers hosted
  on the LAN (RFC 1918 destinations) are ignored, and clients must use
  pfSense as their NAT gateway.
- A device behind another NAT (a second router, a VPN) is seen as that
  router's address.
- Clients that bypass Unbound (DNS over HTTPS, hard-coded resolvers) give no
  DNS evidence and get lower confidence, often `unknown`.
- The state table is sampled once per poll; very short sessions between
  polls can be missed.
- Port ranges are shared by many games; titles that share a publisher's
  network are named only when a game-specific DNS name is present (see
  [signatures.md](signatures.md#known-limitations)).
- The JSON endpoint needs a logged-in pfSense session; there is no API key
  access yet.
