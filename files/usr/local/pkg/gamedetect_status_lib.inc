<?php
/*
 * Copyright 2026 Jeff Nelson
 * Licensed under the Apache License, Version 2.0.
 *
 * Pure-PHP helpers for Status -> Game Detect (status_gamedetect.php). No
 * pfSense dependencies. The page's JavaScript row builder mirrors
 * gd_status_row_html(); keep the two in step.
 */

const GD_STATUS_COLUMNS = 8;

function gd_status_h($v): string {
	return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/*
 * Clients whose ip/mac/hostname/game contains $filter (case-insensitive) and,
 * when $game_id is not '', whose game_id equals it. Order is preserved.
 */
function gd_status_filter_clients(array $clients, string $filter, string $game_id): array {
	$needle = strtolower(trim($filter));
	$out = [];
	foreach ($clients as $c) {
		if (!is_array($c)) {
			continue;
		}
		if ($game_id !== '' && (string)($c['game_id'] ?? '') !== $game_id) {
			continue;
		}
		if ($needle !== '') {
			$hay = strtolower(implode("\n", array_map('strval', [
			    $c['ip'] ?? '', $c['mac'] ?? '', $c['hostname'] ?? '', $c['game'] ?? ''])));
			if (!str_contains($hay, $needle)) {
				continue;
			}
		}
		$out[] = $c;
	}
	return $out;
}

/* ['total','playing','unknown','by_game' => [game_id => ['name','count']]]; by_game sorted by name. */
function gd_status_summary(array $clients): array {
	$s = ['total' => 0, 'playing' => 0, 'unknown' => 0, 'by_game' => []];
	foreach ($clients as $c) {
		if (!is_array($c)) {
			continue;
		}
		$id = (string)($c['game_id'] ?? 'unknown');
		$s['total']++;
		$s[$id === 'unknown' ? 'unknown' : 'playing']++;
		$s['by_game'][$id] ??= ['name' => (string)($c['game'] ?? $id), 'count' => 0];
		$s['by_game'][$id]['count']++;
	}
	uasort($s['by_game'], fn($a, $b) => strcasecmp($a['name'], $b['name']));
	return $s;
}

/* HH:MM:SS from an ISO-8601 timestamp (firewall local time), else the raw value. */
function gd_status_time($iso): string {
	$iso = (string)$iso;
	return preg_match('/^\d{4}-\d\d-\d\dT(\d\d:\d\d:\d\d)/', $iso, $m) ? $m[1] : $iso;
}

/*
 * One client row. A client with game_id 'unknown' gets a chevron and a
 * following hidden detail row (tr.gd-detail) listing its top destinations.
 */
function gd_status_row_html(array $c): string {
	$h = 'gd_status_h';
	$ip = (string)($c['ip'] ?? '');
	$unknown = ($c['game_id'] ?? 'unknown') === 'unknown';
	$conf = (string)($c['confidence'] ?? 'unknown');
	$cls = ['high' => 'label label-success', 'medium' => 'label label-warning', 'low' => 'label label-default'];
	$badge = isset($cls[$conf]) ? '<span class="' . $cls[$conf] . '">' . $h($conf) . '</span>' : '&mdash;';
	$dash = fn($v) => ($v === null || $v === '') ? '&mdash;' : $h($v);
	$out = '<tr data-ip="' . $h($ip) . '">'
	    . '<td>' . $h($ip) . '</td>'
	    . '<td>' . $dash($c['mac'] ?? null) . '</td>'
	    . '<td>' . $dash($c['hostname'] ?? null) . '</td>'
	    . '<td>' . $h($c['game'] ?? 'unknown') . '</td>'
	    . '<td>' . $badge . '</td>'
	    . '<td>' . $h($c['flows'] ?? 0) . '</td>'
	    . '<td title="' . $h($c['last_seen'] ?? '') . '">' . $h(gd_status_time($c['last_seen'] ?? '')) . '</td>'
	    . '<td>';
	if ($unknown) {
		$out .= '<a href="#" class="gd-expand" data-ip="' . $h($ip) . '" title="' . $h('Top destinations') . '">'
		    . '<i class="fa-solid fa-chevron-right"></i></a>';
	}
	$out .= '</td></tr>';
	if ($unknown) {
		$items = '';
		foreach ((array)($c['top_destinations'] ?? []) as $d) {
			if (!is_array($d)) {
				continue;
			}
			$names = array_map('strval', array_filter((array)($d['names'] ?? []), 'is_scalar'));
			$items .= '<li>' . $h(($d['ip'] ?? '') . ':' . ($d['port'] ?? ''))
			    . ($names ? ' &ndash; ' . $h(implode(', ', $names)) : '') . '</li>';
		}
		$out .= '<tr class="gd-detail hidden" data-ip="' . $h($ip) . '"><td colspan="' . GD_STATUS_COLUMNS . '">'
		    . ($items === '' ? $h('No destinations recorded.') : '<ul class="list-unstyled">' . $items . '</ul>')
		    . '</td></tr>';
	}
	return $out;
}

/*
 * Plain-text banner for daemon.json + 'running' (gamedetect_status()); null
 * when the daemon is running, not paused, and has no warnings. Callers escape.
 */
function gd_status_banner(array $d): ?string {
	if (empty($d['running'])) {
		return 'The Game Detect daemon is not running. Check that it is enabled in Services > Game Detect and see the system log.';
	}
	$msgs = [];
	if (($d['state'] ?? 'running') === 'paused') {
		$reason = (string)($d['reason'] ?? '');
		$msgs[] = $reason === 'no_attendee_networks'
		    ? 'Polling is paused: no usable attendee network is configured (no_attendee_networks). Set one in Services > Game Detect.'
		    : 'Polling is paused: ' . ($reason !== '' ? $reason : 'no reason given') . '.';
	}
	$known = [
	    'states_unavailable' => 'Cannot read the pf state table; showing the last good client table.',
	    'dns_cache_unavailable' => 'Cannot read the Unbound DNS cache; DNS-based evidence is stale or missing.',
	    'arp_unavailable' => 'Cannot read the ARP table; MAC addresses may be missing or stale.',
	    'leases_unavailable' => 'Cannot read DHCP leases; hostnames may be missing or stale.',
	];
	foreach ((array)($d['warnings'] ?? []) as $w) {
		$w = (string)$w;
		if (isset($known[$w])) {
			$msgs[] = $known[$w];
		} elseif (str_starts_with($w, 'signatures_invalid:')) {
			$msgs[] = 'The signature file is invalid; the previous signatures are still in use: '
			    . trim(substr($w, strlen('signatures_invalid:')));
		} elseif ($w !== '') {
			$msgs[] = 'Warning: ' . $w;
		}
	}
	return $msgs ? implode(' ', $msgs) : null;
}

/*
 * True when the Signatures tab should offer "Reset to defaults": the shipped
 * default is readable and the live file differs from it, is missing, or is
 * unreadable. False when the default itself cannot be read (nothing to reset to).
 */
function gd_status_signatures_differ(string $live, string $default): bool {
	$b = @hash_file('sha256', $default);
	if (!is_string($b)) {
		return false;
	}
	$a = is_file($live) && is_readable($live) ? @hash_file('sha256', $live) : false;
	return !is_string($a) || $a !== $b;
}

/*
 * Signatures tab "Reset to defaults": back up $live (if present) to
 * "$live.<UTC YYYYmmddTHHMMSSZ>.bak" (".N" appended if that name exists),
 * then replace it with $default atomically (temp file in the same directory +
 * rename, mode 0644). No shell commands. Returns
 * ['ok' => bool, 'backup' => ?string, 'error' => ?string]; on error the live
 * file is unchanged.
 */
function gamedetect_reset_signatures(string $live, string $default, ?int $now = null): array {
	$fail = fn(string $m, ?string $bak = null) => ['ok' => false, 'backup' => $bak, 'error' => $m];
	$body = is_file($default) ? @file_get_contents($default) : false;
	if ($body === false) {
		return $fail("cannot read the shipped default signatures ($default)");
	}
	$dir = dirname($live);
	if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
		return $fail("cannot create $dir");
	}
	$backup = null;
	if (file_exists($live)) {
		$base = $live . '.' . gmdate('Ymd\THis\Z', $now ?? time());
		$backup = "$base.bak";
		for ($i = 1; file_exists($backup); $i++) {
			$backup = "$base.$i.bak";
		}
		if (!@copy($live, $backup)) {
			return $fail("cannot back up $live to $backup");
		}
	}
	$tmp = @tempnam($dir, '.gd');
	if ($tmp === false || realpath(dirname($tmp)) !== realpath($dir)) {
		if ($tmp !== false) {
			@unlink($tmp);
		}
		return $fail("cannot write a temporary file in $dir", $backup);
	}
	if (@file_put_contents($tmp, $body) === false || !@chmod($tmp, 0644) || !@rename($tmp, $live)) {
		@unlink($tmp);
		return $fail("cannot replace $live", $backup);
	}
	return ['ok' => true, 'backup' => $backup, 'error' => null];
}

