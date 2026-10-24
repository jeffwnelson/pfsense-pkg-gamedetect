# Game signatures: format and authoring guide

How game-detect decides which title a client is playing, and how to add or
fix a title while it is running.

## Where the file lives

| File | Role |
|---|---|
| `/usr/local/etc/gamedetect/signatures.json` | Live file. Edit this one. |
| `/usr/local/share/pfSense-pkg-gamedetect/signatures.default.json` | Shipped defaults. Copied to the live path on install if the live file is absent. On upgrade it replaces a live file you never edited (see below); an edited live file is left alone. |
| `/usr/local/share/pfSense-pkg-gamedetect/signatures.default.history` | sha256 of every earlier shipped default, one per line (`#` comments). |

Edit over SSH (`vi`) or with Diagnostics > Edit File. The Signatures tab on
Status > Game Detect shows the live file, its path and when it was last
loaded.

Upgrades: the package install hook hashes the live file. If the hash is in
`signatures.default.history` (the file is an unedited earlier default) it is
copied to `signatures.json.<UTC timestamp>.bak` and replaced with the new
default; otherwise (you edited it) it is left alone. When the live file
differs from the shipped default, the Signatures tab says so and offers
**Reset to defaults** (needs the Services > Game Detect settings privilege):
it backs the live file up the same way and installs the default; the daemon
loads it on its next poll. Re-apply your edits from the backup if you still
need them.

## Hot reload

The daemon checks the live file's mtime and size every poll (default every 60 s). When it
changes, the daemon parses and validates the whole file:

- If it is valid, the new rules take effect on that poll. Nothing restarts.
- If it is broken (bad JSON, unknown key, bad CIDR, weight out of range, ...)
  the daemon keeps the rules it had, logs the error, and the status page
  shows a warning with the error text, for example
  `games[3].rules[1].set: unknown prefix set "riot2"`. Fix the file and save
  again; the next poll picks it up.

So a typo never blanks the table, but your change is also not live until the
warning clears. Check the page after every save.

To test a file without touching the live one:

```sh
php -r 'require "/usr/local/pkg/gamedetect_lib.inc";
  var_export(gd_load_signatures(file_get_contents($argv[1]))["error"] ?? "ok");' /tmp/sigs.json
```

## Top-level keys

```json
{
  "version": 1,
  "thresholds": {"high": 80, "medium": 50, "low": 30},
  "publishers": {"epic": "Epic Games"},
  "prefix_sets": {"valve": ["155.133.224.0/19", "162.254.192.0/21"]},
  "games": [ ... ],
  "_sources": [ ... ],
  "_notes": [ ... ]
}
```

| Key | Required | Meaning |
|---|---|---|
| `version` | yes | Must be `1`. |
| `thresholds` | yes | Integers, `high > medium > low > 0`. Score needed for each confidence. |
| `prefix_sets` | yes | Object: set name to a list of IPv4 CIDRs (`"a.b.c.d/n"` or a bare address). May be `{}`. |
| `games` | yes | Non-empty list of titles (below). |
| `publishers` | no | Object: publisher id to display name. Used in ambiguous labels; without it the id is capitalised (`epic` shows as `Epic`). |
| `_anything` | no | Any key starting with `_` is ignored. Use `_sources` (URLs and the date checked) and `_notes`. |

Any other top-level key is an error, so a misspelt key fails loudly instead of
being silently ignored.

## A title

```json
{"id": "fortnite", "name": "Fortnite", "publisher": "epic", "rules": [
  {"type": "dns",  "match": ["*.epicgames.com", "*.epicgames.dev"], "weight": 60},
  {"type": "port", "dst_ports": ["9000-9100"], "weight": 25}
]}
```

| Field | Rule |
|---|---|
| `id` | `^[a-z0-9_-]+$`, unique. Shown in JSON output and consumed by integrations via the JSON endpoint, so do not rename casually. |
| `name` | Display name, non-empty. |
| `publisher` | `^[a-z0-9_-]+$`. Titles that share a publisher can be reported as an ambiguous pair (below). |
| `rules` | Non-empty list. Each rule has a `type` and an integer `weight` 1-100. |
| `dns_optional` | Optional boolean (default `false`). Marks a title that has no `dns` rule on purpose; say why in `_notes`. The shipped-defaults test requires every title to have a `dns` rule unless this is `true`. Used by `steam` (Steam domains are resolved by every PC with Steam open, so they say nothing about the game). |

Rule types:

