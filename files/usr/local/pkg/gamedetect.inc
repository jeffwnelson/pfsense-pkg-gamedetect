<?php
/*
 * Copyright 2026 Jeff Nelson
 * Licensed under the Apache License, Version 2.0.
 *
 * pfSense glue for pfSense-pkg-gamedetect: settings validation, the daemon
 * conf and rc script, start/reload/stop, and the self-updater wiring (daily
 * check cron job, Update now / Check now). Pure logic lives in
 * gamedetect_lib.inc and gamedetect_update_lib.inc; the daemon is
 * libexec/gamedetect/gamedetectd.php.
 */
require_once('config.inc');
require_once('interfaces.inc');
require_once('service-utils.inc');
require_once('services.inc');
require_once('util.inc');
require_once(__DIR__ . '/gamedetect_lib.inc');
require_once(__DIR__ . '/gamedetect_update_lib.inc');

const GAMEDETECT_CFG = 'installedpackages/gamedetect/config/0';
const GAMEDETECT_RCFILE = '/usr/local/etc/rc.d/gamedetectd.sh';
const GAMEDETECT_PID = '/var/run/gamedetectd.pid';
const GAMEDETECT_DAEMON_CMD = '/usr/sbin/daemon -f -p ' . GAMEDETECT_PID
    . ' -r -R 5 /usr/local/bin/php -f /usr/local/libexec/gamedetect/gamedetectd.php';
const GAMEDETECT_LEASES_CMD = '/usr/local/libexec/gamedetect/leases.php';

/*
 * Self-updater. Releases are looked up in this GitHub repo; a fork changes it
 * here (it is deliberately not a setting). Commands are fixed strings/argv.
 */
const GAMEDETECT_UPDATE_REPO = 'jeffwnelson/pfsense-pkg-gamedetect';
const GAMEDETECT_UPDATE_CHECK_ARGV = ['/usr/local/bin/php', '-f', '/usr/local/libexec/gamedetect/update-check.php'];
const GAMEDETECT_UPDATE_CHECK_CMD = '/usr/local/bin/php -f /usr/local/libexec/gamedetect/update-check.php';
const GAMEDETECT_UPDATE_RUN_CMD = '/usr/sbin/daemon -f /usr/local/bin/php -f /usr/local/libexec/gamedetect/update-run.php';
const GAMEDETECT_PKG_BIN = '/usr/local/sbin/pkg';
const GAMEDETECT_UPDATE_ENV = ['PATH' => '/sbin:/bin:/usr/sbin:/usr/bin:/usr/local/sbin:/usr/local/bin', 'HOME' => '/root', 'LANG' => 'C'];
const GAMEDETECT_UPDATE_CHECK_TIMEOUT_S = 15;  /* Check now (synchronous, in the web request) */
const GAMEDETECT_UPDATE_HTTP_TIMEOUT_S = 10;   /* releases/latest and SHA256SUMS */
const GAMEDETECT_UPDATE_DL_TIMEOUT_S = 120;    /* the package download */
const GAMEDETECT_UPDATE_PKG_TIMEOUT_S = 900;   /* pkg add (runs the package's install scripts) */

/* field => [min, max, default] (Global Constraints). */
const GAMEDETECT_RANGES = [
    'poll_interval' => [2, 60, 60],
    'idle_timeout' => [30, 900, 600],
    'dns_refresh' => [10, 300, 30],
    'unknown_list_size' => [10, 500, 50],
];
const GAMEDETECT_LABELS = [
    'poll_interval' => 'Poll interval',
    'idle_timeout' => 'Idle timeout',
    'dns_refresh' => 'DNS cache refresh',
    'unknown_list_size' => 'Unclassified list size',
];

/*
 * Paths can be redirected by tests through $GLOBALS['gamedetect_test_paths']
 * (keys conf, signatures, default_signatures, data_dir, pid, update_lock,
 * info_xml, tmp_dir); production never sets it.
 */