/* "Y-m-d H:i" (firewall local time) from an ISO-8601 timestamp, else the raw value. */
function gd_status_datetime($iso): string {
	$t = is_string($iso) && $iso !== '' ? strtotime($iso) : false;
	return $t === false ? (string)$iso : date('Y-m-d H:i', $t);
}

/*
 * Fixed message for an update error flag carried in the redirect after an
 * Update now / Check now POST (?update_error=<code>); null for anything
 * else, so text from the URL is never shown.
 */
function gd_status_update_error($code): ?string {
	$m = [
	    'running' => 'An update is already running.',
	    'none' => 'No update is available. Use Check now to look for one.',
	    'write' => 'Cannot write the update status file in /var/db/gamedetect.',
	    'timeout' => 'The update check did not finish within 30 seconds; check the firewall\'s DNS and internet access, and the system log.',
	    'forbidden' => 'Updating Game Detect needs permission to change the Game Detect settings (Services > Game Detect).',
	];
	return is_string($code) && isset($m[$code]) ? $m[$code] : null;
}

/* A small POST form back to $action with one act button (csrf-magic adds the token). */
function gd_status_update_form(string $action, string $act, string $cls, string $icon, string $label): string {
	$h = 'gd_status_h';
	return '<form method="post" action="' . $h($action) . '" class="gd-update-form" style="display:inline">'
	    . '<input type="hidden" name="act" value="' . $h($act) . '">'
	    . '<button type="submit" class="btn btn-xs ' . $h($cls) . '"><i class="fa-solid ' . $h($icon) . '"></i> ' . $h($label) . '</button>'
	    . '</form>';
}

