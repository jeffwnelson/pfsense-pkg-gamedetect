<?php
/*
 * Copyright 2026 Jeff Nelson
 * Licensed under the Apache License, Version 2.0.
 *
 * pfSense glue for pfSense-pkg-gamedetect: settings validation, the daemon
 * conf and rc script, and start/reload/stop. Pure logic lives in
 * gamedetect_lib.inc; the daemon is libexec/gamedetect/gamedetectd.php.
 */
require_once('config.inc');
require_once('interfaces.inc');
require_once('service-utils.inc');
require_once('util.inc');
require_once(__DIR__ . '/gamedetect_lib.inc');

const GAMEDETECT_CFG = 'installedpackages/gamedetect/config/0';
const GAMEDETECT_RCFILE = '/usr/local/etc/rc.d/gamedetectd.sh';
const GAMEDETECT_PID = '/var/run/gamedetectd.pid';
const GAMEDETECT_DAEMON_CMD = '/usr/sbin/daemon -f -p ' . GAMEDETECT_PID
    . ' -r -R 5 /usr/local/bin/php -f /usr/local/libexec/gamedetect/gamedetectd.php';
const GAMEDETECT_LEASES_CMD = '/usr/local/libexec/gamedetect/leases.php';

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
 * (keys conf, signatures, default_signatures, data_dir, pid); production never sets it.
 */
function gamedetect_path(string $key): string {
	$defaults = [
	    'conf' => '/usr/local/etc/gamedetect/gamedetectd.conf',
	    'signatures' => '/usr/local/etc/gamedetect/signatures.json',
	    'default_signatures' => '/usr/local/share/pfSense-pkg-gamedetect/signatures.default.json',
	    'data_dir' => '/var/db/gamedetect',
	    'pid' => GAMEDETECT_PID,
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

/* Saved settings; a never-saved package uses every default (enabled, rDNS on, lan). */
function gamedetect_settings(): array {
	$cfg = config_get_path(GAMEDETECT_CFG);
	$saved = is_array($cfg);
	$cfg = $saved ? $cfg : ['attendee_networks' => 'lan'];
	$s = [
	    'enable' => $saved ? !empty($cfg['enable']) : true,
	    'rdns_enabled' => $saved ? !empty($cfg['rdns_enabled']) : true,
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
	}
	@unlink(GAMEDETECT_RCFILE);
}