function gamedetect_path(string $key): string {
	$defaults = [
	    'conf' => '/usr/local/etc/gamedetect/gamedetectd.conf',
	    'signatures' => '/usr/local/etc/gamedetect/signatures.json',
	    'default_signatures' => '/usr/local/share/pfSense-pkg-gamedetect/signatures.default.json',
	    'data_dir' => '/var/db/gamedetect',
	    'pid' => GAMEDETECT_PID,
	    'update_lock' => '/var/run/gamedetect-update.lock',
	    'info_xml' => '/usr/local/share/pfSense-pkg-gamedetect/info.xml',
	    'tmp_dir' => '/tmp',
	];
	return (string)($GLOBALS['gamedetect_test_paths'][$key] ?? $defaults[$key]);
}

/* IPv4 CIDR syntax check plus pfSense's is_subnet(). */
function gamedetect_is_cidr(string $s): bool {
	return preg_match('#^(\d{1,3}\.){3}\d{1,3}/\d{1,2}$#', $s) === 1 && is_subnet($s) === 4;
}

/*
 * Network entries from a form post or saved config: interface list + free-text
 * CIDRs. Non-string values (e.g. nested arrays in a crafted POST) are skipped
 * and counted in $invalid.
 */
function gamedetect_network_entries(array $src, int &$invalid = 0): array {
	$invalid = 0;
	$ifs = $src['attendee_networks'] ?? [];
	$ifs = is_array($ifs) ? $ifs : (is_string($ifs) ? explode(',', $ifs) : [$ifs]);
	$extra = $src['extra_cidrs'] ?? '';
	if (!is_string($extra)) {
		$invalid++;
		$extra = '';
	}
	$out = [];
	foreach (array_merge($ifs, preg_split('/[\s,]+/', $extra, -1, PREG_SPLIT_NO_EMPTY)) as $e) {
		if (!is_string($e)) {
			$invalid++;
			continue;
		}
		$e = trim($e);
		if ($e !== '') {
			$out[] = $e;
		}
	}
	return array_values(array_unique($out));
}

/*
 * Interface names -> their IPv4 network CIDR; CIDR strings validated and
 * passed through. A configured interface without an IPv4 address yields
 * nothing. Anything else throws InvalidArgumentException.
 */
function gamedetect_resolve_networks(array $entries): array {
	$ifaces = get_configured_interface_list();
	$out = [];
	foreach ($entries as $e) {
		$e = trim((string)$e);
		if ($e !== '' && isset($ifaces[$e])) {
			$ip = get_interface_ip($e);
			$bits = get_interface_subnet($e);
			if (!is_string($ip) || !is_ipaddrv4($ip) || !is_numeric($bits) || (int)$bits < 0 || (int)$bits > 32) {
				continue;
			}
			$mask = (int)$bits === 0 ? 0 : (0xFFFFFFFF << (32 - (int)$bits)) & 0xFFFFFFFF;
			$out[] = long2ip(ip2long($ip) & $mask) . '/' . (int)$bits;
		} elseif (gamedetect_is_cidr($e)) {
			$out[] = $e;
		} else {
			throw new InvalidArgumentException("not a configured interface or IPv4 CIDR: {$e}");
		}
	}
	return array_values(array_unique($out));
}

/* The box's own IPv4 addresses (states to them are ignored). */
function gamedetect_local_ips(): array {
	$out = [];
	foreach (array_keys(get_configured_interface_list()) as $if) {
		$ip = get_interface_ip($if);
		if (is_string($ip) && is_ipaddrv4($ip)) {
			$out[] = $ip;
		}
	}
	return array_values(array_unique($out));
}

/*
 * Saved settings; a never-saved package uses every default (enabled, rDNS on,
 * lan, update check on). update_check is also on for a config saved before
 * the field existed (key absent); pkg_edit.php stores an unchecked box as ''.
 */
