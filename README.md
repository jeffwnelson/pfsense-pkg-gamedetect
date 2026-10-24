# pfSense-pkg-gamedetect

Live "who is playing what" for your LAN, on pfSense.

## What it is

Game Detect is a pfSense package that shows which device on your network is
playing which game, right now. A small daemon on the firewall reads the pf
state table and the Unbound DNS resolver cache once a poll, matches each
device's outbound UDP flows and the DNS names behind its connections against
an editable signature file, and shows the result as a live table on
**Status > Game Detect**. There is no
packet capture and no payload inspection: it only uses connection metadata
that pfSense already has.

```
Status / Game Detect                          Clients: 41  Playing: 37  Unknown: 4
[ Clients ] [ Unclassified ] [ Signatures ]
[filter: ____________]  [All games v]  [Pause]  Auto-refresh every 60 s . Updated 21:04:10

IP            MAC                 Hostname      Game                Conf.   Flows  Last seen
10.10.3.17    a4:5e:60:12:34:56   DESKTOP-K9    Fortnite            high    3      21:04:10
10.10.3.22    3c:7c:3f:aa:bb:01   gamer-pc      League of Legends   high    2      21:04:10
10.10.3.40    d8:bb:c1:10:20:30   SEAT-112      Source engine game  low     1      21:03:10
10.10.4.101   —                   —             unknown             —       5      21:04:10  >
```

It was built for LAN parties, but works on any network where pfSense is the
gateway.

## Requirements

- **pfSense CE 2.9.x** (FreeBSD 16 build) or **2.8.x** (FreeBSD 15 build),
  amd64.
- **pfSense is the NAT gateway** for the devices you want to see: their
  internet traffic must go through it.
- **Unbound DNS Resolver recommended** (the pfSense default). DNS names are
  the strongest signal. Devices that bypass it (DNS over HTTPS, hard-coded
  public resolvers) still show up, but with lower confidence, often as
  `unknown`.

This is an unofficial package: it is not in the pfSense package manager and
is installed with `pkg add` from this repository's GitHub releases.

## Install

First find out which build you need. Run this in a shell on the firewall
(any of the methods below):

```sh
pkg config ABI
```

`FreeBSD:16:amd64` means the `fbsd16` package (pfSense 2.9.x);
`FreeBSD:15:amd64` means `fbsd15` (pfSense 2.8.x).

### 1. From the web GUI (Diagnostics > Command Prompt)

Open **Diagnostics > Command Prompt**, paste the command into **Execute
Shell Command** and click **Execute**.

pfSense 2.9.x:

```sh
pkg add https://github.com/jeffwnelson/pfsense-pkg-gamedetect/releases/latest/download/pfSense-pkg-gamedetect-fbsd16.pkg
```

pfSense 2.8.x:

```sh
pkg add https://github.com/jeffwnelson/pfsense-pkg-gamedetect/releases/latest/download/pfSense-pkg-gamedetect-fbsd15.pkg
```

### 2. From an SSH or console shell

Log in over SSH (or on the console) and choose option **8) Shell**, then run
the same command:

```sh
pkg add https://github.com/jeffwnelson/pfsense-pkg-gamedetect/releases/latest/download/pfSense-pkg-gamedetect-fbsd16.pkg
```

To install a specific version instead of the latest:

```sh
pkg add https://github.com/jeffwnelson/pfsense-pkg-gamedetect/releases/download/v0.2.0/pfSense-pkg-gamedetect-0.2.0-fbsd16.pkg
```

### 3. Offline (firewall without internet access)