| Type | Scope | Fields | Fires when |
|---|---|---|---|
| `dns` | client | `match`: list of globs | Any DNS name behind any destination the client is talking to (UDP or TCP/443) matches any glob. |
| `port` | flow | `dst_ports`: list of `"N"` or `"N-M"` | A UDP flow's destination port is in a range. |
| `src_port` | flow | `src_ports`: list of `"N"` or `"N-M"` | A UDP flow's source port (the client's LAN-side port, before NAT) is in a range. Use it for games that bind a fixed local port (Source engine clients use 27005). Evidence reads `src_port:27005`. |
| `prefix` | flow | `set`: a `prefix_sets` name | A UDP flow's destination IP is in the set. |
| `port_and_prefix` | flow | `dst_ports` and `set` | Both, on the same flow. Use it when a port range is only meaningful on the publisher's own network. |

Globs: `*` matches any run of characters including dots, so
`*.epicgames.com` matches `fngw-mcp-gc-livefn.ol.epicgames.com`. A leading
`*.` also matches the bare domain (`*.epicgames.com` matches `epicgames.com`).
Matching is case-insensitive. A glob without `*` must match exactly.

DNS names come from the pfSense resolver (Unbound) cache: the names that
resolved to each destination IP, with CNAME chains flattened. A client using
its own DNS (DoH, 8.8.8.8 hard-coded) gives no DNS evidence. Such a client
reaches `low` only from prefix-scoped evidence (`port_and_prefix`, or
`port` + `prefix` on the publisher's own network) or a title-unique port; a
broad plain port range alone deliberately stays `unknown` (see Weights and
Known limitations).

## Scoring

For each title, per client:

1. **Flow score**: for every UDP flow of the client, add up the weights of
   that title's flow-scope rules (`port`, `src_port`, `prefix`,
   `port_and_prefix`) that match the flow. The flow score is the best single flow.
2. If the flow score is 0 the title is not a candidate at all. DNS alone never
   marks anyone as playing (everyone has a launcher open).
3. **Client score**: add the weight of each `dns` rule with at least one
   matching glob (a rule counts once however many globs match).
4. Total = flow score + client score.

Confidence from `thresholds`: `high` >= 80, `medium` >= 50, `low` >= 30,
otherwise the client is `unknown` (the candidates are still listed).

TCP flows never score; they only contribute DNS names. UDP 53 (DNS), 67/68
(DHCP), 123 (NTP), 1900 (SSDP), 5353 (mDNS) and 3478-3481 (STUN) are dropped
when states are parsed, so rules on those ports never fire. UDP 443 (QUIC) is dropped
unless some `port`/`port_and_prefix` rule names 443. Only claim 443 if the
title really sends game traffic there; claiming it pulls every browser's
QUIC traffic into classification.

### Weights that reach each confidence

| Rule | Typical weight |
|---|---|
| `dns` | 50-60 |
| `port_and_prefix` | 30-40 |
| plain `port` (not prefix-scoped) | 20-25, i.e. below `low` |
| plain `port` on a title-unique port | 30-40 (shipped: Minecraft Bedrock 19132-19133 only) |
| `prefix` | 10-20 |
| `src_port` on a fixed client port | 40 (shipped: Source engine 27005 only) |

Rule: a flow-scope rule that is not scoped to a prefix set must weigh less
than `low` unless its port is unique to the title. Published game port
ranges are broad and shared with other software (UDP 5000-5500 covers
SIP/RTP 5060, 3074 is Xbox Live for many titles, 7000-9000 and 27000-27250
catch voice and other Steam games), so on their own they would put a wrong
title on the board. Below `low` they still make the title a candidate (the
client's `candidates[]` lists it, the destination shows on the Unclassified
tab) and they still unlock the title's DNS points.

| Evidence present | Example total | Confidence |
|---|---|---|
| dns 60 + port | 60 + 25 = 85 | high |
| dns 50 + port | 50 + 25 = 75 | medium |
| dns + port + prefix | 50 + 25 + 15 = 90 | high |
| dns + prefix only | 50 + 15 = 65 | medium |
| port_and_prefix (+ prefix) | 30 + 15 = 45 | low |
| port + prefix | 25 + 15 = 40 | low |
| title-unique port only | 35 | low |
| plain port only | 25 | unknown (candidate listed) |

Never use a plain `port` rule for a generic range (Roblox's 49152-65535 is
the OS ephemeral range). Even at a low weight it is a flow-scope hit for any
WebRTC, voice or P2P flow, and that hit lets the title's DNS points count:
a client with `roblox.com` in the cache plus a Discord call would score
60 + 20 = 80, high. Scope generic ranges to the publisher's network with
`port_and_prefix` instead, as the shipped Roblox entry does.