function gamedetect_settings(): array {
	$cfg = config_get_path(GAMEDETECT_CFG);
	$saved = is_array($cfg);
	$cfg = $saved ? $cfg : ['attendee_networks' => 'lan'];
	$s = [
	    'enable' => $saved ? !empty($cfg['enable']) : true,
	    'rdns_enabled' => $saved ? !empty($cfg['rdns_enabled']) : true,
	    'update_check' => array_key_exists('update_check', $cfg) ? !empty($cfg['update_check']) : true,
	    'networks' => gamedetect_network_entries($cfg),
	];
	foreach (GAMEDETECT_RANGES as $f => [$min, $max, $def]) {
		$v = (string)($cfg[$f] ?? '');
		$s[$f] = ctype_digit($v) ? max($min, min($max, (int)$v)) : $def;
	}
	return $s;
}

/* Build the daemon conf array from the saved settings; bad stored networks are logged and dropped. */
function gamedetect_build_conf(): array {
	$s = gamedetect_settings();
	$cidrs = [];
	foreach ($s['networks'] as $e) {
		try {
			$cidrs = array_merge($cidrs, gamedetect_resolve_networks([$e]));
		} catch (InvalidArgumentException $ex) {
			log_error('gamedetect: ignoring attendee network: ' . $ex->getMessage());
		}
	}
	return [
	    'attendee_cidrs' => array_values(array_unique($cidrs)),
	    'local_ips' => gamedetect_local_ips(),
	    'poll_interval' => $s['poll_interval'],
	    'idle_timeout' => $s['idle_timeout'],
	    'dns_refresh' => $s['dns_refresh'],
	    'rdns_enabled' => $s['rdns_enabled'],
	    'unknown_list_size' => $s['unknown_list_size'],
	    'data_dir' => gamedetect_path('data_dir'),
	    'signatures_path' => gamedetect_path('signatures'),
	    'default_signatures_path' => gamedetect_path('default_signatures'),
	    'leases_cmd' => GAMEDETECT_LEASES_CMD,
	];
}

function gamedetect_write_conf(): void {
	$path = gamedetect_path('conf');
	if (!is_dir(dirname($path))) {
		@mkdir(dirname($path), 0755, true);
	}
	if (!gd_write_json_atomic($path, gamedetect_build_conf())) {
		log_error("gamedetect: cannot write {$path}");
	}
}

/*
 * rc script. daemon(8) -r restarts the child, so stop signals the supervisor
 * (the child's parent) and waits for the child to exit.
 */
function gamedetect_write_rcfile(): void {
	$pid = GAMEDETECT_PID;
	$cmd = GAMEDETECT_DAEMON_CMD;
	$start = <<<SH
if ! /bin/pgrep -F {$pid} >/dev/null 2>&1; then
		{$cmd}
	fi
SH;
	$stop = <<<SH
pid=\$(/bin/cat {$pid} 2>/dev/null)
	case "\$pid" in
	'' | *[!0-9]*) return 0 ;;
	esac
	sup=\$(/bin/ps -o ppid= -p "\$pid" | /usr/bin/tr -d ' ')
	if [ -n "\$sup" ] && [ "\$(/bin/ps -o comm= -p "\$sup")" = daemon ]; then
		/bin/kill "\$sup"
	else
		/bin/kill "\$pid" 2>/dev/null
	fi
	n=0
	while /bin/kill -0 "\$pid" 2>/dev/null && [ \$n -lt 50 ]; do
		/bin/sleep 0.1
		n=\$((n + 1))
	done
	/bin/rm -f {$pid}
SH;
	write_rcfile(['file' => basename(GAMEDETECT_RCFILE), 'start' => $start, 'stop' => $stop]);
}

