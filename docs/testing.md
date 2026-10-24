# Acceptance testing

A checklist for checking a pfSense-pkg-gamedetect install on your own
firewall, plus the numbers measured on real hardware. Run the shell commands
from Diagnostics > Command Prompt or an SSH / console shell (option 8).

For the unit tests and static checks that run in CI, see
[Building from source](../README.md#building-from-source-and-running-tests).

## Checklist

### Install

- [ ] `pkg add <package URL or file>` finishes without errors and prints the
  pfSense package steps (install command, resync, menu items, services) as
  done.
- [ ] Services > Game Detect (settings) and Status > Game Detect (status)
  appear in the menus.
- [ ] Status > Services lists `gamedetectd` ("Game Detect classifier") as
  running, and the page renders without a PHP error.
- [ ] The daemon is running:

  ```sh
  pgrep -F /var/run/gamedetectd.pid && echo running
  ```

- [ ] `/usr/local/etc/gamedetect/signatures.json` exists (seeded from the
  shipped defaults) and `/usr/local/etc/gamedetect/gamedetectd.conf` lists
  your attendee network(s) under `attendee_cidrs`.

### JSON written

- [ ] Within one poll interval (60 s by default) the data files exist:

  ```sh
  ls -l /var/db/gamedetect/
  cat /var/db/gamedetect/daemon.json
  ```

  Expect `clients.json`, `unknown.json`, `daemon.json` and
  `signatures.lastgood.json`; `daemon.json` shows `"state": "running"`, a
  recent `last_poll_at`, and `"warnings": []`.

- [ ] `https://<firewall>/status_gamedetect.php?refresh=clients` (logged in)
  returns JSON with `clients`, `poll_interval`, `generated_at` and
  `banner` only (no `daemon`, no per-client `candidates`/`evidence`), and
  `?format=json` just shows the page.

### Settings save and reload

- [ ] Change Poll interval to 5 on Services > Game Detect and save. The
  system log shows `gamedetectd: SIGHUP; reloading conf and signatures`, the
  daemon pid is unchanged, and `daemon.json` shows `"effective_interval": 5`.
- [ ] Out-of-range values (Poll interval 1, Idle timeout 5000) are rejected
  with an error and nothing is saved.

### Signatures: edit, break, restore

- [ ] Edit `/usr/local/etc/gamedetect/signatures.json` (Diagnostics > Edit
  File), for example add a title with a `dns` rule on `*.example.invalid`,
  and save. On the next poll `signatures.lastgood.json` contains it, and the
  Signatures tab shows **Loaded** plus the "differs from the defaults" box
  with **Reset to defaults**.
- [ ] Replace the file with `{` and save. On the next poll the status page
  banner reads "The signature file is invalid; the previous signatures are
  still in use: root: not a JSON object", the system log has one line for
  it, the client table keeps updating, and the daemon pid is unchanged.
- [ ] Click **Reset to defaults** on the Signatures tab. A
  `signatures.json.<UTC timestamp>.bak` appears next to the live file, the
  banner clears on the next poll, and the info box disappears.

### Classification

- [ ] Start a game covered by the shipped signatures on a LAN device. Within
  one poll the device appears on the Clients tab with the game and a
  confidence of `low`, `medium` or `high`.
- [ ] Quit the game. The row stays (with `Flows` 0) and disappears after the
  idle timeout (600 s by default).
- [ ] Devices with non-game UDP (for example WebRTC calls) appear as
  `unknown` and their destinations are listed on the Unclassified tab; no
  game is named for them.

### Upgrade

- [ ] Note the daemon pid, then `pkg add` a newer package. Afterwards the
  daemon runs with a new pid (the new code is running), settings are
  unchanged, and `/var/db/gamedetect` still has `clients.json` (first-seen
  times survive).
- [ ] If the live `signatures.json` was an unedited earlier default, it was
  replaced by the new default and a `.bak` of the old one was kept. If you
  had edited it, it is untouched.

### Uninstall

- [ ] `pkg delete pfSense-pkg-gamedetect` removes the menus, the
  `gamedetectd` entry on Status > Services, the daemon, the rc script and
  `/var/db/gamedetect`, and unregisters the package from the pfSense
  config (`pkg info pfSense-pkg-gamedetect` reports it is not installed).
- [ ] `/usr/local/etc/gamedetect/signatures.json` is kept.
- [ ] Reinstalling reuses the kept `signatures.json` and registers the
  package once (no duplicate menu or service entries).

### Input validation

- [ ] Enter `10.0.0.0/8; id` (also try `10.0.0.0/8$(id)`) in Additional
  attendee networks and save. The form shows "Attendee network ... is
  neither a configured interface nor an IPv4 CIDR." and
  `gamedetectd.conf` is unchanged. A plain `10.0.0.0/8` saves.

## Measured results

Hardware: Intel Xeon D-2123IT (4 cores / 8 threads), pfSense CE 2.9.0.

| Measurement | Result |
|---|---|
| Real poll (state table + classification), ~500 state lines after the prefilter | 16-18 ms |
| Poll that includes an Unbound cache dump (every 30 s by default; ~26k-line cache) | ~300 ms |
| Synthetic 300k-line `pfctl -ss` parse (prefilter + PHP), before the v0.1.1 classifier precompile | 3.7 s |
| `gd_aggregate` with 700 clients x 40 flows (Apple silicon, test suite) | 4.5 s before the precompile, 0.33 s after |

A slow poll does not hurt forwarding: the daemon doubles its interval when a
poll takes more than half of it (see [architecture](architecture.md#daemon-behaviour)).

## Validated live

| Game | Result |
|---|---|
| Fortnite | `fortnite`, high: game server UDP port 9045 plus Epic DNS names |
| Team Fortress 2 on a community server | "Source engine game" (client port 27005) |
| League of Legends | "Riot game" from shared Riot evidence; League of Legends once the client holds a connection to a LoL-specific host |
