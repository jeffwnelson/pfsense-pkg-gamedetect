<?php
/*
 * Copyright 2026 Jeff Nelson
 * Licensed under the Apache License, Version 2.0.
 *
 * gamedetectd: poll the pf state table, classify attendee clients, and write
 * /var/db/gamedetect/{clients,unknown,daemon}.json.
 *
 * Usage: php -f gamedetectd.php [-- --conf <path>] [--once]
 *
 * Every external command goes through collect.sh (argv array, no shell).
 * SIGHUP reloads the conf and signatures and clears a pause; SIGTERM exits 0.
 */
require_once(__DIR__ . '/../../pkg/gamedetect_lib.inc');

const GD_CONF_DEFAULT = '/usr/local/etc/gamedetect/gamedetectd.conf';
const GD_IDENTITY_REFRESH_S = 30;
const GD_MAX_INTERVAL_S = 60;
const GD_PAUSE_POLL_S = 60;   /* default pause threshold; conf pause_threshold_s (tests only) overrides */
const GD_RDNS_TTL_S = 3600;
const GD_RDNS_MAX_PER_POLL = 20;
const GD_RDNS_BUDGET_S = 1.0;
const GD_NO_NETWORKS = 'no_attendee_networks';

function gdd_log(string $msg): void {
	syslog(LOG_NOTICE, $msg);
}

/* Integer conf value clamped to [min, max]; $def when absent or not an integer. */
function gdd_int($v, int $min, int $max, int $def): int {
	if (!is_int($v) && !(is_string($v) && ctype_digit($v))) {
		return $def;
	}
	return max($min, min($max, (int)$v));
}

/*
 * Load and normalise the conf; null when missing or not JSON. attendee_cidrs may be empty.
 * pause_threshold_s is a hidden key (not on the settings page) so tests can trip the pause.
 */
function gdd_load_conf(string $path): ?array {
	$c = gd_read_json($path);
	if ($c === null) {
		return null;
	}
	$cidrs = array_values(array_filter((array)($c['attendee_cidrs'] ?? []), 'is_string'));
	$leases = $c['leases_cmd'] ?? null;
	return [
	    'attendee_cidrs' => $cidrs,
	    'local_ips' => array_values(array_filter((array)($c['local_ips'] ?? []), 'is_string')),
	    'poll_interval' => gdd_int($c['poll_interval'] ?? null, 2, 60, 60),
	    'idle_timeout' => gdd_int($c['idle_timeout'] ?? null, 30, 900, 600),
	    'dns_refresh' => gdd_int($c['dns_refresh'] ?? null, 10, 300, 30),
	    'unknown_list_size' => gdd_int($c['unknown_list_size'] ?? null, 10, 500, 50),
	    'rdns_enabled' => (bool)($c['rdns_enabled'] ?? true),
	    'data_dir' => (string)($c['data_dir'] ?? '/var/db/gamedetect'),
	    'signatures_path' => (string)($c['signatures_path'] ?? '/usr/local/etc/gamedetect/signatures.json'),
	    'default_signatures_path' => (string)($c['default_signatures_path'] ?? '/usr/local/share/pfSense-pkg-gamedetect/signatures.default.json'),
	    'leases_cmd' => is_string($leases) && $leases !== '' ? $leases : null,
	    'pause_threshold_s' => gdd_int($c['pause_threshold_s'] ?? null, 1, 3600, GD_PAUSE_POLL_S),
	];
}

