<?php
/*
 * Copyright 2026 Jeff Nelson
 * Licensed under the Apache License, Version 2.0.
 *
 * Status -> Game Detect: live client table (Clients), unclassified UDP
 * destinations (Unclassified), and the live signature file (Signatures).
 * ?format=json returns clients.json plus generated_at, daemon, and banner;
 * the Clients tab polls it every poll_interval seconds and rebuilds the
 * table body in place. Access is the page-status-gamedetect privilege.
 * POST act=reset_signatures (Signatures tab "Reset to defaults"),
 * act=update (Update now) and act=update_check (Check now) also need the
 * settings privilege (pkg_edit.php?xml=gamedetect.xml), checked before any
 * action runs (HTTP 403 otherwise); csrf-magic guards the POSTs. Every tab
 * shows the update banner; while an update runs the page polls
 * ?format=update_status every 3 s and reloads once when it finishes.
 */
require_once('guiconfig.inc');
require_once('/usr/local/pkg/gamedetect.inc');
require_once('/usr/local/pkg/gamedetect_status_lib.inc');

/* clients.json normalised for the page and the JSON endpoint. */
function gamedetect_page_payload(array $daemon): array {
	$p = gd_read_json(gamedetect_path('data_dir') . '/clients.json') ?? [];
	$p['clients'] = array_values(array_filter((array)($p['clients'] ?? []), 'is_array'));
	$pi = $p['poll_interval'] ?? null;
	$p['poll_interval'] = is_int($pi) && $pi >= 2 && $pi <= 60 ? $pi : gamedetect_settings()['poll_interval'];
	$p['generated_at'] = is_string($p['generated_at'] ?? null) ? $p['generated_at'] : date(DATE_ATOM);
	$p['daemon'] = $daemon;
	$p['banner'] = gd_status_banner($daemon);
	return $p;
}

$gd_daemon = gamedetect_status();
$gd_reset_error = null;
$gd_update_error = gd_status_update_error($_GET['update_error'] ?? null);
$gd_tabs = ['clients' => gettext('Clients'), 'unclassified' => gettext('Unclassified'), 'signatures' => gettext('Signatures')];
$gd_tab = is_string($_GET['tab'] ?? null) && isset($gd_tabs[$_GET['tab']]) ? $_GET['tab'] : 'clients';
$gd_can_settings = isAllowedPage('pkg_edit.php?xml=gamedetect.xml');

/* Privilege first: a known act without the settings privilege does nothing but 403. */
$gd_act = gd_update_post_action($_SERVER['REQUEST_METHOD'] ?? '', $_POST['act'] ?? null, $gd_can_settings);
if ($gd_act === 'forbidden') {
	http_response_code(403);
	if (($_POST['act'] ?? null) === 'reset_signatures') {
		$gd_reset_error = gettext('Resetting the signatures needs permission to change the Game Detect settings (Services > Game Detect).');
		$gd_tab = 'signatures';
	} else {
		$gd_update_error = gettext(gd_status_update_error('forbidden'));
	}
} elseif ($gd_act === 'reset_signatures') {
	$gd_reset = gamedetect_reset_signatures(gamedetect_path('signatures'), gamedetect_path('default_signatures'));
	if ($gd_reset['ok']) {
		log_error('gamedetect: signatures reset to the shipped defaults'
		    . ($gd_reset['backup'] !== null ? " (backup: {$gd_reset['backup']})" : ''));
		header('Location: status_gamedetect.php?tab=signatures&reset=ok');
		exit;
	}
	$gd_reset_error = gettext('Could not reset the signatures') . ': ' . $gd_reset['error'];
	$gd_tab = 'signatures';
}
$gd_self = 'status_gamedetect.php' . ($gd_tab === 'clients' ? '' : '?tab=' . $gd_tab);
if ($gd_act === 'update' || $gd_act === 'update_check') {
	/* Redirect either way (no resubmission on reload); a failure carries only a fixed code. */
	$gd_r = $gd_act === 'update' ? gamedetect_update_start() : gamedetect_update_check_now();
	$gd_code = !$gd_r['ok'] && gd_status_update_error($gd_r['code']) !== null ? $gd_r['code'] : null;
	header('Location: ' . $gd_self . ($gd_code === null ? '' : (str_contains($gd_self, '?') ? '&' : '?') . 'update_error=' . $gd_code));
	exit;
}