### Ambiguity

Candidates are sorted by score. If the runner-up is within 10 points of the
top and itself at least `low`:

- **Same publisher**: the client is shown as `<Publisher> (A or B)`, for
  example `Epic Games (Fortnite or Rocket League)`, with `game_id`
  `epic:ambiguous`. `high` is capped to `medium`.
- **Different publishers**: the client is `unknown`; both candidates are
  listed.

To keep sibling titles apart, give each a DNS glob only it resolves
(`*.psyonix.com` for Rocket League, not the shared `*.epicgames.com`) and
distinct port ranges where the publisher documents them. Shared globs (e.g.
a launcher domain on two titles) are only fine when ports really separate
the titles: DNS then adds the same points to both and the port decides.
When neither ports nor network separate them, use a publisher-generic title.

### Publisher-generic titles

Some evidence belongs to a publisher, not to one game: Valve's network and
its 27000-27250 range carry every Steam title; Riot's network, its ports
(LoL's game servers use 7000-8000 like Valorant) and its client/anti-cheat
DNS (`*.riotgames.com`, `*.riotcdn.net`, `*.pvp.net` for Vanguard,
`*.rgpub.io*`) are the same for LoL and Valorant. Putting that evidence on
each title makes the first title in the file win (a LoL player read as
Valorant, high), or gives an ambiguous label. Instead:

- A **generic title** (`steam` "Steam / Valve game", `riot` "Riot game")
  owns the shared flow rules at full weight (port 25 + prefix 15 = 40, low)
  and, for Riot, the shared DNS at a weak weight (20; total 60, medium).
- The **specific titles** keep the same flow rules at weight 5 each
  (flow-only 10, never labelled) so they stay candidates, plus a
  **game-specific `dns` rule at 60** (CS2 `*.counter-strike.net`, Dota 2
  `*.dota2.com`, LoL `*.leagueoflegends.com` / `lolstatic*.akamaihd.net*`,
  Valorant `*.playvalorant.com` / `*.valorant.*`). Riot titles also carry
  the generic Riot dns rule (20), so with their own name they score
  60 + 20 + 10 = 90 against the generic title's 60.

The result: shared evidence alone names the publisher's generic title;
a game-specific name the client is currently talking to names the game,
more than 10 points ahead. Pick specific globs from what the resolver
cache actually holds: CNAMEs are flattened onto the address, so LoL's
`lolstatic-a.akamaihd.net` shows up as `lolstatic-a.akamaihd.net.edgesuite.net`
too, and `lolstatic*.akamaihd.net*` matches both.

## Adding a title from the Unclassified tab

The Unclassified tab ranks destinations that clients without a match are
sending UDP to: `dst_ip`, `dst_port`, rDNS / DNS-cache names, and how many
clients. Suppose at 21:00 it shows:

| dst_ip | dst_port | names | clients |
|---|---|---|---|
| 34.89.12.201 | 7777 | `gs-uw1.prod.example-shooter.net` | 38 |
| 34.89.40.17 | 7779 | (none) | 21 |
| 104.18.7.9 | 7777 | (none) | 2 |

1. **Identify the title.** Expand a few of those clients on the main tab and
   look at their other destinations' names (TCP/443 shows launcher and API
   hosts, e.g. `api.example-shooter.net`). Ask a player at the seat if in
   doubt.
2. **Pick the DNS glob** from names the game resolves: `*.example-shooter.net`.
   Avoid globs that every PC resolves (`*.microsoft.com`, `*.steampowered.com`,
   `*.playfabapi.com`, CDNs).
3. **Pick the port range.** Several clients on 7777-7779 suggests the server
   range is near there; check the publisher's support page if there is one.
   Use a modest range (`"7777-7790"`) rather than one port, and not a range
   that overlaps another title unless DNS separates them.
4. **Prefix only if the publisher owns the network.** 34.89.x.x is Google
   Cloud; do not add it as a prefix set, it would match every GCP-hosted
   game. Use a prefix set only for a publisher's own ASN (look it up with
   `whois` or `https://stat.ripe.net/data/announced-prefixes/data.json?resource=ASnnnn`).
5. **Write the rule** and add a `_sources` entry:

```json
{"id": "example_shooter", "name": "Example Shooter", "publisher": "example", "rules": [
  {"type": "dns",  "match": ["*.example-shooter.net"], "weight": 60},
  {"type": "port", "dst_ports": ["7777-7790"], "weight": 25}
]}
```