/* Pid of the running daemon, or null. */
function gamedetect_pid(): ?int {
	$s = trim((string)@file_get_contents(gamedetect_path('pid')));
	if (!ctype_digit($s) || (int)$s <= 0) {
		return null;
	}
	$pid = (int)$s;
	if (function_exists('posix_kill')) {
		return (posix_kill($pid, 0) || posix_get_last_error() === 1) ? $pid : null;
	}
	exec('/bin/kill -0 ' . $pid . ' 2>/dev/null', $out, $rc);
	return $rc === 0 ? $pid : null;
}

/* Write conf + rc script, install default signatures if absent, then start, reload, or stop. */
function gamedetect_resync(): void {
	$data = gamedetect_path('data_dir');
	if (!is_dir($data)) {
		@mkdir($data, 0755, true);
	}
	$live = gamedetect_path('signatures');
	if (!file_exists($live)) {
		@mkdir(dirname($live), 0755, true);
		if (!@copy(gamedetect_path('default_signatures'), $live)) {
			log_error("gamedetect: cannot install default signatures to {$live}");
		}
	}
	gamedetect_write_conf();
	gamedetect_write_rcfile();
	$conf = gd_read_json(gamedetect_path('conf'));
	$have_networks = !empty($conf['attendee_cidrs']);
	if (!$have_networks) {
		log_error('gamedetect: no usable attendee network; daemon not started');
	}
	if (gamedetect_settings()['enable'] && $have_networks) {
		$pid = gamedetect_pid();
		mwexec($pid !== null ? '/bin/kill -HUP ' . $pid : GAMEDETECT_RCFILE . ' start');
	} else {
		mwexec(GAMEDETECT_RCFILE . ' stop');
	}
	gamedetect_update_cron(gamedetect_settings()['update_check']);
}

/* Settings form validation (Services -> Game Detect). */
function gamedetect_validate_input(array $post, array &$errors): void {
	foreach (GAMEDETECT_RANGES as $f => [$min, $max]) {
		$v = $post[$f] ?? '';
		$v = is_string($v) ? trim($v) : '-';
		if ($v === '') {
			continue;
		}
		if (!ctype_digit($v) || (int)$v < $min || (int)$v > $max) {
			$errors[] = GAMEDETECT_LABELS[$f] . " must be a whole number from {$min} to {$max}.";
		}
	}
	$invalid = 0;
	$entries = gamedetect_network_entries($post, $invalid);
	if ($invalid > 0) {
		$errors[] = 'Attendee networks contain an invalid value.';
	}
	if (!$entries) {
		$errors[] = 'Select at least one attendee interface or enter an attendee CIDR.';
		return;
	}
	$ifaces = get_configured_interface_list();
	foreach ($entries as $e) {
		if (!isset($ifaces[$e]) && !gamedetect_is_cidr($e)) {
			$errors[] = 'Attendee network ' . htmlspecialchars($e) . ' is neither a configured interface nor an IPv4 CIDR.';
		}
	}
}

/* daemon.json plus 'running' from the pid file. */
function gamedetect_status(): array {
	$d = gd_read_json(gamedetect_path('data_dir') . '/daemon.json') ?? [];
	$d['running'] = gamedetect_pid() !== null;
	return $d;
}

/* Service status for pfSense (gamedetect.xml custom_php_service_status_command). */
function gamedetect_is_running(): bool {
	return gamedetect_status()['running'] === true;
}

/*
 * Package removal: stop the daemon, drop runtime data and the rc script;
 * signatures.json is kept. pkg(8) also runs the DEINSTALL hook (and so this,
 * via rc.packages) during an upgrade, with PKG_UPGRADE exported: the data dir
 * (first_seen history, signatures.lastgood.json) is kept then. The
 * POST-DEINSTALL shell hook in pkg-deinstall.sh is the authoritative removal.
 */