On a workstation, download the package and the checksums from the
[releases page](https://github.com/jeffwnelson/pfsense-pkg-gamedetect/releases):

```sh
curl -LO https://github.com/jeffwnelson/pfsense-pkg-gamedetect/releases/latest/download/pfSense-pkg-gamedetect-fbsd16.pkg
curl -LO https://github.com/jeffwnelson/pfsense-pkg-gamedetect/releases/latest/download/SHA256SUMS
```

Verify it: the hash must match the line for that file in `SHA256SUMS`.

```sh
grep ' pfSense-pkg-gamedetect-fbsd16.pkg$' SHA256SUMS
sha256 -q pfSense-pkg-gamedetect-fbsd16.pkg          # FreeBSD
shasum -a 256 pfSense-pkg-gamedetect-fbsd16.pkg      # macOS / Linux
```

Copy it to the firewall and install it:

```sh
scp pfSense-pkg-gamedetect-fbsd16.pkg admin@<firewall>:/tmp/
ssh admin@<firewall>
pkg add /tmp/pfSense-pkg-gamedetect-fbsd16.pkg
```

### After install

- **Services > Game Detect**: settings.
- **Status > Game Detect**: the live table.
- **Status > Services**: the `gamedetectd` service ("Game Detect
  classifier"), which you can start, stop and restart there.

The daemon starts straight away with the defaults: enabled, attendee network
= your LAN interface, poll every 60 s, idle timeout 600 s, reverse DNS on.
Nothing in your firewall rules, NAT or DNS resolver configuration is
changed. The first results appear after the first poll.

## Configure

**Services > Game Detect**:

| Setting | Default | What it does |
|---|---|---|
| Enable | on | Runs the classifier daemon. Untick to stop it. |
| Attendee interfaces | LAN | Interfaces whose IPv4 network holds the devices to watch. Select every LAN / VLAN with players. |
| Additional attendee networks | empty | Extra IPv4 CIDRs, one per line (for example `10.20.0.0/16`), for networks not tied to one interface, such as routed subnets. |
| Poll interval | 60 s (2-60) | Seconds between state-table snapshots. 60 s is plenty for a live view; drop it to 5 s while testing a new signature. |
| Idle timeout | 600 s (30-900) | How long a device stays listed after its game traffic stops. Keeps the last game shown through lobbies, queues and map changes. Lower it if you want quitters to drop off sooner. |
| DNS cache refresh | 30 s (10-300) | Seconds between reads of the Unbound cache. Raise it on a very busy resolver if the dump is slow. |
| Reverse DNS | on | Looks up reverse DNS names for unclassified destinations (bounded: 20 lookups and 1 s per poll, cached for an hour). Turn it off if you do not want those lookups. |
| Unclassified list size | 50 (10-500) | How many unclassified destinations the Unclassified tab keeps. |
| Update check | on | Checks GitHub once a day for a new release and shows it on the status page. Never installs anything by itself; see [Updating](#updating). |

Saving applies the settings immediately (the daemon reloads; no restart).

## Using it

**Status > Game Detect** has three tabs.

- **Clients**: every attendee device with current (or recent, within the
  idle timeout) UDP traffic: IP, MAC, DHCP hostname, game, confidence,
  number of UDP flows and last seen. It refreshes itself every poll
  interval; use the filter box, the game drop-down and **Pause**. Rows for
  `unknown` devices have a chevron that expands their top destinations and
  the DNS names behind them.
- **Unclassified**: the UDP destinations most common among devices the
  signatures do not classify, ranked by how many devices use them. This is
  where you discover games the signatures miss.
- **Signatures**: the live signature file, its path, when it changed, and
  whether it loaded.

Confidence levels:

| Confidence | Meaning |
|---|---|
| high | Game-specific DNS names plus matching game traffic. |
| medium | Good evidence, but not conclusive (for example DNS plus traffic on the publisher's network, or an ambiguous pair). |
| low | Game traffic on the publisher's network, a title-unique port, or a fixed client port, but no DNS confirmation. |
| unknown | UDP traffic that no signature explains with enough confidence. Candidates are still recorded in the daemon's `/var/db/gamedetect/clients.json`. |

Some labels name a publisher or engine rather than one game, on purpose:

- **Steam / Valve game**: traffic on Valve's network (Steam Datagram Relay)
  without a CS2 or Dota 2 DNS name. Every Steam game using Valve's relays
  looks like this.
- **Riot game**: Riot network and client traffic without a League of Legends
  or Valorant specific DNS name. It turns into the game when the device
  talks to a game-specific host.
- **Source engine game**: a client using the Source engine's fixed client
  port 27005 (TF2, Garry's Mod, CS:S, L4D2 and others on community servers).
- **Epic Games (Fortnite or Rocket League)**, and other `Publisher (A or B)`
  labels: two titles of the same publisher scored within 10 points of each
  other.

## Signatures

| File | Role |
|---|---|
| `/usr/local/etc/gamedetect/signatures.json` | The live file. Edit this one. |
| `/usr/local/share/pfSense-pkg-gamedetect/signatures.default.json` | The shipped defaults (read-only seed). |

Edit the live file with **Diagnostics > Edit File** (or `vi` over SSH). The
daemon checks it every poll and loads it when it changes: no restart. If the
file is broken (bad JSON, unknown key, bad value) the daemon keeps the
previous rules and the status page shows a banner with the error until you
fix it.

Upgrades replace the live file with the new defaults only if you never
edited it (the old file is kept as `signatures.json.<UTC timestamp>.bak`);
an edited file is left alone. When your file differs from the shipped
defaults, the Signatures tab offers **Reset to defaults** (backs your file
up the same way first; needs access to Services > Game Detect).

A tiny example title:

```json
{"id": "example_shooter", "name": "Example Shooter", "publisher": "example", "rules": [
  {"type": "dns",  "match": ["*.example-shooter.net"], "weight": 60},
  {"type": "port", "dst_ports": ["7777-7790"], "weight": 25}
]}
```

The rule format, scoring, weight policy and a step-by-step guide to adding a
game from the Unclassified tab are in [docs/signatures.md](docs/signatures.md).

## Updating

### From the status page

Once a day (at a random minute between 04:00 and 04:59) the firewall asks
GitHub whether a newer release exists. When one does, every tab of
**Status > Game Detect** shows:

```
Game Detect v0.3.0 is available (installed v0.2.0). Release notes    [Update now] [Check now]
```

- **Release notes** opens the release page on GitHub.
- **Update now** downloads the package built for your pfSense (FreeBSD 15
  or 16), checks it against the release's `SHA256SUMS`, and installs it
  with `pkg add -f`. It runs in the background: the banner shows
  "Updating to v0.3.0", the page reloads by itself when it is done, and
  then shows the result (on failure with the updater output). Settings,
  client history and an edited signature file are kept, exactly as with
  a manual upgrade.
- **Check now** runs the check immediately (it takes a few seconds).

The buttons are only shown to users with the **WebCfg - Services: Game
Detect** privilege; others see the notice only. When no update is
available those users see a small "Last checked" line with **Check now**,
and any check error is shown there.

Nothing is ever installed without someone clicking **Update now**. The
check contacts only GitHub: `api.github.com` for the latest release and
`github.com` for its `SHA256SUMS` file (GitHub serves release files from
its download host, `*.githubusercontent.com`). **Update now** downloads
the package from the same place. The request carries a
`User-Agent: pfSense-pkg-gamedetect/<version>` header and nothing else
about your firewall.

To turn the daily check off, untick **Update check** in **Services > Game
Detect** and save; the cron job is removed. **Check now** still works.

The release source is the `GAMEDETECT_UPDATE_REPO` constant in
`/usr/local/pkg/gamedetect.inc`; a fork changes it there.

### Manually with pkg add

The `pkg add` command from [Install](#install) still works and is what to
use on a firewall without internet access. Run it with the newer package
(the `latest` URL always points at the newest release):

```sh
pkg add https://github.com/jeffwnelson/pfsense-pkg-gamedetect/releases/latest/download/pfSense-pkg-gamedetect-fbsd16.pkg
```

The daemon is restarted on the new code, your settings are kept, the client
history in `/var/db/gamedetect` is kept, and an unedited signature file is
refreshed to the new defaults.

### After a pfSense upgrade

After upgrading pfSense itself, check that Game Detect is still installed
and re-run the install command if it is missing: unofficial packages are not
reinstalled from the pfSense repository. Your settings are stored in the
pfSense configuration and come back with the package.

## Uninstall

```sh
pkg delete pfSense-pkg-gamedetect
```

This stops the daemon and removes the menus, the service entry, the daily
update-check cron job, the package registration, the program files and the
data in `/var/db/gamedetect` (including the update status files). Your
`/usr/local/etc/gamedetect/signatures.json` (and any `.bak` copies) is kept;
delete `/usr/local/etc/gamedetect` by hand if you do not want it.

## Performance

The daemon is one PHP process that wakes up once per poll, uses at most one
core, and is not in the forwarding path, so it has no impact on routing
throughput. On a Xeon D-2123IT running pfSense 2.9.0 a normal poll (~500
state lines) takes 16-18 ms, and about 300 ms when it also reads the DNS
cache. A synthetic 300k-line state table took 3.7 s to parse; on very large
state tables the daemon backs off (doubles its interval, up to 60 s) instead
of loading the firewall, and pauses with a banner if a single poll takes
over 60 s. Details in [docs/testing.md](docs/testing.md#measured-results).

## Privacy and security

- Only connection metadata is used: IP addresses, ports, the DNS names your
  resolver already cached, MAC addresses from the ARP table and DHCP
  hostnames. Packet payloads are never read, and DNS query logging is never
  turned on.
- Nothing about your network leaves the firewall. There is no telemetry.
  The only outbound connections are the optional reverse DNS lookups,
  which go to the firewall's configured DNS servers, and the daily update
  check (on by default, see [Updating](#updating)), which contacts only
  GitHub (`api.github.com` and `github.com` release downloads) and sends
  nothing but a `User-Agent` with the installed version.
- Updates are installed only when an administrator clicks **Update now**.
  The download is checked against the release's `SHA256SUMS`; that
  catches a corrupted or truncated download, but since both come from the
  same GitHub release it does not protect against a compromised release.
- Results are shown only on the **Status > Game Detect** page and stay on
  the firewall: there is no API or data export. The daemon's working files
  are in `/var/db/gamedetect`.
- The status page is visible to pfSense users with the
  **WebCfg - Status: Game Detect** privilege; the settings, **Reset to
  defaults**, **Update now** and **Check now** need **WebCfg - Services:
  Game Detect**.
- No user input reaches a shell: external commands run from a fixed script
  with argument arrays, and settings are validated (interface names and
  IPv4 CIDRs only).

## Known limitations

- IPv4 only.
- UDP games only; TCP-only games (Minecraft Java Edition, most World of
  Warcraft gameplay) are not detected.
- Only internet-bound traffic through pfSense is seen; game servers hosted
  on your LAN are ignored.
- A device behind another router or a VPN shows as that router's or tunnel's
  address.
- Devices that bypass the pfSense resolver get lower confidence.
- Some titles that share a publisher's network (League of Legends and
  Valorant, Steam games) are only told apart by a game-specific DNS name;
  otherwise they show as the publisher-generic label.

More in [docs/signatures.md](docs/signatures.md#known-limitations) and
[docs/architecture.md](docs/architecture.md#known-limitations).

## Building from source and running tests

Tests and static checks (needs PHP 8.x CLI with the pcntl and posix
extensions, `shellcheck` and `xmllint`; `node` is optional and checks the
page's JavaScript):

```sh
sh tests/run.sh
```

Build a package (on FreeBSD, needs `pkg` and PHP):

```sh
./build.sh 0.2.0 16     # dist/pfSense-pkg-gamedetect-0.2.0-fbsd16.pkg
./build.sh 0.2.0 15     # dist/pfSense-pkg-gamedetect-0.2.0-fbsd15.pkg
```

`./build.sh 0.2.0 16 --dry-run` stages the tree and prints the manifest and
file list without needing `pkg`. CI (`.github/workflows/build.yml`) runs the
tests and builds both packages in a FreeBSD VM on every `v*` tag and
publishes them, with `SHA256SUMS`, as a GitHub release.

How it works: [docs/architecture.md](docs/architecture.md). Acceptance
checklist for your own install: [docs/testing.md](docs/testing.md).

## Contributing

Issues and pull requests are welcome. The most useful contribution is a game
signature: open a pull request that adds or fixes a title in
`files/usr/local/share/pfSense-pkg-gamedetect/signatures.default.json`, with
the evidence you based it on (the destinations, ports and DNS names from the
Unclassified tab or a client's expanded row, with addresses of your own
devices removed) and a `_sources` entry for any published port range or
prefix list. Changing the shipped default also means adding the previous
default's sha256 to `signatures.default.history` (the tests check this), so
unedited installs pick up the change on upgrade. Run `sh tests/run.sh` before
you push.

## License

Apache License 2.0. See [LICENSE](LICENSE).