/* Run argv without a shell; ['rc' => int, 'out' => string]. */
function gdd_run(array $argv): array {
	$p = @proc_open($argv, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
	if (!is_resource($p)) {
		return ['rc' => -1, 'out' => ''];
	}
	$out = stream_get_contents($pipes[1]);
	fclose($pipes[1]);
	return ['rc' => proc_close($p), 'out' => $out === false ? '' : $out];
}

function gdd_collect(string ...$args): array {
	return gdd_run(array_merge(['/bin/sh', __DIR__ . '/collect.sh'], $args));
}

class GameDetectDaemon {
	public array $conf;
	private string $conf_path;
	private ?array $sigs = null;
	private ?string $sigs_key = null;   /* "mtime:size" of the last load attempt */
	private ?string $sig_warning = null;
	private array $dns_map = [];
	private ?int $dns_at = null;
	private bool $dns_failed = false;
	private array $identity = ['mac' => [], 'hostname' => []];
	private ?int $ident_at = null;
	private bool $arp_failed = false;
	private bool $leases_failed = false;
	private array $prev = [];
	private array $rdns_cache = [];   /* ip => ['name' => ?string, 'at' => int] */
	private int $started_at;
	private int $last_poll_at = 0;
	private int $last_poll_ms = 0;
	private int $state_lines = 0;
	private bool $states_failed = false;
	public int $effective;
	public ?string $paused = null;
	public bool $hup = false;
	public bool $term = false;

	public function __construct(string $conf_path, array $conf) {
		$this->conf_path = $conf_path;
		$this->conf = $conf;
		$this->effective = $conf['poll_interval'];
		$this->started_at = time();
		$this->restore_prev();
		$this->reload_signatures();
	}

	private function path(string $name): string {
		return $this->conf['data_dir'] . '/' . $name;
	}

	/* Rebuild $prev from clients.json so first_seen survives restarts. */
	private function restore_prev(): void {
		$c = gd_read_json($this->path('clients.json'));
		$clients = is_array($c['clients'] ?? null) ? array_values(array_filter($c['clients'], function ($r) {
			return is_array($r) && is_string($r['ip'] ?? null) && is_string($r['first_seen'] ?? null) && is_string($r['last_seen'] ?? null);
		})) : [];
		$this->prev = ['clients' => $clients, 'unknown' => [], 'pending' => []];
	}

	public function has_networks(): bool {
		return $this->conf['attendee_cidrs'] !== [];
	}

	/* SIGHUP: re-read the conf (keep the old one if unusable), signatures, refresh everything. */
	public function reload(bool $quiet = false): void {
		$c = gdd_load_conf($this->conf_path);
		if ($c === null) {
			if (!$quiet) {
				gdd_log("conf {$this->conf_path} unusable on reload; keeping the previous settings");
			}
		} else {
			$this->conf = $c;
		}
		$this->effective = $this->conf['poll_interval'];
		$this->paused = null;
		$this->sigs_key = null;
		$this->dns_at = null;
		$this->ident_at = null;
		$this->reload_signatures();
	}

	/*
	 * Load signatures when the live file's mtime or size changed. On failure keep the
	 * current set and the warning until a later load succeeds. A fresh process
	 * with a broken live file falls back to the last good copy, then defaults.
	 */
	private function reload_signatures(): void {
		$live = $this->conf['signatures_path'];
		clearstatcache(true, $live);
		$st = @stat($live);
		$key = $st === false ? 'missing' : $st['mtime'] . ':' . $st['size'];
		if ($this->sigs_key === $key) {
			return;
		}
		$this->sigs_key = $key;
		$json = @file_get_contents($live);
		$r = $json === false ? ['ok' => false, 'error' => 'cannot read ' . $live] : gd_load_signatures($json);
		if ($r['ok']) {
			$this->sigs = $r['sigs'];
			$this->sig_warning = null;
			$good = $this->path('signatures.lastgood.json');
			if (@file_get_contents($good) !== $json) {
				if (@file_put_contents($good . '.tmp', $json) === false || !@rename($good . '.tmp', $good)) {
					@unlink($good . '.tmp');
				}
			}
			return;
		}
		$this->sig_warning = 'signatures_invalid: ' . $r['error'];
		gdd_log("{$live}: {$r['error']}; keeping the previous signatures");
		if ($this->sigs !== null) {
			return;
		}
		foreach ([$this->path('signatures.lastgood.json'), $this->conf['default_signatures_path']] as $fallback) {
			$j = @file_get_contents($fallback);
			$f = $j === false ? ['ok' => false] : gd_load_signatures($j);
			if ($f['ok']) {
				$this->sigs = $f['sigs'];
				return;
			}
		}
	}

	private function refresh_dns(int $now): void {
		if ($this->dns_at !== null && $now - $this->dns_at < $this->conf['dns_refresh']) {
			return;
		}
		$this->dns_at = $now;
		$r = gdd_collect('dns');
		$this->dns_failed = $r['rc'] !== 0;
		$fresh = $this->dns_failed ? [] : gd_parse_unbound_cache($r['out'], $now);
		$this->dns_map = gd_merge_dns_maps($this->dns_map, $fresh, $now);
	}

	private function refresh_identity(int $now): void {
		if ($this->ident_at !== null && $now - $this->ident_at < GD_IDENTITY_REFRESH_S) {
			return;
		}
		$this->ident_at = $now;
		$r = gdd_collect('arp');
		$this->arp_failed = $r['rc'] !== 0;
		if (!$this->arp_failed) {
			$this->identity['mac'] = gd_parse_arp($r['out']);
		}
		$this->leases_failed = false;
		$cmd = $this->conf['leases_cmd'];
		if ($cmd !== null) {
			$r = gdd_run([PHP_BINARY, '-f', $cmd]);
			$d = $r['rc'] === 0 ? json_decode($r['out'], true) : null;
			if (is_array($d)) {
				$names = [];
				foreach ($d as $ip => $name) {
					if (is_string($name) && $name !== '' && filter_var((string)$ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
						$names[(string)$ip] = $name;
					}
				}
				$this->identity['hostname'] = $names;
			} else {
				$this->leases_failed = true;
			}
		}
	}

	/*
	 * Append cached/looked-up PTR names to unknown destinations. At most
	 * GD_RDNS_MAX_PER_POLL lookups and GD_RDNS_BUDGET_S of wall-clock per
	 * poll; destinations skipped here are looked up on later polls.
	 */
	private function apply_rdns(array $unknown, int $now): array {
		if (!$this->conf['rdns_enabled']) {
			return $unknown;
		}
		$lookups = 0;
		$deadline = microtime(true) + GD_RDNS_BUDGET_S;
		foreach ($unknown as $i => $u) {
			$ip = $u['ip'];
			$hit = $this->rdns_cache[$ip] ?? null;
			if ($hit === null || $now - $hit['at'] >= GD_RDNS_TTL_S) {
				if ($lookups >= GD_RDNS_MAX_PER_POLL || microtime(true) >= $deadline
				    || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
					continue;
				}
				$lookups++;
				$r = gdd_collect('rdns', $ip);
				$name = null;
				if ($r['rc'] === 0 && preg_match('/domain name pointer (\S+)/', $r['out'], $m)) {
					$name = strtolower(rtrim($m[1], '.'));
				}
				$hit = $this->rdns_cache[$ip] = ['name' => $name, 'at' => $now];
			}
			if ($hit['name'] !== null && !in_array($hit['name'], $u['names'], true)) {
				$unknown[$i]['names'][] = $hit['name'];
			}
		}
		foreach ($this->rdns_cache as $ip => $hit) {
			if ($now - $hit['at'] >= GD_RDNS_TTL_S) {
				unset($this->rdns_cache[$ip]);
			}
		}
		return $unknown;
	}

	private function warnings(bool $states_failed): array {
		$w = [];
		if ($states_failed) {
			$w[] = 'states_unavailable';
		}
		if ($this->dns_failed) {
			$w[] = 'dns_cache_unavailable';
		}
		if ($this->sig_warning !== null) {
			$w[] = $this->sig_warning;
		}
		if ($this->arp_failed) {
			$w[] = 'arp_unavailable';
		}
		if ($this->leases_failed) {
			$w[] = 'leases_unavailable';
		}
		return $w;
	}

	/*
	 * One poll; returns its total wall-clock in milliseconds. The backoff
	 * measurement (last_poll_ms) covers the state table, DNS refresh, and
	 * classification only: identity refresh (ARP + leases, every 30 s) and the
	 * bounded rDNS enrichment of unknown.json, which runs after clients.json is
	 * written, are excluded. A failed state read or no signatures at all keeps
	 * the previous tables (and $prev) untouched.
	 */
	public function poll(): int {
		$t0 = hrtime(true);
		$now = time();
		$this->reload_signatures();
		$s = gdd_collect('states');
		$states_failed = $s['rc'] !== 0;
		$lines = $s['out'] === '' ? 0 : substr_count($s['out'], "\n");
		$this->refresh_dns($now);
		$t_ident = hrtime(true);
		$this->refresh_identity($now);
		$ident_ns = hrtime(true) - $t_ident;
		$iso = date(DATE_ATOM, $now);

		$agg = null;
		if ($states_failed) {
			gdd_log("pfctl -ss or prefilter failed (exit {$s['rc']}); keeping the previous client table");
		} elseif ($this->sigs === null) {
			gdd_log('no usable signatures; keeping the previous client table');
		} else {
			$flows = gd_parse_states($s['out'], $this->conf['attendee_cidrs'], $this->conf['local_ips']);
			$agg = gd_aggregate($flows, $this->dns_map, $this->identity, $this->sigs, $this->prev, $now, [
			    'idle_timeout' => $this->conf['idle_timeout'],
			    'unknown_list_size' => $this->conf['unknown_list_size'],
			]);
			$this->prev = $agg;
			$this->write('clients.json', ['generated_at' => $iso, 'poll_interval' => $this->conf['poll_interval'], 'clients' => $agg['clients']]);
		}
		$ms = intdiv(max(0, hrtime(true) - $t0 - $ident_ns), 1000000);
		$this->backoff($ms);
		if ($agg !== null) {
			$this->write('unknown.json', ['generated_at' => $iso, 'unknown' => $this->apply_rdns($agg['unknown'], $now)]);
		}
		$this->write_status($now, $ms, $lines, $states_failed);
		return intdiv(hrtime(true) - $t0, 1000000);
	}

	/* Double on slow polls (max 60 s), pause past pause_threshold_s (60 s), reset on fast polls. */
	private function backoff(int $ms): void {
		$configured = $this->conf['poll_interval'];
		$limit = $this->conf['pause_threshold_s'];
		if ($ms > $limit * 1000) {
			$this->paused = sprintf('poll took %.1f s (limit %d s); send SIGHUP or restart to resume', $ms / 1000, $limit);
			gdd_log('paused: ' . $this->paused);
		} elseif ($ms * 2 > $this->effective * 1000) {
			$next = min(GD_MAX_INTERVAL_S, $this->effective * 2);
			if ($next !== $this->effective) {
				gdd_log(sprintf('poll took %d ms; interval %d s -> %d s', $ms, $this->effective, $next));
			}
			$this->effective = $next;
		} elseif ($ms * 4 < $configured * 1000 && $this->effective !== $configured) {
			gdd_log("polls fast again; interval back to {$configured} s");
			$this->effective = $configured;
		}
	}

	public function write_status(?int $now = null, ?int $ms = null, ?int $lines = null, ?bool $states_failed = null): void {
		$this->last_poll_at = $now ?? $this->last_poll_at;
		$this->last_poll_ms = $ms ?? $this->last_poll_ms;
		$this->state_lines = $lines ?? $this->state_lines;
		$this->states_failed = $states_failed ?? $this->states_failed;
		$paused = $this->has_networks() ? $this->paused : GD_NO_NETWORKS;
		$d = [
		    'state' => $paused === null ? 'running' : 'paused',
		    'pid' => getmypid(),
		    'started_at' => date(DATE_ATOM, $this->started_at),
		    'last_poll_at' => $this->last_poll_at ? date(DATE_ATOM, $this->last_poll_at) : null,
		    'last_poll_ms' => $this->last_poll_ms,
		    'effective_interval' => $this->effective,
		    'state_lines' => $this->state_lines,
		    'warnings' => $this->warnings($this->states_failed),
		];
		if ($paused !== null) {
			$d['reason'] = $paused;
		}
		$this->write('daemon.json', $d);
	}

	private function write(string $name, array $data): void {
		if (!gd_write_json_atomic($this->path($name), $data)) {
			gdd_log('cannot write ' . $this->path($name));
		}
	}
}

/* Sleep up to $seconds, waking early for signals. */
function gdd_sleep(GameDetectDaemon $d, float $seconds): void {
	$until = microtime(true) + $seconds;
	while (!$d->term && !$d->hup && ($left = $until - microtime(true)) > 0) {
		usleep((int)(min(1.0, $left) * 1000000));
		if (function_exists('pcntl_signal_dispatch')) {
			pcntl_signal_dispatch();
		}
	}
}

function gdd_main(array $argv): int {
	$conf_path = GD_CONF_DEFAULT;
	$once = false;
	for ($i = 1; $i < count($argv); $i++) {
		if ($argv[$i] === '--once') {
			$once = true;
		} elseif ($argv[$i] === '--conf' && isset($argv[$i + 1])) {
			$conf_path = $argv[++$i];
		} elseif ($argv[$i] !== '--') {
			fwrite(STDERR, "usage: gamedetectd.php [--conf <path>] [--once]\n");
			return 2;
		}
	}
	openlog('gamedetectd', LOG_PID, LOG_DAEMON);
	$conf = gdd_load_conf($conf_path);
	if ($conf === null) {
		gdd_log("conf {$conf_path} missing or not JSON; exiting");
		fwrite(STDERR, "gamedetectd: unusable conf {$conf_path}\n");
		return 1;
	}
	if (!is_dir($conf['data_dir']) && !@mkdir($conf['data_dir'], 0755, true)) {
		fwrite(STDERR, "gamedetectd: cannot create {$conf['data_dir']}\n");
		return 1;
	}
	$d = new GameDetectDaemon($conf_path, $conf);
	if (!$d->has_networks()) {
		gdd_log('no attendee networks configured; waiting for a conf change');
	}
	if ($once) {
		$d->has_networks() ? $d->poll() : $d->write_status();
		return 0;
	}
	if (function_exists('pcntl_signal')) {
		pcntl_signal(SIGHUP, function () use ($d) {
			$d->hup = true;
		});
		pcntl_signal(SIGTERM, function () use ($d) {
			$d->term = true;
		});
		pcntl_signal(SIGINT, function () use ($d) {
			$d->term = true;
		});
	}
	gdd_log('started');
	while (true) {
		if (function_exists('pcntl_signal_dispatch')) {
			pcntl_signal_dispatch();
		}
		if ($d->term) {
			gdd_log('SIGTERM; exiting');
			return 0;
		}
		if ($d->hup) {
			$d->hup = false;
			gdd_log('SIGHUP; reloading conf and signatures');
			$d->reload();
		}
		if (!$d->has_networks()) {
			/* Paused, not exiting: daemon(8) -r would restart us every 5 s. */
			$d->write_status();
			gdd_sleep($d, 5);
			if (!$d->hup && !$d->term) {
				$d->reload(true);
			}
			continue;
		}
		if ($d->paused !== null) {
			$d->write_status();
			gdd_sleep($d, 5);
			continue;
		}
		$ms = $d->poll();
		if ($d->paused === null) {
			gdd_sleep($d, max(0.0, $d->effective - $ms / 1000));
		}
	}
}

exit(gdd_main($argv));