/*
 * Update banner for every tab of the status page. $check is update.json,
 * $run is gd_update_run_view() of update-run.json, $can_update is the
 * settings privilege, $action the page URL the forms post to. While an update
 * runs: a progress note (the page script polls and reloads). Otherwise the
 * last result (for a day), then "vX is available" with the release notes
 * link and, for $can_update, Update now / Check now. With nothing available,
 * $can_update users get a quiet "last checked" line with Check now. Every
 * value is escaped; release_url is linked only on github.com.
 */
function gd_status_update_html(array $check, array $run, bool $can_update, string $action, int $now): string {
	$h = 'gd_status_h';
	$ver = fn($v) => is_string($v) && preg_match('/^\d{1,6}\.\d{1,6}\.\d{1,6}\z/', $v) === 1 ? $v : null;
	$state = (string)($run['state'] ?? 'idle');
	if ($state === 'running') {
		$to = $ver($run['to'] ?? null);
		return '<div class="alert alert-info" role="alert" id="gd-update-running">'
		    . '<i class="fa-solid fa-gear fa-spin"></i> '
		    . $h('Updating to ' . ($to !== null ? "v{$to}" : 'the new version') . ' — this page will reload when done.')
		    . '</div>';
	}
	$out = '';
	$fin = strtotime((string)($run['finished_at'] ?? ''));
	if (in_array($state, ['done', 'failed'], true) && $fin !== false && $now - $fin <= 86400) {
		$to = $ver($run['to'] ?? null);
		$when = ' <span class="text-muted">(' . $h(gd_status_datetime($run['finished_at'])) . ')</span>';
		if ($state === 'done') {
			$out .= '<div class="alert alert-success" role="alert">'
			    . $h('Game Detect was updated to ' . ($to !== null ? "v{$to}" : 'the new version') . '.') . $when . '</div>';
		} else {
			$log = (string)($run['log'] ?? '');
			$out .= '<div class="alert alert-danger" role="alert">'
			    . $h('The update to ' . ($to !== null ? "v{$to}" : 'the new version') . ' failed: ' . (string)($run['error'] ?? 'unknown error')) . $when
			    . ($log !== '' ? '<details><summary>' . $h('Updater output') . '</summary><pre>' . $h($log) . '</pre></details>' : '')
			    . '</div>';
		}
	}
	$latest = $ver($check['latest'] ?? null);
	$checked = is_string($check['checked_at'] ?? null)
	    ? 'Last checked ' . gd_status_datetime($check['checked_at']) . '.' : 'Not checked yet.';
	$err = is_scalar($check['error'] ?? null) && (string)$check['error'] !== '' ? ' ' . (string)$check['error'] : '';
	$quiet = '<span class="text-muted">' . $h($checked . $err) . '</span>';
	if (!empty($check['update_available']) && $latest !== null) {
		$installed = (string)($check['installed'] ?? '');
		$url = $check['release_url'] ?? null;
		$link = is_string($url) && preg_match('#^https://github\.com/[A-Za-z0-9._/%+-]+\z#', $url) === 1
		    ? ' <a href="' . $h($url) . '" target="_blank" rel="noopener">' . $h('Release notes') . '</a>' : '';
		$btns = $can_update ? '<span class="pull-right">'
		    . gd_status_update_form($action, 'update', 'btn-primary', 'fa-download', 'Update now') . ' '
		    . gd_status_update_form($action, 'update_check', 'btn-default', 'fa-rotate', 'Check now') . '</span>' : '';
		$out .= '<div class="alert alert-info clearfix" role="alert" id="gd-update">' . $btns
		    . $h("Game Detect v{$latest} is available (installed v" . ($installed !== '' ? $installed : '?') . ').')
		    . $link . ' ' . $quiet . '</div>';
	} elseif ($can_update) {
		$v = $ver($check['installed'] ?? null);
		$out .= '<p class="text-muted small" id="gd-update">'
		    . $h(($v !== null ? "Game Detect v{$v}. " : '') . $checked . $err) . ' '
		    . gd_status_update_form($action, 'update_check', 'btn-default', 'fa-rotate', 'Check now') . '</p>';
	}
	return $out;
}