6. **Save, watch the page.** Within one poll the warning area should stay
   clear and those 59 clients should move to `Example Shooter` (`high` where
   DNS evidence exists). Clients without DNS evidence stay `unknown` with
   Example Shooter in their candidates: 7777 is a common game-server port,
   so a plain port rule stays below `low`.

If a new rule steals clients from an existing title, the ambiguity rule will
usually show it as `unknown` or `<Publisher> (A or B)`; narrow the port range
or make the DNS glob more specific.

## Known limitations

- **Port-only clients are not labelled.** Every shipped plain `port` rule
  except Minecraft Bedrock weighs 25, below `low`, so a client whose resolver
  traffic is invisible (DoH, hard-coded DNS) and who plays on cloud-hosted
  servers (Fortnite, Rocket League, Call of Duty, Apex, Battlefield) shows as
  `unknown` with the title in `candidates[]`. Titles with a prefix set
  (Overwatch 2, WoW, Roblox, and the generic Riot game) still reach `low`
  on the publisher's network via `port` + `prefix` or `port_and_prefix`.
- **LoL vs Valorant can only be told apart while the client holds a
  connection to a game-specific host; otherwise it shows Riot game.** DNS
  evidence is client-scope: a name counts only while the client has a UDP or
  TCP/443 flow to an address behind it. Riot game traffic (riot prefix,
  7000-8000) plus the Riot client and Vanguard names is "Riot game"
  (medium), without DNS "Riot game" (low).
- **Valve network traffic does not name a Valve title.** Every Steam game
  that uses Steam Datagram Relay sends UDP 27000-27250 into the Valve
  network, so those flow rules belong to the generic title `steam`
  ("Steam / Valve game", port 25 + prefix 15 = 40, low). CS2 and Dota 2
  carry the same rules at weight 5 each (flow-only 10, never labelled) and
  are named only when their DNS matches (60 + 10 = 70, medium; > 10 ahead
  of `source_engine`'s 55 when the client also binds 27005). Without
  that DNS a CS2 or Dota 2 player shows as "Steam / Valve game".
- **"Source engine game" cannot distinguish TF2/GMod/CS:S/L4D2.** Community
  servers have no DNS names (bare IPs on rented hosts), so the only evidence
  is the Source client port: `src_port` 27005 (40, low), plus 15 when the
  server or Valve relay is on 27015-27150 (55, medium). A client that
  changed `clientport`, or a non-Source game that happens to bind 27005,
  is misread, as is a random local port 27005 behind a second NAT. Through
  a Valve relay on 27015-27150 a Source client scores 55 against `steam` 40,
  so it stays "Source engine game".
- **Marvel Rivals is not detectable from flow rules alone.** NetEase
  publishes no ports and its own network (AS45062) is mainland China only, so
  the entry has a placeholder `port 9000-9999` at weight 10. That is below
  `low`, so the title only shows when its DNS also matches (60 + 10 = 70,
  medium). Clients that bypass the resolver show as `unknown`. Raise the
  weight once the real range is seen on the Unclassified tab.
- **World of Warcraft gameplay is TCP** (1119/3724). Only its UDP (voice,
  UDP 3724) can be classified, so most WoW players are not detected.
- **Some DNS globs are unverified guesses** about what the client resolves
  during play: Overwatch 2, WoW, CS2 (`*.counter-strike.net`), Dota 2
  (`*.dota2.com`), Apex, Battlefield and Marvel Rivals (`*.easebar.com`).
  Without them these titles fall back to flow-only confidence; CS2 and
  Dota 2 fall back to "Steam / Valve game".

## The shipped defaults

`signatures.default.json` covers Fortnite, Rocket League, Valorant, League of
Legends, Overwatch 2, World of Warcraft, Call of Duty, Apex Legends,
Battlefield, Counter-Strike 2, Dota 2, Roblox, Minecraft Bedrock and Marvel
Rivals, plus generic titles: "Steam / Valve game" (`steam`, Valve network
traffic without CS2/Dota 2 DNS), "Riot game" (`riot`, Riot network and
client traffic without a LoL/Valorant-specific name) and "Source engine game" (`source_engine`,
publisher `source`, client port 27005: TF2, GMod, CS:S, L4D2 and other
Source games on community servers). Its `_sources` key lists where each port range and prefix set came
from and when it was checked; `_notes` lists the entries built on weak or
secondhand data, which are the first ones to tune on a live network.