function gamedetect_deinstall(): void {
	if (file_exists(GAMEDETECT_RCFILE)) {
		mwexec(GAMEDETECT_RCFILE . ' stop');
	}
	if ((string)getenv('PKG_UPGRADE') === '') {
		mwexec('/bin/rm -rf ' . escapeshellarg(gamedetect_path('data_dir')));
		/* Kept during an upgrade: the updater may be the one running pkg, and resync re-adds the job. */
		gamedetect_update_cron(false);
		@unlink(gamedetect_path('update_lock'));
	}
	@unlink(GAMEDETECT_RCFILE);
}

/* ---- Self-updater ---- */

/*
 * Install (or, with $active false, remove) the daily 04:xx update-check cron
 * job. The minute is random per firewall but kept once chosen, so repeated
 * resyncs do not rewrite the config.
 */
function gamedetect_update_cron(bool $active): void {
	$minute = null;
	foreach ((array)config_get_path('cron/item', []) as $item) {
		if (is_array($item) && ($item['command'] ?? null) === GAMEDETECT_UPDATE_CHECK_CMD) {
			$m = (string)($item['minute'] ?? '');
			$minute = ctype_digit($m) && (int)$m <= 59 ? (string)(int)$m : null;
		}
	}
	install_cron_job(GAMEDETECT_UPDATE_CHECK_CMD, $active, $minute ?? (string)random_int(0, 59), '4', '*', '*', '*', 'root');
}

/*
 * Installed package version: the version build.sh substituted into info.xml,
 * else `pkg query %v pfSense-pkg-gamedetect`; '' when neither is x.y.z.
 */
function gamedetect_installed_version(): string {
	$v = gd_update_info_version(gamedetect_path('info_xml'));
	if ($v !== null) {
		return $v;
	}
	$e = gd_update_exec([GAMEDETECT_PKG_BIN, 'query', '%v', GD_UPDATE_PKG], 10, GAMEDETECT_UPDATE_ENV);
	return $e['rc'] === 0 ? (string)gd_update_semver(trim($e['output'])) : '';
}

/* FreeBSD major of the running system (php_uname('r'), e.g. "16.0-RELEASE"), 0 if unknown. */
function gamedetect_freebsd_major(): int {
	return gd_update_freebsd_major(php_uname('r')) ?? 0;
}

function gamedetect_update_ua(string $installed): string {
	return GD_UPDATE_PKG . '/' . ($installed !== '' ? $installed : 'unknown');
}

/* Paths of update.json and update-run.json. */
function gamedetect_update_file(string $which): string {
	return gamedetect_path('data_dir') . ($which === 'run' ? '/update-run.json' : '/update.json');
}

/* The check (update-check.php): GitHub releases/latest + SHA256SUMS -> update.json. */
function gamedetect_update_check(): array {
	$installed = gamedetect_installed_version();
	$ua = gamedetect_update_ua($installed);
	$s = gd_update_check([
	    'repo' => GAMEDETECT_UPDATE_REPO,
	    'major' => gamedetect_freebsd_major(),
	    'installed' => $installed,
	    'state_path' => gamedetect_update_file('check'),
	    'fetch' => function (string $url, int $max) use ($ua): array {
		    $hdr = str_starts_with($url, 'https://api.github.com/')
			? ['Accept: application/vnd.github+json', 'X-GitHub-Api-Version: 2022-11-28'] : [];
		    return gd_update_http_get($url, $ua, GAMEDETECT_UPDATE_HTTP_TIMEOUT_S, $max, null, $hdr);
	    },
	]);
	if ($s['error'] !== null) {
		log_error('gamedetect: update check failed: ' . $s['error']);
	}
	return $s;
}

/*
 * The update (update-run.php, started detached by Update now): take the lock,
 * install the release recorded in update.json, re-run the check in a fresh
 * process so update.json shows the new version. Returns the exit status.
 */