if (($_GET['format'] ?? null) === 'update_status') {
	$gd_run = gamedetect_update_status()['run'];
	unset($gd_run['log']);
	header('Content-Type: application/json');
	header('Cache-Control: no-store');
	echo json_encode($gd_run, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
	exit;
}

if (($_GET['format'] ?? null) === 'json') {
	header('Content-Type: application/json');
	header('Cache-Control: no-store');
	echo json_encode(gamedetect_page_payload($gd_daemon), JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
	exit;
}

$gd_banner = gd_status_banner($gd_daemon);
$gd_update = gamedetect_update_status();

$pgtitle = array(gettext('Status'), gettext('Game Detect'));
include('head.inc');

$tab_array = array();
foreach ($gd_tabs as $k => $label) {
	$tab_array[] = array($label, $gd_tab === $k, 'status_gamedetect.php' . ($k === 'clients' ? '' : '?tab=' . $k));
}
display_top_tabs($tab_array);
?>
<?php if ($gd_update_error !== null): ?>
<div class="alert alert-danger" role="alert"><?=htmlspecialchars($gd_update_error)?></div>
<?php endif; ?>
<?=gd_status_update_html($gd_update['check'], $gd_update['run'], $gd_can_settings, $gd_self, time())?>
<?php if ($gd_update['run']['state'] === 'running'): ?>
<script type="text/javascript">
//<![CDATA[
events.push(function () {
	var url = 'status_gamedetect.php?format=update_status';
	var self = <?=json_encode($gd_self, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)?>;
	var stuck = <?=json_encode(gettext('The update is taking longer than expected; reload this page to check on it.'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)?>;
	var polls = 0;

	/* Poll every 3 s; reload once, without resubmitting, when the update is no longer running. */
	function next() {
		polls++;
		if (polls <= 400) {
			setTimeout(poll, 3000);
		} else {
			$('#gd-update-running').text(stuck);
		}
	}

	function poll() {
		$.ajax({url: url, dataType: 'json', cache: false}).done(function (d) {
			if (d && typeof d === 'object' && typeof d.state === 'string' && d.state !== 'running') {
				window.location.replace(self);
				return;
			}
			next();
		}).fail(next);
	}

	next();
});
//]]>
</script>
<?php endif; ?>
<div id="gd-banner" class="alert alert-warning<?=$gd_banner === null ? ' hidden' : ''?>" role="alert"><?=htmlspecialchars((string)$gd_banner)?></div>
<?php
if ($gd_tab === 'clients'):
	$gd_payload = gamedetect_page_payload($gd_daemon);
	$gd_filter = is_string($_GET['filter'] ?? null) ? $_GET['filter'] : '';
	$gd_game = is_string($_GET['game'] ?? null) ? $_GET['game'] : '';
	$gd_summary = gd_status_summary($gd_payload['clients']);
	$gd_rows = gd_status_filter_clients($gd_payload['clients'], $gd_filter, $gd_game);
?>
<div class="panel panel-default">
	<div class="panel-heading">
		<h2 class="panel-title">
			<?=htmlspecialchars(gettext('Clients'))?>
			<span class="pull-right" id="gd-summary"><?=htmlspecialchars(sprintf(gettext('Clients: %d  Playing: %d  Unknown: %d'),
			    $gd_summary['total'], $gd_summary['playing'], $gd_summary['unknown']))?></span>
		</h2>
	</div>
	<div class="panel-body">
		<form class="form-inline" id="gd-controls" onsubmit="return false;">
			<div class="form-group">
				<label class="sr-only" for="gd-filter"><?=htmlspecialchars(gettext('Filter'))?></label>
				<input type="text" class="form-control" id="gd-filter" placeholder="<?=htmlspecialchars(gettext('Filter IP, MAC, hostname, game'))?>" value="<?=htmlspecialchars($gd_filter)?>">
			</div>
			<div class="form-group">
				<label class="sr-only" for="gd-game"><?=htmlspecialchars(gettext('Game'))?></label>
				<select class="form-control" id="gd-game">
					<option value=""><?=htmlspecialchars(gettext('All games'))?></option>
<?php foreach ($gd_summary['by_game'] as $id => $g): ?>
					<option value="<?=htmlspecialchars((string)$id)?>"<?=(string)$id === $gd_game ? ' selected' : ''?>><?=htmlspecialchars($g['name'] . ' (' . $g['count'] . ')')?></option>
<?php endforeach; ?>
<?php if ($gd_game !== '' && !isset($gd_summary['by_game'][$gd_game])): ?>
					<option value="<?=htmlspecialchars($gd_game)?>" selected><?=htmlspecialchars($gd_game . ' (0)')?></option>
<?php endif; ?>
				</select>
			</div>
			<button type="button" class="btn btn-sm btn-default" id="gd-pause" title="<?=htmlspecialchars(gettext('Pause auto-refresh'))?>">
				<i class="fa-solid fa-pause"></i> <span><?=htmlspecialchars(gettext('Pause'))?></span>
			</button>
			<span class="text-muted">
				<i class="fa-solid fa-rotate" id="gd-spin"></i>
				<span id="gd-refresh"><?=htmlspecialchars(sprintf(gettext('Auto-refresh every %d s'), $gd_payload['poll_interval']))?></span>
				&middot; <span id="gd-updated" title="<?=htmlspecialchars($gd_payload['generated_at'])?>"><?=htmlspecialchars(gettext('Updated') . ' ' . gd_status_time($gd_payload['generated_at']))?></span>
			</span>
		</form>
	</div>
	<div class="table-responsive">
		<table class="table table-striped table-hover table-condensed">
			<thead>
				<tr>
					<th><?=htmlspecialchars(gettext('IP'))?></th>
					<th><?=htmlspecialchars(gettext('MAC'))?></th>
					<th><?=htmlspecialchars(gettext('Hostname'))?></th>
					<th><?=htmlspecialchars(gettext('Game'))?></th>
					<th><?=htmlspecialchars(gettext('Conf.'))?></th>
					<th><?=htmlspecialchars(gettext('Flows'))?></th>
					<th><?=htmlspecialchars(gettext('Last seen'))?></th>
					<th></th>
				</tr>
			</thead>
			<tbody id="gd-rows">
<?php
	foreach ($gd_rows as $c) {
		echo gd_status_row_html($c), "\n";
	}
	if (!$gd_rows):
?>
				<tr class="gd-empty"><td colspan="<?=GD_STATUS_COLUMNS?>"><?=htmlspecialchars(gettext('No clients with game traffic.'))?></td></tr>
<?php endif; ?>
			</tbody>
		</table>
	</div>
</div>

<script type="text/javascript">
//<![CDATA[
events.push(function () {
	var url = 'status_gamedetect.php?format=json';
	var data = <?=json_encode($gd_payload, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)?>;
	var txt = <?=json_encode([
	    'summary' => gettext('Clients: %t  Playing: %p  Unknown: %u'),
	    'refresh' => gettext('Auto-refresh every %s s'),
	    'paused' => gettext('Auto-refresh paused'),
	    'updated' => gettext('Updated'),
	    'failed' => gettext('Refresh failed; retrying'),
	    'empty' => gettext('No clients with game traffic.'),
	    'nodest' => gettext('No destinations recorded.'),
	    'topdest' => gettext('Top destinations'),
	    'all' => gettext('All games'),
	    'pause' => gettext('Pause'),
	    'resume' => gettext('Resume'),
	    'pausetip' => gettext('Pause auto-refresh'),
	    'resumetip' => gettext('Resume auto-refresh'),
	], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)?>;
	var COLS = <?=GD_STATUS_COLUMNS?>;
	var BADGE = {high: 'label label-success', medium: 'label label-warning', low: 'label label-default'};
	var paused = false;
	var timer = null;
	var expanded = {};
	var builtSelect = null;
	var $rows = $('#gd-rows');
	var $filter = $('#gd-filter');
	var $game = $('#gd-game');

	/* Same escaping as htmlspecialchars(ENT_QUOTES). */
	function esc(v) {
		var map = {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'};
		return String(v === null || v === undefined ? '' : v).replace(/[&<>"']/g, function (ch) {
			return map[ch];
		});
	}

	function dash(v) {
		return (v === null || v === undefined || v === '') ? '&mdash;' : esc(v);
	}

	/* Mirrors gd_status_time(). */
	function timeOf(iso) {
		var m = /^\d{4}-\d\d-\d\dT(\d\d:\d\d:\d\d)/.exec(String(iso || ''));
		return m ? m[1] : String(iso || '');
	}

	/* Mirrors gd_status_row_html(). */
	function rowHtml(c) {
		var ip = String(c.ip || '');
		var unknown = (c.game_id === undefined || c.game_id === null ? 'unknown' : c.game_id) === 'unknown';
		var conf = String(c.confidence || 'unknown');
		var badge = BADGE.hasOwnProperty(conf) ? '<span class="' + BADGE[conf] + '">' + esc(conf) + '</span>' : '&mdash;';
		var h = '<tr data-ip="' + esc(ip) + '">' +
		    '<td>' + esc(ip) + '</td>' +
		    '<td>' + dash(c.mac) + '</td>' +
		    '<td>' + dash(c.hostname) + '</td>' +
		    '<td>' + esc(c.game === undefined || c.game === null ? 'unknown' : c.game) + '</td>' +
		    '<td>' + badge + '</td>' +
		    '<td>' + esc(c.flows === undefined || c.flows === null ? 0 : c.flows) + '</td>' +
		    '<td title="' + esc(c.last_seen) + '">' + esc(timeOf(c.last_seen)) + '</td>' +
		    '<td>';
		if (unknown) {
			h += '<a href="#" class="gd-expand" data-ip="' + esc(ip) + '" title="' + esc(txt.topdest) + '">' +
			    '<i class="fa-solid fa-chevron-right"></i></a>';
		}
		h += '</td></tr>';
		if (unknown) {
			var items = '';
			$.each(Array.isArray(c.top_destinations) ? c.top_destinations : [], function (i, d) {
				if (!d || typeof d !== 'object') {
					return;
				}
				var names = Array.isArray(d.names) ? d.names : [];
				items += '<li>' + esc((d.ip || '') + ':' + (d.port === undefined ? '' : d.port)) +
				    (names.length ? ' &ndash; ' + esc(names.join(', ')) : '') + '</li>';
			});
			h += '<tr class="gd-detail hidden" data-ip="' + esc(ip) + '"><td colspan="' + COLS + '">' +
			    (items === '' ? esc(txt.nodest) : '<ul class="list-unstyled">' + items + '</ul>') + '</td></tr>';
		}
		return h;
	}

	/* Mirrors gd_status_filter_clients(). */
	function filtered(clients, filter, gameId) {
		var needle = String(filter).trim().toLowerCase();
		return $.grep(clients, function (c) {
			if (gameId !== '' && String(c.game_id || '') !== gameId) {
				return false;
			}
			if (needle === '') {
				return true;
			}
			var hay = [c.ip, c.mac, c.hostname, c.game].map(function (v) {
				return v === null || v === undefined ? '' : String(v);
			}).join('\n').toLowerCase();
			return hay.indexOf(needle) !== -1;
		});
	}

	/* Mirrors gd_status_summary(). */
	function summary(clients) {
		var s = {total: 0, playing: 0, unknown: 0, by_game: {}};
		$.each(clients, function (i, c) {
			var id = String(c.game_id === undefined || c.game_id === null ? 'unknown' : c.game_id);
			s.total++;
			if (id === 'unknown') {
				s.unknown++;
			} else {
				s.playing++;
			}
			if (!s.by_game.hasOwnProperty(id)) {
				s.by_game[id] = {name: String(c.game === undefined || c.game === null ? id : c.game), count: 0};
			}
			s.by_game[id].count++;
		});
		return s;
	}

	function rebuildGameSelect(byGame) {
		var sel = String($game.val() || '');
		var ids = Object.keys(byGame).sort(function (a, b) {
			return byGame[a].name.toLowerCase() < byGame[b].name.toLowerCase() ? -1 : (byGame[a].name.toLowerCase() > byGame[b].name.toLowerCase() ? 1 : 0);
		});
		var h = '<option value="">' + esc(txt.all) + '</option>';
		$.each(ids, function (i, id) {
			h += '<option value="' + esc(id) + '">' + esc(byGame[id].name + ' (' + byGame[id].count + ')') + '</option>';
		});
		if (sel !== '' && !byGame.hasOwnProperty(sel)) {
			h += '<option value="' + esc(sel) + '">' + esc(sel + ' (0)') + '</option>';
		}
		if (h !== builtSelect) {
			builtSelect = h;
			$game.html(h).val(sel);
		}
	}

	function detailRow(ip) {
		return $rows.find('tr.gd-detail').filter(function () {
			return $(this).attr('data-ip') === ip;
		});
	}

	function setExpanded(ip, open) {
		detailRow(ip).toggleClass('hidden', !open);
		$rows.find('a.gd-expand').filter(function () {
			return $(this).attr('data-ip') === ip;
		}).find('i').toggleClass('fa-chevron-right', !open).toggleClass('fa-chevron-down', open);
	}

	function render() {
		var clients = Array.isArray(data.clients) ? data.clients : [];
		var rows = filtered(clients, String($filter.val() || ''), String($game.val() || ''));
		var h = '';
		$.each(rows, function (i, c) {
			h += rowHtml(c);
		});
		$rows.html(h === '' ? '<tr class="gd-empty"><td colspan="' + COLS + '">' + esc(txt.empty) + '</td></tr>' : h);
		$.each(Object.keys(expanded), function (i, ip) {
			setExpanded(ip, true);
		});
	}

	function renderMeta() {
		var s = summary(Array.isArray(data.clients) ? data.clients : []);
		$('#gd-summary').text(txt.summary.replace('%t', s.total).replace('%p', s.playing).replace('%u', s.unknown));
		rebuildGameSelect(s.by_game);
		$('#gd-updated').text(txt.updated + ' ' + timeOf(data.generated_at)).attr('title', String(data.generated_at || ''));
		$('#gd-banner').text(data.banner || '').toggleClass('hidden', !data.banner);
		$('#gd-refresh').text(paused ? txt.paused : txt.refresh.replace('%s', interval()));
	}

	function interval() {
		var n = parseInt(data.poll_interval, 10);
		return isNaN(n) || n < 2 ? 5 : n;
	}

	function schedule() {
		clearTimeout(timer);
		if (!paused) {
			timer = setTimeout(poll, interval() * 1000);
		}
	}

	function poll() {
		if (paused) {
			return;
		}
		$('#gd-spin').addClass('fa-spin');
		$.ajax({url: url, dataType: 'json', cache: false}).done(function (d) {
			if (d && typeof d === 'object') {
				data = d;
				renderMeta();
				render();
			}
		}).fail(function () {
			$('#gd-refresh').text(txt.failed);
		}).always(function () {
			$('#gd-spin').removeClass('fa-spin');
			schedule();
		});
	}

	$filter.on('input', render);
	$game.on('change', render);
	$rows.on('click', 'a.gd-expand', function (e) {
		e.preventDefault();
		var ip = String($(this).attr('data-ip'));
		var open = !expanded.hasOwnProperty(ip);
		if (open) {
			expanded[ip] = true;
		} else {
			delete expanded[ip];
		}
		setExpanded(ip, open);
	});
	$('#gd-pause').on('click', function () {
		paused = !paused;
		$(this).attr('title', paused ? txt.resumetip : txt.pausetip);
		$(this).find('i').toggleClass('fa-pause', !paused).toggleClass('fa-play', paused);
		$(this).find('span').text(paused ? txt.resume : txt.pause);
		$('#gd-refresh').text(paused ? txt.paused : txt.refresh.replace('%s', interval()));
		if (paused) {
			clearTimeout(timer);
		} else {
			poll();
		}
	});

	render();
	schedule();
});
//]]>
</script>
<?php
elseif ($gd_tab === 'unclassified'):
	$gd_unknown = gd_read_json(gamedetect_path('data_dir') . '/unknown.json') ?? [];
	$gd_list = array_values(array_filter((array)($gd_unknown['unknown'] ?? []), 'is_array'));
?>
<div class="panel panel-default">
	<div class="panel-heading">
		<h2 class="panel-title">
			<?=htmlspecialchars(gettext('Unclassified UDP destinations'))?>
<?php if (is_string($gd_unknown['generated_at'] ?? null)): ?>
			<span class="pull-right"><?=htmlspecialchars(gettext('Updated') . ' ' . $gd_unknown['generated_at'])?></span>
<?php endif; ?>
		</h2>
	</div>
	<div class="panel-body">
		<p class="text-muted"><?=htmlspecialchars(gettext('The most common UDP destinations of clients the signatures do not classify, ranked by client count. Use them to draft new signatures.'))?></p>
	</div>
	<div class="table-responsive">
		<table class="table table-striped table-hover table-condensed">
			<thead>
				<tr>
					<th><?=htmlspecialchars(gettext('Destination'))?></th>
					<th><?=htmlspecialchars(gettext('Port'))?></th>
					<th><?=htmlspecialchars(gettext('DNS / reverse DNS names'))?></th>
					<th><?=htmlspecialchars(gettext('Clients'))?></th>
				</tr>
			</thead>
			<tbody>
<?php foreach ($gd_list as $u):
	$names = array_map('strval', array_filter((array)($u['names'] ?? []), 'is_scalar'));
?>
				<tr>
					<td><?=htmlspecialchars((string)($u['ip'] ?? ''))?></td>
					<td><?=htmlspecialchars((string)($u['port'] ?? ''))?></td>
					<td><?=$names ? htmlspecialchars(implode(', ', $names)) : '&mdash;'?></td>
					<td><?=htmlspecialchars((string)($u['clients'] ?? 0))?></td>
				</tr>
<?php endforeach; ?>
<?php if (!$gd_list): ?>
				<tr><td colspan="4"><?=htmlspecialchars(gettext('No unclassified destinations.'))?></td></tr>
<?php endif; ?>
			</tbody>
		</table>
	</div>
</div>
<?php
else:
	$gd_sig_path = gamedetect_path('signatures');
	clearstatcache(true, $gd_sig_path);
	$gd_sig_mtime = @filemtime($gd_sig_path);
	$gd_sig_body = @file_get_contents($gd_sig_path, false, null, 0, 1048576);
	$gd_sig_differs = gd_status_signatures_differ($gd_sig_path, gamedetect_path('default_signatures'));
	$gd_can_reset = $gd_can_settings;
	$gd_sig_warn = null;
	foreach ((array)($gd_daemon['warnings'] ?? []) as $w) {
		if (is_string($w) && str_starts_with($w, 'signatures_invalid:')) {
			$gd_sig_warn = trim(substr($w, strlen('signatures_invalid:')));
		}
	}
	if (empty($gd_daemon['running'])) {
		$gd_sig_status = ['label label-default', gettext('Unknown: the daemon is not running')];
	} elseif ($gd_sig_warn !== null) {
		$gd_sig_status = ['label label-warning', gettext('Invalid, previous signatures still in use') . ': ' . $gd_sig_warn];
	} else {
		$gd_sig_status = ['label label-success', gettext('Loaded')];
	}
?>
<?php if ($gd_reset_error !== null): ?>
<div class="alert alert-danger" role="alert"><?=htmlspecialchars($gd_reset_error)?></div>
<?php elseif (($_GET['reset'] ?? null) === 'ok'): ?>
<div class="alert alert-success" role="alert"><?=htmlspecialchars(gettext('Signatures reset to the shipped defaults; the previous file was backed up next to it. The daemon loads them on its next poll.'))?></div>
<?php endif; ?>
<?php if ($gd_sig_differs): ?>
<div class="alert alert-info clearfix" role="alert">
<?php if ($gd_can_reset): ?>
	<form method="post" action="status_gamedetect.php?tab=signatures" class="pull-right">
		<input type="hidden" name="act" value="reset_signatures">
		<button type="submit" class="btn btn-sm btn-warning"><i class="fa-solid fa-arrow-rotate-left"></i> <?=htmlspecialchars(gettext('Reset to defaults'))?></button>
	</form>
<?php endif; ?>
	<?=htmlspecialchars($gd_sig_mtime === false || $gd_sig_body === false
	    ? gettext('Your signatures file is missing or unreadable.')
	    : gettext('Your signatures file differs from the defaults shipped with this version.'))?>
<?php if ($gd_can_reset): ?>
	<?=htmlspecialchars(gettext('Reset to defaults backs it up to signatures.json.<UTC timestamp>.bak and installs the shipped defaults.'))?>
<?php else: ?>
	<?=htmlspecialchars(gettext('A user with access to Services > Game Detect can reset it to the defaults.'))?>
<?php endif; ?>
</div>
<?php endif; ?>
<div class="panel panel-default">
	<div class="panel-heading"><h2 class="panel-title"><?=htmlspecialchars(gettext('Live signature file'))?></h2></div>
	<div class="panel-body">
		<dl class="dl-horizontal">
			<dt><?=htmlspecialchars(gettext('Path'))?></dt>
			<dd><code><?=htmlspecialchars($gd_sig_path)?></code></dd>
			<dt><?=htmlspecialchars(gettext('Modified'))?></dt>
			<dd><?=htmlspecialchars($gd_sig_mtime === false ? gettext('file missing') : date('Y-m-d H:i:s T', $gd_sig_mtime))?></dd>
			<dt><?=htmlspecialchars(gettext('Last reload'))?></dt>
			<dd><span class="<?=htmlspecialchars($gd_sig_status[0])?>"><?=htmlspecialchars($gd_sig_status[1])?></span>
<?php if (is_string($gd_daemon['last_poll_at'] ?? null)): ?>
				<span class="text-muted"><?=htmlspecialchars(sprintf(gettext('(checked every poll; last poll %s)'), $gd_daemon['last_poll_at']))?></span>
<?php endif; ?>
			</dd>
		</dl>
		<p class="text-muted"><?=htmlspecialchars(gettext('Edit the file over SSH or with Diagnostics > Edit File; the daemon reloads it on the next poll when it changes.'))?></p>
<?php if ($gd_sig_body === false): ?>
		<div class="alert alert-warning"><?=htmlspecialchars(gettext('The signature file cannot be read.'))?></div>
<?php else: ?>
		<pre><?=htmlspecialchars($gd_sig_body)?></pre>
<?php endif; ?>
	</div>
</div>
<?php
endif;
include('foot.inc');