function gamedetect_update_run(): int {
	$lock = gamedetect_path('update_lock');
	if (!gd_update_lock_acquire($lock, getmypid())) {
		log_error('gamedetect: update not started: another update is running');
		return 1;
	}
	try {
		$installed = gamedetect_installed_version();
		$ua = gamedetect_update_ua($installed);
		$r = gd_update_run([
		    'state_path' => gamedetect_update_file('check'),
		    'run_path' => gamedetect_update_file('run'),
		    'repo' => GAMEDETECT_UPDATE_REPO,
		    'major' => gamedetect_freebsd_major(),
		    'installed' => $installed,
		    'tmp_dir' => gamedetect_path('tmp_dir'),
		    'download' => fn(string $url, string $dest, int $max): array
			=> gd_update_http_get($url, $ua, GAMEDETECT_UPDATE_DL_TIMEOUT_S, $max, $dest),
		    'pkg_add' => function (string $file): array {
			    $e = gd_update_exec([GAMEDETECT_PKG_BIN, 'add', '-f', $file], GAMEDETECT_UPDATE_PKG_TIMEOUT_S, GAMEDETECT_UPDATE_ENV);
			    return ['rc' => $e['rc'], 'output' => $e['output'] . ($e['timed_out'] ? "\n(pkg add timed out)\n" : '')];
		    },
		    'recheck' => function (): void {
			    gd_update_exec(GAMEDETECT_UPDATE_CHECK_ARGV, 60, GAMEDETECT_UPDATE_ENV);
		    },
		]);
	} finally {
		gd_update_lock_release($lock, getmypid());
	}
	log_error($r['state'] === 'done'
	    ? "gamedetect: updated from v{$r['from']} to v{$r['to']}"
	    : 'gamedetect: update failed: ' . $r['error']);
	return $r['state'] === 'done' ? 0 : 1;
}

/* ['check' => update.json (or []), 'run' => gd_update_run_view() of update-run.json]. */
function gamedetect_update_status(): array {
	return [
	    'check' => gd_read_json(gamedetect_update_file('check')) ?? [],
	    'run' => gd_update_run_view(gd_read_json(gamedetect_update_file('run')),
		gd_update_lock_holder(gamedetect_path('update_lock')), time()),
	];
}

/*
 * Update now: refuse if an update is running or none is available, record a
 * 'running' state, and start update-run.php detached with daemon(8) so it
 * survives the web request and the package reinstall. No request data is
 * used. Returns ['ok' => bool, 'error' => ?string].
 */
function gamedetect_update_start(): array {
	$st = gamedetect_update_status();
	if ($st['run']['state'] === 'running' || gd_update_lock_holder(gamedetect_path('update_lock')) !== null) {
		return ['ok' => false, 'error' => 'An update is already running.'];
	}
	$latest = gd_update_semver($st['check']['latest'] ?? null);
	if (empty($st['check']['update_available']) || $latest === null) {
		return ['ok' => false, 'error' => 'No update is available. Use Check now to look for one.'];
	}
	$from = gamedetect_installed_version();
	if (!gd_update_write(gamedetect_update_file('run'), ['state' => 'running', 'started_at' => date(DATE_ATOM),
	    'finished_at' => null, 'from' => $from, 'to' => $latest, 'log' => '', 'error' => null])) {
		return ['ok' => false, 'error' => 'Cannot write the update status file.'];
	}
	mwexec_bg(GAMEDETECT_UPDATE_RUN_CMD);
	log_error("gamedetect: update from v{$from} to v{$latest} started from the status page");
	return ['ok' => true, 'error' => null];
}

/* Check now: run update-check.php in-line, at most 15 s. Returns ['ok', 'error']. */
function gamedetect_update_check_now(): array {
	$e = gd_update_exec(GAMEDETECT_UPDATE_CHECK_ARGV, GAMEDETECT_UPDATE_CHECK_TIMEOUT_S);
	if ($e['timed_out']) {
		return ['ok' => false, 'error' => 'The update check did not finish within ' . GAMEDETECT_UPDATE_CHECK_TIMEOUT_S . ' seconds.'];
	}
	return ['ok' => true, 'error' => null];
}
