/*
 * ui/scripts/hdhomerundvr_ui.js
 *
 * Copyright (c) 2020 Rik Dunphy
 * Copyright (c) 2026 Silicondust USA Inc.
 * Released under the MIT License - see LICENSE
 */


/* Ajax - call an exported PHP function (see dvruiAjaxDispatch in
 * includes/dvrui_ajax.php). The reply is a set of element id => html, which
 * replaces the contents of those elements. A spinner shows while calls run. */
var dvruiSpinner = null;
var dvruiLoading = 0;

function dvruiLoadingShow() {
	var loading = document.getElementById('loading');
	if (!loading) {
		loading = document.createElement('div');
		loading.id = 'loading';
		document.body.appendChild(loading);
	}
	if (!dvruiSpinner && typeof Spinner !== 'undefined') {
		dvruiSpinner = new Spinner({lines: 16, length: 28, width: 10, radius: 42, scale: 1,
			corners: 1, color: '#5577a0', opacity: 0.25, rotate: 0, direction: 1, speed: 2,
			trail: 60, fps: 20, zIndex: 2e9, className: 'spinner', top: '50%', left: '50%',
			shadow: false, hwaccel: false, position: 'absolute'});
	}
	if (dvruiSpinner) {
		dvruiSpinner.spin();
		loading.appendChild(dvruiSpinner.el);
	}
	loading.style.display = 'block';
	dvruiLoading++;
}

function dvruiLoadingHide() {
	dvruiLoading--;
	if (dvruiLoading < 1) {
		dvruiLoading = 0;
		var loading = document.getElementById('loading');
		if (loading) {
			if (dvruiSpinner) {
				dvruiSpinner.stop();
			}
			loading.style.display = 'none';
		}
	}
}

function dvruiCall(fn, args) {
	dvruiLoadingShow();
	return $.ajax({
		url: window.location.pathname,
		type: 'POST',
		dataType: 'json',
		data: {fn: fn, args: $.map(args, function(a) { return (a === undefined || a === null) ? '' : String(a); })}
	}).done(function(reply) {
		if (!reply || !reply.ok) {
			dvruiCallFailed(fn, reply && reply.error ? reply.error : 'no reply');
			return;
		}
		$.each(reply.html, function(id, html) {
			var el = document.getElementById(id);
			if (el) {
				el.innerHTML = html;
			}
		});
		// what the UI has to say about it - toasts (the status bar is the engine's)
		$.each(reply.messages || [], function(i, m) {
			dvruiToast(m.text, m.kind);
		});
	}).fail(function(xhr, status, err) {
		var msg = (xhr.responseJSON && xhr.responseJSON.error) ? xhr.responseJSON.error : (err || status);
		dvruiCallFailed(fn, msg + (xhr.status ? ' (HTTP ' + xhr.status + ')' : ''));
	}).always(dvruiLoadingHide);
}

function dvruiCallFailed(fn, msg) {
	dvruiToast('Request ' + fn + ' failed: ' + msg, 'error');
}

/* ---------------------------------------------------------------------------
 * Messages - the status bar shows only the record engine's status; what the UI
 * did (saved, deleted, unable to ...) is a toast at the bottom right, above the
 * bar. A toast hides after 10 seconds (or with its x); more than one stack, the
 * newest at the bottom. The Messages button on the bar shows the last 5 minutes
 * of them again - kept for the browser tab, so they last through a reload.
 * ------------------------------------------------------------------------- */
var DVRUI_TOAST_SECS = 10;
var DVRUI_MESSAGE_LOG_SECS = 300;
var dvruiMessages = dvruiMessagesLoad();

function dvruiMessagesLoad() {
	try {
		var saved = JSON.parse(window.sessionStorage.getItem('dvrui_messages') || '[]');
		return (saved && saved.length !== undefined) ? saved : [];
	} catch (e) {
		return [];
	}
}

// the messages of the last 5 minutes (older ones are dropped)
function dvruiMessagesRecent() {
	var since = new Date().getTime() - DVRUI_MESSAGE_LOG_SECS * 1000;
	var recent = [];
	for (var i = 0; i < dvruiMessages.length; i++) {
		if (dvruiMessages[i].time >= since) {
			recent.push(dvruiMessages[i]);
		}
	}
	dvruiMessages = recent;
	try {
		window.sessionStorage.setItem('dvrui_messages', JSON.stringify(recent));
	} catch (e) {
		// not kept through a reload then
	}
	return recent;
}

// a message for the user - $kind ok (green), error (red) or info
function dvruiToast(text, kind) {
	text = $.trim(String(text || ''));
	if (text == '') {
		return;
	}
	kind = (kind == 'ok' || kind == 'error') ? kind : 'info';
	dvruiMessages.push({time: new Date().getTime(), text: text, kind: kind});
	dvruiMessagesUpdate();

	var box = document.getElementById('toasts');
	if (!box) {
		return;
	}
	var toast = document.createElement('div');
	toast.className = 'toast toast_' + kind;
	toast.setAttribute('role', kind == 'error' ? 'alert' : 'status');
	var span = document.createElement('span');
	span.className = 'toast_text';
	span.textContent = text;
	var close = document.createElement('button');
	close.type = 'button';
	close.className = 'toast_close';
	close.title = 'Close';
	close.setAttribute('aria-label', 'Close this message');
	close.innerHTML = '&times;';
	var hide = function() {
		clearTimeout(toast.hideTimer);
		if (toast.parentNode) {
			toast.classList.add('toast_hiding');
			setTimeout(function() {
				if (toast.parentNode) {
					toast.parentNode.removeChild(toast);
				}
			}, 300);
		}
	};
	close.onclick = hide;
	toast.appendChild(span);
	toast.appendChild(close);
	box.appendChild(toast);
	// a toast being read (pointer over it) stays until the pointer leaves
	toast.onmouseenter = function() {
		clearTimeout(toast.hideTimer);
	};
	toast.onmouseleave = function() {
		toast.hideTimer = setTimeout(hide, 3000);
	};
	toast.hideTimer = setTimeout(hide, DVRUI_TOAST_SECS * 1000);
}

// the Messages button shows how many there are, and an open list is kept up to date
function dvruiMessagesUpdate() {
	var recent = dvruiMessagesRecent();
	var button = document.getElementById('messages_button');
	if (button) {
		button.textContent = recent.length ? 'Messages (' + recent.length + ')' : 'Messages';
		button.classList.toggle('messages_has_error', recent.some ? recent.some(function(m) { return m.kind == 'error'; }) : false);
	}
	var log = document.getElementById('message_log');
	if (log && !log.hidden) {
		dvruiMessageLogFill(log, recent);
	}
}

function dvruiMessageLogFill(log, recent) {
	log.innerHTML = '';
	var head = document.createElement('div');
	head.className = 'message_log_head';
	head.textContent = 'Messages - the last 5 minutes';
	var close = document.createElement('button');
	close.type = 'button';
	close.className = 'toast_close';
	close.title = 'Close';
	close.setAttribute('aria-label', 'Close the messages');
	close.innerHTML = '&times;';
	close.onclick = toggleMessageLog;
	head.appendChild(close);
	log.appendChild(head);
	if (recent.length == 0) {
		var none = document.createElement('p');
		none.className = 'message_log_none';
		none.textContent = 'No messages in the last 5 minutes.';
		log.appendChild(none);
		return;
	}
	var list = document.createElement('ul');
	list.className = 'message_log_list';
	// newest first
	for (var i = recent.length - 1; i >= 0; i--) {
		var item = document.createElement('li');
		item.className = 'message_log_item toast_' + recent[i].kind;
		var when = document.createElement('span');
		when.className = 'message_log_time';
		var t = new Date(recent[i].time);
		when.textContent = ('0' + t.getHours()).slice(-2) + ':' + ('0' + t.getMinutes()).slice(-2) + ':' + ('0' + t.getSeconds()).slice(-2);
		var text = document.createElement('span');
		text.textContent = recent[i].text;
		item.appendChild(when);
		item.appendChild(text);
		list.appendChild(item);
	}
	log.appendChild(list);
}

// Messages button on the status bar
function toggleMessageLog() {
	var log = document.getElementById('message_log');
	var button = document.getElementById('messages_button');
	if (!log) {
		return;
	}
	log.hidden = !log.hidden;
	if (button) {
		button.setAttribute('aria-expanded', log.hidden ? 'false' : 'true');
	}
	if (!log.hidden) {
		dvruiMessageLogFill(log, dvruiMessagesRecent());
	}
}

document.addEventListener('keydown', function(evt) {
	var log = document.getElementById('message_log');
	if (evt.key == 'Escape' && log && !log.hidden) {
		toggleMessageLog();
	}
});
// a click elsewhere closes the list
document.addEventListener('click', function(evt) {
	var log = document.getElementById('message_log');
	if (log && !log.hidden && !log.contains(evt.target) && evt.target.id != 'messages_button') {
		toggleMessageLog();
	}
});

document.addEventListener('DOMContentLoaded', function() {
	dvruiMessagesUpdate();
	// older messages drop off the count while the page is open
	setInterval(dvruiMessagesUpdate, 30000);
	// keep the record engine status on the bar up to date (not while the tab is
	// hidden) - quietly, without the loading spinner or any failure message
	setInterval(function() {
		if (document.hidden) {
			return;
		}
		$.ajax({url: window.location.pathname, type: 'POST', dataType: 'json', data: {fn: 'dvrStatus'}})
			.done(function(reply) {
				var el = document.getElementById('dvr_status');
				if (el && reply && reply.ok && reply.html && reply.html.dvr_status !== undefined) {
					el.innerHTML = reply.html.dvr_status;
				}
			});
	}, 30000);
});

// The PHP functions the page calls - each sends its arguments to the function of the same name
$.each(['getLogFile', 'updateServerConfig', 'openDashboard', 'openDiagnosticsPage',
	'openRecordedPage', 'openSeriesPage', 'seriesActionPage', 'openRulesPage',
	'moveRulePage', 'deleteRulePage', 'openRuleEditor', 'saveRulePage',
	'newRuleOpen', 'newRuleSearch', 'newRuleSeries', 'newRuleAdd', 'newRuleAddOnce',
	'checkEngineUpdate', 'updateEngine', 'engineVersion', 'openSettingsPage', 'saveSetting',
	'clearApiCache'], function(i, fn) {
	window[fn] = function() {
		return dvruiCall(fn, Array.prototype.slice.call(arguments));
	};
});

// Page currently shown, and whether the Recorded series have been fetched yet
var activeTab = '';
var recordedLoaded = false;

function loadTab(tabname, force) {
	if (tabname == 'dashboard_page') {
		openDashboard();
	}
	if (tabname == 'recorded_page') {
		// Only fetch the series once - use Reload to fetch again
		if (force || !recordedLoaded) {
			recordedLoaded = true;
			openRecordedPage();
		}
		// the row has no width while the tab is hidden - check the arrows once it's shown
		requestAnimationFrame(updateLatestArrows);
	}
	if (tabname == 'series_page') {
		// loaded by openSeries - Reload fetches the same series again
		if (force && currentSeries) {
			openSeriesPage(currentSeries.key, currentSeries.storageId);
		}
	}
	if (tabname == 'rules_page') {
		// fetched from the SiliconDust servers once - use Reload to fetch again
		if (force || !rulesLoaded) {
			rulesLoaded = true;
			if (document.getElementById('rules_box').innerHTML == '') {
				document.getElementById('rules_box').innerHTML = "<p class='recorded_msg'>Loading...</p>";
			}
			openRulesPage();
		}
	}
	if (tabname == 'diagnostics_page') {
		openDiagnosticsPage();
	}
	if (tabname == 'settings_page') {
		openSettingsPage();
	}
}

/* Settings - a switch or number saves as soon as it is changed (the page comes back
 * with what was saved, so a setting that couldn't be saved goes back) */
function changeSetting(input)
{
	var value = (input.type == 'checkbox') ? (input.checked ? '1' : '0') : input.value;
	$('#settings_box [data-setting]').prop('disabled', true);
	saveSetting(input.getAttribute('data-setting'), value).fail(function() {
		openSettingsPage();
	});
}
var rulesLoaded = false;

/* Diagnostics - the sidebar shows one section in the pane, or all of them.
 * CSS hides the other sections from #diagnostics_main's data-show, so the choice
 * stays when the page is reloaded. */
function showDiagSection(section)
{
	var main = document.getElementById('diagnostics_main');
	if (!main) {
		return;
	}
	main.setAttribute('data-show', section);
	var name = '';
	$('#diagnostics_nav .diag_nav').each(function() {
		var on = this.getAttribute('data-section') == section;
		$(this).toggleClass('active', on);
		this.setAttribute('aria-pressed', on ? 'true' : 'false');
		if (on && section != 'all') {
			name = this.getAttribute('aria-label');
		}
	});
	$('#diagnostics_section_name').text(name ? ' - ' + name : '');
	var box = document.getElementById('diagnostics_box');
	if (box) {
		box.scrollTop = 0;
	}
}

/* The diagnostics text as the page shows it - every section when all is true,
 * otherwise the section shown ('' when there's nothing) */
function diagnosticsText(all)
{
	var main = document.getElementById('diagnostics_main');
	var box = document.getElementById('diagnostics_box');
	if (!main || !box) {
		return '';
	}
	var shown = main.getAttribute('data-show');
	if (all) {
		main.setAttribute('data-show', 'all');   // innerText leaves out hidden sections
	}
	var text = box.innerText || box.textContent || '';
	if (all) {
		main.setAttribute('data-show', shown);
	}
	text = text.replace(/ /g, ' ').replace(/\s+$/, '');
	return text.replace(/\s/g, '') == '' ? '' : text;
}

/* Copy text to the clipboard, and show on the copy button (.copy_overlay) whether
 * it worked. navigator.clipboard only works on https or localhost - the UI is
 * usually opened over http on the LAN, so otherwise it copies through a hidden
 * textarea. */
function copyToClipboard(text, btn)
{
	if (btn.copyTimer) {
		clearTimeout(btn.copyTimer);
	} else {
		btn.copyTitle = btn.title;   // put back once the result has been shown
	}
	var done = function(ok) {
		$(btn).removeClass('copied copy_failed').addClass(ok ? 'copied' : 'copy_failed');
		btn.title = ok ? 'Copied' : 'Copy failed - select the text and copy it';
		clearTimeout(btn.copyTimer);
		btn.copyTimer = setTimeout(function() {
			$(btn).removeClass('copied copy_failed');
			btn.title = btn.copyTitle;
			btn.copyTimer = null;
		}, 1500);
	};
	var fallback = function() {
		var ta = document.createElement('textarea');
		ta.value = text;
		ta.setAttribute('readonly', '');
		ta.style.position = 'fixed';
		ta.style.top = '0';
		ta.style.left = '-9999px';
		document.body.appendChild(ta);
		ta.select();
		var ok = false;
		try {
			ok = document.execCommand('copy');
		} catch (e) {
			ok = false;
		}
		document.body.removeChild(ta);
		done(ok);
	};
	if (navigator.clipboard && window.isSecureContext) {
		navigator.clipboard.writeText(text).then(function() { done(true); }, fallback);
	} else {
		fallback();
	}
}

/* Diagnostics - copy the text shown in the pane (one section, or all) */
function copyDiagnostics()
{
	var text = diagnosticsText(false);
	if (text != '') {
		copyToClipboard(text, document.getElementById('diagnostics_copy'));
	}
}

/* Diagnostics - save the text of every section to hdhrdvr-diagnostics-<date-time>.log,
 * whichever section is shown (what is on the page, so the checks aren't run again) */
function downloadDiagnostics()
{
	var text = diagnosticsText(true);
	if (text == '') {
		return;
	}
	text = text.replace(/\r?\n/g, '\r\n') + '\r\n';
	var d = new Date();
	var pad = function(n) { return (n < 10 ? '0' : '') + n; };
	var name = 'hdhrdvr-diagnostics-' + d.getFullYear() + pad(d.getMonth() + 1) + pad(d.getDate())
		+ '-' + pad(d.getHours()) + pad(d.getMinutes()) + pad(d.getSeconds()) + '.log';
	var url = URL.createObjectURL(new Blob([text], {type: 'text/plain;charset=utf-8'}));
	var a = document.createElement('a');
	a.href = url;
	a.download = name;
	document.body.appendChild(a);
	a.click();
	document.body.removeChild(a);
	setTimeout(function() { URL.revokeObjectURL(url); }, 1000);
}

/* Recorded page - apply the Record engine and Category filters.
 * A card lists every engine the series is on; when filtered to an engine, only
 * that engine's line is kept. Sections with nothing to show are hidden. */
function filterRecorded() {
	var i, j, shown = 0;
	var engineSel = document.getElementById('recorded_engine_select');
	var groupSel = document.getElementById('recorded_group_select');
	var engine = engineSel ? engineSel.value : 'all';
	var group = groupSel ? groupSel.value : 'all';

	var sections = document.getElementsByClassName('recorded_group');
	for (i = 0; i < sections.length; i++) {
		var inGroup = (group == 'all' || sections[i].getAttribute('data-group') == group);
		var cards = sections[i].getElementsByClassName('recorded_entry');
		var sectionShown = 0;
		for (j = 0; j < cards.length; j++) {
			var engines = ' ' + cards[j].getAttribute('data-engines') + ' ';
			var match = inGroup && (engine == 'all' || engines.indexOf(' ' + engine + ' ') >= 0);
			cards[j].style.display = match ? 'inline-block' : 'none';
			if (match) {
				sectionShown++;
				updateRecordedCard(cards[j], engine);
			}
		}
		sections[i].style.display = (sectionShown > 0) ? 'block' : 'none';
		var sectionCount = sections[i].getElementsByClassName('recorded_group_count')[0];
		if (sectionCount) {
			sectionCount.innerHTML = '(' + sectionShown + ')';
		}
		shown += sectionShown;
	}

	var lines = document.getElementsByClassName('recorded_engine');
	for (i = 0; i < lines.length; i++) {
		lines[i].style.display = (engine == 'all' || lines[i].getAttribute('data-engine') == engine) ? 'block' : 'none';
	}
	// all cards reserve room for the same number of engine lines - only one is shown when filtered
	var list = document.getElementById('recorded_list');
	if (list) {
		list.style.setProperty('--engine-lines', (engine == 'all') ? list.getAttribute('data-engine-lines') : 1);
	}

	var noMatch = document.getElementById('recorded_no_match');
	var msgShown = false;
	var msgs = document.getElementsByClassName('recorded_msg');
	for (i = 0; i < msgs.length; i++) {
		if (msgs[i] == noMatch) {
			continue;
		}
		var key = msgs[i].getAttribute('data-engine');
		var show = (engine == 'all' || key == null || key == engine);
		if (msgs[i].getAttribute('data-filtered-only') && engine == 'all') {
			show = false;
		}
		msgs[i].style.display = show ? 'block' : 'none';
		msgShown = msgShown || show;
	}
	// e.g. an engine with no Sports recordings - say so rather than show an empty page
	if (noMatch) {
		noMatch.style.display = (shown == 0 && !msgShown) ? 'block' : 'none';
	}

	var count = document.getElementById('recorded_count');
	if (count) {
		count.innerHTML = shown + ' series';
	}
	sortRecorded();
}

/* Most recently recorded first within each section, using the date each card
 * currently shows (combined for All engines, else the selected engine's), then by title */
function sortRecorded() {
	var lists = document.getElementsByClassName('recorded_group_list');
	for (var l = 0; l < lists.length; l++) {
		var cards = Array.prototype.slice.call(lists[l].getElementsByClassName('recorded_entry'));
		cards.sort(function(a, b) {
			var diff = (b.sortLatest || 0) - (a.sortLatest || 0);
			if (diff != 0) {
				return diff;
			}
			var ta = a.getElementsByClassName('recorded_title')[0].textContent.toLowerCase();
			var tb = b.getElementsByClassName('recorded_title')[0].textContent.toLowerCase();
			return (ta < tb) ? -1 : (ta > tb) ? 1 : 0;
		});
		for (var i = 0; i < cards.length; i++) {
			lists[l].appendChild(cards[i]);   // re-appending moves the card into order
		}
	}
}

/* Recording count badge and last recorded date: combined figures for All engines,
 * otherwise the figures from the selected engine's line on the card */
function updateRecordedCard(card, engine) {
	var src = card;
	if (engine != 'all') {
		var lines = card.getElementsByClassName('recorded_engine');
		for (var i = 0; i < lines.length; i++) {
			if (lines[i].getAttribute('data-engine') == engine) {
				src = lines[i];
			}
		}
	}
	var count = parseInt(src.getAttribute('data-count'), 10) || 0;
	var last = src.getAttribute('data-last') || '';
	card.sortLatest = parseInt(src.getAttribute('data-latest'), 10) || 0;

	var badge = card.getElementsByClassName('recorded_badge')[0];
	if (badge) {
		badge.style.display = (count > 1) ? 'block' : 'none';
		badge.getElementsByClassName('recorded_badge_count')[0].innerHTML = count;
	}
	var lastLine = card.getElementsByClassName('recorded_last')[0];
	if (lastLine) {
		// hidden rather than removed so the card keeps its size
		lastLine.style.visibility = (last != '') ? 'visible' : 'hidden';
		lastLine.getElementsByClassName('recorded_last_date')[0].textContent = last;
	}
}

/* Latest recordings row - the arrow overlays scroll it by about a screen of cards
 * (one card is kept in view), and each is shown only when there's more that way */
function scrollLatest(dir) {
	var list = document.querySelector('.recorded_latest_list');
	if (!list) {
		return;
	}
	var card = list.querySelector('.recorded_entry');
	var step = card ? card.offsetWidth + 5 : 245;
	list.scrollBy({left: dir * Math.max(step, list.clientWidth - step), behavior: 'smooth'});
}

function updateLatestArrows() {
	var list = document.querySelector('.recorded_latest_list');
	if (!list) {
		return;
	}
	var scroller = list.parentNode;
	var left = scroller.querySelector('.recorded_latest_arrow.left');
	var right = scroller.querySelector('.recorded_latest_arrow.right');
	var max = list.scrollWidth - list.clientWidth;
	// a few pixels of slack - snapping can leave the row a fraction off either end
	left.hidden = !(list.scrollLeft > 5);
	right.hidden = !(max > 5 && list.scrollLeft < max - 5);
}

window.addEventListener('resize', updateLatestArrows);

/* Dashboard engine card image - open the Recordings page filtered to that engine.
 * If the series aren't loaded yet (or a load is in flight) the filter is applied
 * once the page content arrives. */
var pendingRecordedEngine = null;

function selectRecordedEngine(storageId) {
	var sel = document.getElementById('recorded_engine_select');
	if (!sel) {
		return false;
	}
	for (var i = 0; i < sel.options.length; i++) {
		var id = sel.options[i].getAttribute('data-storageid');
		if (id && id.toLowerCase() == storageId.toLowerCase()) {
			sel.selectedIndex = i;
			filterRecorded();
			return true;
		}
	}
	return false;
}

function openEngineRecordings(evt, storageId) {
	if (evt) {
		evt.preventDefault();
	}
	var link = document.querySelector('a.tablink.recorded_page');
	if (!link) {
		return;
	}
	var wasLoaded = recordedLoaded;
	pendingRecordedEngine = storageId;
	openTab({currentTarget: link}, 'recorded_page');
	// already loaded - filter now, otherwise wait for the content (watched below)
	if (wasLoaded && selectRecordedEngine(storageId)) {
		pendingRecordedEngine = null;
	}
}

/* The page is sized to the window, less the status bar fixed along the bottom -
 * keep --status-h (see style.css) matched to the bar, which grows with longer messages */
document.addEventListener('DOMContentLoaded', function() {
	var bar = document.getElementById('statusMessage');
	if (!bar) {
		return;
	}
	var update = function() {
		document.documentElement.style.setProperty('--status-h', (bar.offsetHeight + 4) + 'px');
	};
	update();
	if (window.ResizeObserver) {
		new ResizeObserver(update).observe(bar);
	}
});

document.addEventListener('DOMContentLoaded', function() {
	var box = document.getElementById('recorded_box');
	if (box && window.MutationObserver) {
		new MutationObserver(function() {
			updateLatestArrows();   // new Latest recordings row - show its arrows
			if (pendingRecordedEngine !== null && document.getElementById('recorded_engine_select')) {
				var id = pendingRecordedEngine;
				pendingRecordedEngine = null;
				selectRecordedEngine(id);
			}
		}).observe(box, {childList: true});
	}
});

/* Recordings card image - open the series page (menu tab titled with the series).
 * Limited to the engine the Recordings page is filtered to, if any - except for the
 * Latest recordings cards, which ignore the filters. */
var currentSeries = null;

function openSeries(evt, img) {
	if (evt) {
		evt.preventDefault();
	}
	var card = img.closest('.recorded_entry');
	var link = document.querySelector('a.tablink.series_page');
	if (!card || !link) {
		return;
	}
	var storageId = '';
	var sel = document.getElementById('recorded_engine_select');
	if (!card.classList.contains('recorded_latest') && sel && sel.value != 'all') {
		storageId = sel.options[sel.selectedIndex].getAttribute('data-storageid') || '';
	}
	var title = card.getElementsByClassName('recorded_title')[0].textContent;
	currentSeries = {key: card.getAttribute('data-seriesid'), storageId: storageId};

	link.textContent = title;
	link.title = title;
	link.parentNode.style.display = 'flex';   // title and close button the same height
	if (!link.parentNode.querySelector('.series_close')) {
		// close button next to the title - see closeSeries
		var close = document.createElement('a');
		close.href = '#';
		close.className = 'series_close';
		close.title = 'Close';
		close.innerHTML = '&#x2715;';
		close.onclick = closeSeries;
		link.parentNode.appendChild(close);
	}
	document.getElementById('series_box').innerHTML = "<p class='recorded_msg'>Loading...</p>";
	openTab({currentTarget: link}, 'series_page');
	openSeriesPage(currentSeries.key, currentSeries.storageId);
}

/* Rules page - drag a rule to a new place to change its priority. The drop
 * line shows where it goes; on drop the rule moves straight away and the server
 * asks SiliconDust to put it just after the rule above it (0 = top), then the page
 * is rebuilt from the rules as they now are. One-time rules stay on top. */
var ruleDrag = null;

function ruleEntryAt(evt) {
	var el = evt.target;
	return (el && el.closest) ? el.closest('#rules_list .rule_entry') : null;
}

function clearRuleDrop() {
	var marked = document.querySelectorAll('#rules_list .rule_drop_before, #rules_list .rule_drop_after');
	for (var i = 0; i < marked.length; i++) {
		marked[i].classList.remove('rule_drop_before', 'rule_drop_after');
	}
}

// the rule being dragged goes before or after the one under the pointer
function ruleDropPlace(evt, entry) {
	var r = entry.getBoundingClientRect();
	return (evt.clientY < r.top + r.height / 2) ? 'before' : 'after';
}

document.addEventListener('dragstart', function(evt) {
	var entry = ruleEntryAt(evt);
	if (!entry || entry.getAttribute('draggable') != 'true') {
		return;
	}
	ruleDrag = entry;
	evt.dataTransfer.effectAllowed = 'move';
	evt.dataTransfer.setData('text/plain', entry.getAttribute('data-ruleid'));
	setTimeout(function() { entry.classList.add('rule_dragging'); }, 0);
});

document.addEventListener('dragover', function(evt) {
	if (!ruleDrag) {
		return;
	}
	var list = document.getElementById('rules_list');
	// scroll the list when dragging near its top or bottom
	if (list) {
		var lr = list.getBoundingClientRect();
		if (evt.clientY < lr.top + 40) {
			list.scrollTop -= 12;
		} else if (evt.clientY > lr.bottom - 40) {
			list.scrollTop += 12;
		}
	}
	var entry = ruleEntryAt(evt);
	clearRuleDrop();
	if (!entry || entry == ruleDrag || entry.classList.contains('rule_fixed')) {
		// a one-time rule above can't be passed - dropping on it puts the rule just below it
		if (entry && entry.classList.contains('rule_fixed')) {
			evt.preventDefault();
			entry.classList.add('rule_drop_after');
		}
		return;
	}
	evt.preventDefault();
	evt.dataTransfer.dropEffect = 'move';
	entry.classList.add(ruleDropPlace(evt, entry) == 'before' ? 'rule_drop_before' : 'rule_drop_after');
});

document.addEventListener('drop', function(evt) {
	if (!ruleDrag) {
		return;
	}
	evt.preventDefault();
	var entry = ruleEntryAt(evt);
	var moving = ruleDrag;
	clearRuleDrop();
	if (!entry || entry == moving) {
		return;
	}
	var place = entry.classList.contains('rule_fixed') ? 'after' : ruleDropPlace(evt, entry);
	var list = entry.parentNode;
	var before = moving.previousElementSibling;
	list.insertBefore(moving, place == 'before' ? entry : entry.nextElementSibling);
	if (moving.previousElementSibling == before) {
		return;   // dropped where it already was
	}
	// new place, numbered straight away while the server is asked
	var entries = list.querySelectorAll('.rule_entry');
	for (var i = 0; i < entries.length; i++) {
		entries[i].querySelector('.rule_priority').textContent = i + 1;
	}
	var prev = moving.previousElementSibling;
	var afterId = (prev && !prev.classList.contains('rule_fixed')) ? prev.getAttribute('data-ruleid') : '0';
	list.classList.add('rules_busy');
	moveRulePage(moving.getAttribute('data-ruleid'), afterId);
});

document.addEventListener('dragend', function() {
	if (ruleDrag) {
		ruleDrag.classList.remove('rule_dragging');
		ruleDrag = null;
		clearRuleDrop();
	}
});

/* Rules page Delete button - confirm, then the server deletes the rule and
 * rebuilds the page */
function deleteRule(button) {
	var entry = button.closest('.rule_entry');
	if (!entry) {
		return;
	}
	var title = entry.querySelector('.rule_title').textContent;
	showConfirm('Delete the rule for ' + title + '?',
		'Nothing more will be recorded for it. Recordings already made are kept.',
		'Delete rule', true, function() {
			var list = document.getElementById('rules_list');
			if (list) {
				list.classList.add('rules_busy');   // until the page comes back
			}
			deleteRulePage(entry.getAttribute('data-ruleid'));
		});
}

/* Rules page Edit button - the server fills #rules_dialog with the edit dialog
 * (see openRuleEditor in rules.php); Save sends the settings back */
function editRule(button) {
	var entry = button.closest('.rule_entry');
	if (!entry) {
		return;
	}
	document.getElementById('rules_dialog').innerHTML = "<div class='modal_overlay' id='rule_editor'>"
		+ "<div class='modal_box rule_editor'><p class='modal_message'>Loading the rule and the guide...</p></div></div>";
	openRuleEditor(entry.getAttribute('data-ruleid'));
}

function closeRuleEditor() {
	document.getElementById('rules_dialog').innerHTML = '';
}

/* The Teams list in a dialog (prefix re = Edit, nr = New Rule) - All teams, or the
 * teams ticked. Returns {only, teams: [names]} (only is false for All teams). */
function ruleTeamsChoice(prefix) {
	var checks = document.querySelectorAll('.' + prefix + '_team_check');
	var choice = {only: false, teams: []};
	for (var i = 0; i < checks.length; i++) {
		if (checks[i].checked) {
			choice.teams.push(checks[i].value);
		}
	}
	choice.only = choice.teams.length > 0;
	return choice;
}

/* A box in the Teams list was changed: ticking a team unticks All teams, ticking
 * All teams unticks the teams, and with no team ticked All teams is ticked again
 * (so the choice is never empty) */
function ruleTeamsPick(box, prefix) {
	var all = document.querySelector('.' + prefix + '_team_all');
	var checks = document.querySelectorAll('.' + prefix + '_team_check');
	var i;
	if (box == all) {
		for (i = 0; i < checks.length; i++) {
			checks[i].checked = false;
		}
		all.checked = true;
		return;
	}
	var any = false;
	for (i = 0; i < checks.length; i++) {
		if (checks[i].checked) {
			any = true;
		}
	}
	if (all) {
		all.checked = !any;
	}
}

// does an airing's row (data-teams "A|B") have one of the teams chosen?
function ruleRowHasTeam(row, choice) {
	if (!choice.only) {
		return true;
	}
	var teams = (row.getAttribute('data-teams') || '').split('|');
	for (var i = 0; i < teams.length; i++) {
		if (teams[i] != '' && choice.teams.indexOf(teams[i]) >= 0) {
			return true;
		}
	}
	return false;
}

// channel boxes only count with "Only these channels"; Save needs at least one then.
// The episodes listed follow the teams chosen.
function updateRuleEditor() {
	var teams = ruleTeamsChoice('re');
	var rows = document.querySelectorAll('#re_form .re_upcoming_list tbody tr');
	for (var r = 0; r < rows.length; r++) {
		rows[r].classList.toggle('nr_hidden', !ruleRowHasTeam(rows[r], teams));
	}
	var only = document.querySelector("#re_form input[name=re_channels][value=only]");
	var checks = document.querySelectorAll('#re_form .re_channel_check');
	var any = false;
	for (var i = 0; i < checks.length; i++) {
		checks[i].disabled = !(only && only.checked);
		if (checks[i].checked) {
			any = true;
		}
	}
	var save = document.getElementById('re_save');
	if (save) {
		save.disabled = !!(only && only.checked && !any);
		save.title = save.disabled ? 'Choose at least one channel' : '';
	}
}

function saveRuleEditor() {
	var editor = document.getElementById('rule_editor');
	var save = document.getElementById('re_save');
	if (!editor || !save || save.disabled) {
		return;
	}
	var channels = [];
	var only = document.querySelector("#re_form input[name=re_channels][value=only]");
	if (only && only.checked) {
		var checks = document.querySelectorAll('#re_form .re_channel_check');
		for (var i = 0; i < checks.length; i++) {
			if (checks[i].checked) {
				channels.push(checks[i].value);
			}
		}
	}
	var recent = document.querySelector("#re_form input[name=re_recent]:checked");
	save.disabled = true;
	save.textContent = 'Saving...';
	saveRulePage(editor.getAttribute('data-ruleid'), channels.join('|'), recent ? recent.value : '0',
		document.getElementById('re_start').value, document.getElementById('re_end').value,
		ruleTeamsChoice('re').teams.join('|'));
}

// a newly opened dialog - set up its channel boxes and focus the first choice;
// Esc closes it
document.addEventListener('DOMContentLoaded', function() {
	var dialog = document.getElementById('rules_dialog');
	if (dialog && window.MutationObserver) {
		new MutationObserver(function() {
			if (document.getElementById('re_form') && !dialog.getAttribute('data-ready')) {
				dialog.setAttribute('data-ready', '1');
				updateRuleEditor();
				var first = document.querySelector('#re_form input, #re_form select');
				if (first) {
					first.focus();
				}
			} else if (!document.getElementById('re_form')) {
				dialog.removeAttribute('data-ready');
			}
		}).observe(dialog, {childList: true, subtree: true});
	}
});
document.addEventListener('keydown', function(evt) {
	if (evt.key == 'Escape' && document.getElementById('rule_editor')) {
		closeRuleEditor();
	}
	// (Esc in a search box with text in it just clears the text)
	if (evt.key == 'Escape' && document.getElementById('rule_new')
			&& !(evt.target && evt.target.id == 'nr_search' && evt.target.value != '')) {
		closeNewRule();
	}
});

/* Rules page + New Rule - the server fills #rules_dialog with the New Rule dialog
 * (see newRuleOpen in rules.php): search the guide, pick a show, then record all
 * of it, the current season, or single airings. It stays open until closed. */
function newRule() {
	document.getElementById('rules_dialog').innerHTML = "<div class='modal_overlay' id='rule_new'>"
		+ "<div class='modal_box rule_editor'><p class='modal_message'>Loading...</p></div></div>";
	newRuleOpen().done(function() {
		var search = document.getElementById('nr_search');
		if (search) {
			search.focus();
		}
	});
}

function closeNewRule() {
	document.getElementById('rules_dialog').innerHTML = '';
}

function newRuleMessage(text, error) {
	var msg = document.getElementById('nr_message');
	if (msg) {
		msg.innerHTML = '';
		var span = document.createElement('span');
		span.className = error ? 're_error' : 'nr_ok';
		span.textContent = text;
		msg.appendChild(span);
	}
}

function searchNewRule() {
	var search = document.getElementById('nr_search');
	var button = document.getElementById('nr_search_button');
	var term = search ? search.value.trim() : '';
	if (term.length < 2) {
		newRuleMessage('Type at least two letters to search for', true);
		return;
	}
	newRuleMessage('', false);
	document.getElementById('nr_results').innerHTML = "<p class='re_note'>Searching the guide...</p>";
	document.getElementById('nr_series').innerHTML = '';
	newRuleBusy(false);
	button.disabled = true;
	newRuleSearch(term).always(function() {
		button.disabled = false;
	});
}

function pickNewRuleSeries(item) {
	var items = document.querySelectorAll('#nr_results .nr_item');
	for (var i = 0; i < items.length; i++) {
		items[i].classList.toggle('nr_item_selected', items[i] == item);
		items[i].setAttribute('aria-pressed', items[i] == item ? 'true' : 'false');
	}
	var series = document.getElementById('nr_series');
	series.innerHTML = "<p class='re_note'>Getting the upcoming airings...</p>";
	newRuleMessage('', false);
	newRuleBusy(false);
	newRuleSeries(item.getAttribute('data-seriesid'), item.getAttribute('data-title')).done(function() {
		newRuleEndPadding();
		series.scrollIntoView({block: 'nearest', behavior: 'smooth'});
	}).always(function() {
		newRuleBusy(false);
	});
}

/* A show was picked - its end padding: the Sports end padding setting for sports
 * (games with teams), otherwise End padding. A box changed by hand is left as it is. */
function newRuleEndPadding() {
	var selected = document.getElementById('nr_selected');
	var box = document.getElementById('nr_end');
	var note = document.getElementById('nr_end_note');
	if (!selected || !box || box.getAttribute('data-edited')) {
		return;
	}
	box.value = selected.getAttribute('data-end-padding');
	if (note) {
		note.textContent = selected.getAttribute('data-sports') == '1' ? '(sports - games often run over)' : '';
	}
}

// the padding boxes as whole seconds in range, or null (with a message) if they aren't
function newRulePadding() {
	var boxes = [document.getElementById('nr_start'), document.getElementById('nr_end')];
	var values = [];
	for (var i = 0; i < boxes.length; i++) {
		var box = boxes[i];
		var text = box.value.trim();
		if (!/^\d+$/.test(text) || +text > +box.max) {
			newRuleMessage((i == 0 ? 'Start' : 'End') + ' padding must be whole seconds from 0 to ' + box.max, true);
			box.focus();
			return null;
		}
		values.push(text);
	}
	return values;
}

// While a rule is being added nothing can be sent again. Save needs a show picked.
function newRuleBusy(busy) {
	var buttons = document.querySelectorAll('#nr_series .nr_once');
	for (var i = 0; i < buttons.length; i++) {
		buttons[i].disabled = busy;
	}
	var save = document.getElementById('nr_save');
	if (save) {
		var picked = !!document.getElementById('nr_selected');
		var recorded = newRuleRecorded();
		save.disabled = busy || !picked || recorded;
		save.title = recorded ? 'An airing has been recorded - Close to finish'
			: (picked ? '' : 'Search for a show and pick one first');
	}
}

// once an airing has been recorded the dialog is done: Save is off and Cancel is Close
function newRuleRecorded() {
	var dialog = document.getElementById('rule_new');
	return !!(dialog && dialog.getAttribute('data-recorded'));
}

function newRuleSetRecorded() {
	var dialog = document.getElementById('rule_new');
	var cancel = document.getElementById('nr_cancel');
	if (dialog) {
		dialog.setAttribute('data-recorded', '1');
	}
	if (cancel) {
		cancel.textContent = 'Close';
	}
}

// Enter in the dialog presses Cancel/Close - except in the search box (it searches)
// and on a button or list (they handle Enter themselves)
function newRuleKey(evt) {
	if (evt.key != 'Enter' || evt.defaultPrevented) {
		return;
	}
	var target = evt.target;
	if (target && (target.id == 'nr_search' || target.tagName == 'BUTTON' || target.tagName == 'SELECT')) {
		return;
	}
	evt.preventDefault();
	var cancel = document.getElementById('nr_cancel');
	if (cancel) {
		cancel.click();
	}
}

// the value of a row of radio buttons in the New Rule dialog (Record, Priority)
function newRuleRadio(name, fallback) {
	var checked = document.querySelector('#nr_series input[name=' + name + ']:checked');
	return checked ? checked.value : fallback;
}

// Save - a rule for the show picked, with the Record, Channel, Teams and Priority
// choices. The dialog closes when it is added (it stays open with the reason if it isn't).
function saveNewRule() {
	var selected = document.getElementById('nr_selected');
	var save = document.getElementById('nr_save');
	var padding = newRulePadding();
	if (!selected || !padding || !save || save.disabled) {
		return;
	}
	newRuleBusy(true);
	save.textContent = 'Saving...';
	newRuleMessage('Adding the rule...', false);
	newRuleAdd(selected.getAttribute('data-seriesid'), selected.getAttribute('data-title'),
			newRuleRadio('nr_record', 'all'), newRuleChannel(), ruleTeamsChoice('nr').teams.join('|'),
			newRuleRadio('nr_priority', 'lowest'), padding[0], padding[1]).always(function() {
		save.textContent = 'Save';
		newRuleBusy(false);
	});
}

// A record button on an airing - recorded once straight away; the dialog stays open
function addNewRuleOnce(button) {
	var selected = document.getElementById('nr_selected');
	var padding = newRulePadding();
	if (!selected || !padding) {
		return;
	}
	// the airings are rebuilt - keep the Record, Priority and Teams choices
	var radios = {nr_record: newRuleRadio('nr_record', ''), nr_priority: newRuleRadio('nr_priority', '')};
	var teams = ruleTeamsChoice('nr');
	newRuleBusy(true);
	newRuleMessage('Adding the recording...', false);
	newRuleAddOnce(selected.getAttribute('data-seriesid'), selected.getAttribute('data-title'),
		button.getAttribute('data-start'), button.getAttribute('data-channel'), padding[0], padding[1],
		newRuleChannel()).done(function() {
		if (document.querySelector('#nr_message .nr_ok')) {
			newRuleSetRecorded();
		}
	}).always(function() {
		for (var name in radios) {
			var keep = document.querySelector('#nr_series input[name=' + name + "][value='" + radios[name] + "']");
			if (keep) {
				keep.checked = true;
			}
		}
		var checks = document.querySelectorAll('#nr_series .nr_team_check');
		for (var i = 0; i < checks.length; i++) {
			checks[i].checked = teams.teams.indexOf(checks[i].value) >= 0;
		}
		var all = document.querySelector('#nr_series .nr_team_all');
		if (all) {
			all.checked = !teams.only;
		}
		filterNewRule();
		newRuleBusy(false);
	});
}

// the channel chosen for the rule ('' = all channels)
function newRuleChannel() {
	var select = document.getElementById('nr_channel');
	return select ? select.value : '';
}

// Channel or Teams chosen - only the airings on that channel, with those teams, are listed
function filterNewRule() {
	var channel = newRuleChannel();
	var teams = ruleTeamsChoice('nr');
	var rows = document.querySelectorAll('#nr_series .nr_upcoming_list tbody tr');
	for (var i = 0; i < rows.length; i++) {
		var shown = (channel == '' || rows[i].getAttribute('data-channel') == channel) && ruleRowHasTeam(rows[i], teams);
		rows[i].classList.toggle('nr_hidden', !shown);
	}
	newRuleBusy(false);
}

/* Series tab close button - hide the tab, drop the series and go back to Recordings */
function closeSeries(evt) {
	if (evt) {
		evt.preventDefault();
	}
	var link = document.querySelector('a.tablink.series_page');
	if (link) {
		link.parentNode.style.display = 'none';
		link.className = link.className.replace(' active', '');
	}
	currentSeries = null;
	document.getElementById('series_box').innerHTML = '';
	var rec = document.querySelector('a.tablink.recorded_page');
	if (rec) {
		openTab({currentTarget: rec}, 'recorded_page');
		rec.focus();
	}
}

/* Series page engine filter - fetch the series again for the chosen engine (or All),
 * so the header figures follow it; Reload keeps the choice */
function changeSeriesEngine(sel) {
	if (!currentSeries) {
		return;
	}
	currentSeries.storageId = sel.value;
	openSeriesPage(currentSeries.key, currentSeries.storageId);
}

/* Confirmation dialog, centred in the window over a dimmed page (instead of the
 * browser's confirm box). Cancel has the focus so Enter can't delete by accident;
 * Esc or a click outside the dialog cancels. onOk runs only if confirmed. */
function showConfirm(title, message, okLabel, danger, onOk) {
	var old = document.getElementById('dvrui_modal');
	if (old) {
		old.parentNode.removeChild(old);
	}
	var overlay = document.createElement('div');
	overlay.id = 'dvrui_modal';
	overlay.className = 'modal_overlay';
	var box = document.createElement('div');
	box.className = 'modal_box';
	box.setAttribute('role', 'dialog');
	box.setAttribute('aria-modal', 'true');
	var h = document.createElement('h3');
	h.className = 'modal_title';
	h.textContent = title;
	var p = document.createElement('p');
	p.className = 'modal_message';
	p.textContent = message;
	var buttons = document.createElement('div');
	buttons.className = 'modal_buttons';
	var cancel = document.createElement('button');
	cancel.type = 'button';
	cancel.className = 'modal_button';
	cancel.textContent = 'Cancel';
	var ok = document.createElement('button');
	ok.type = 'button';
	ok.className = 'modal_button modal_ok' + (danger ? ' modal_danger' : '');
	ok.textContent = okLabel;
	buttons.appendChild(cancel);
	buttons.appendChild(ok);
	box.appendChild(h);
	box.appendChild(p);
	box.appendChild(buttons);
	overlay.appendChild(box);

	var close = function() {
		document.removeEventListener('keydown', onKey, true);
		if (overlay.parentNode) {
			overlay.parentNode.removeChild(overlay);
		}
	};
	var onKey = function(e) {
		if (e.key == 'Escape') {
			e.preventDefault();
			close();
		} else if (e.key == 'Tab') {
			// keep the focus on the two buttons
			e.preventDefault();
			(document.activeElement == cancel ? ok : cancel).focus();
		}
	};
	cancel.onclick = close;
	ok.onclick = function() {
		close();
		onOk();
	};
	overlay.onclick = function(e) {
		if (e.target == overlay) {
			close();
		}
	};
	document.addEventListener('keydown', onKey, true);
	document.body.appendChild(overlay);
	cancel.focus();
}

/* Series page - show the chosen season's (or year's) episodes */
function selectSeason(evt, link) {
	if (evt) {
		evt.preventDefault();
	}
	var group = link.getAttribute('data-group');
	var links = document.querySelectorAll('#series_seasons a.series_season');
	for (var i = 0; i < links.length; i++) {
		links[i].classList.toggle('active', links[i] == link);
	}
	var lists = document.getElementsByClassName('series_episodes');
	for (var j = 0; j < lists.length; j++) {
		lists[j].style.display = (lists[j].getAttribute('data-group') == group) ? 'block' : 'none';
	}
	// selection is for the season shown - clear it so hidden episodes aren't acted on
	var checks = document.querySelectorAll('#series_episode_list .series_ep_check');
	for (var k = 0; k < checks.length; k++) {
		checks[k].checked = false;
	}
	updateSeriesSelection();
}

/* Series page command bar - the checkboxes of the episodes in the season shown */
function visibleSeriesChecks() {
	var result = [];
	var lists = document.getElementsByClassName('series_episodes');
	for (var i = 0; i < lists.length; i++) {
		if (lists[i].style.display != 'none') {
			var checks = lists[i].getElementsByClassName('series_ep_check');
			for (var j = 0; j < checks.length; j++) {
				result.push(checks[j]);
			}
		}
	}
	return result;
}

/* The selected episodes, as [{id, storageId}] - for the command bar actions */
function selectedSeriesEpisodes() {
	var result = [];
	var checks = visibleSeriesChecks();
	for (var i = 0; i < checks.length; i++) {
		if (checks[i].checked) {
			result.push({id: checks[i].getAttribute('data-id'), storageId: checks[i].getAttribute('data-storageid')});
		}
	}
	return result;
}

function selectAllSeriesEpisodes(box) {
	var checks = visibleSeriesChecks();
	for (var i = 0; i < checks.length; i++) {
		checks[i].checked = box.checked;
	}
	updateSeriesSelection();
}

/* Select all shows ticked / part ticked / clear, plus how many are selected */
function updateSeriesSelection() {
	var checks = visibleSeriesChecks();
	var selected = 0;
	for (var i = 0; i < checks.length; i++) {
		if (checks[i].checked) {
			selected++;
		}
		checks[i].closest('.series_episode').classList.toggle('selected', checks[i].checked);
	}
	var all = document.getElementById('series_select_all');
	if (all) {
		all.checked = (checks.length > 0 && selected == checks.length);
		all.indeterminate = (selected > 0 && selected < checks.length);
	}
	var count = document.getElementById('series_selected_count');
	if (count) {
		count.textContent = (selected > 0) ? selected + ' of ' + checks.length + ' selected' : '';
	}

	// actions need a selection; the watched button sets unwatched when every
	// selected episode is already watched
	var buttons = document.querySelectorAll('#series_actions .series_action');
	for (var b = 0; b < buttons.length; b++) {
		buttons[b].disabled = (selected == 0);
	}
	var watchedBtn = document.getElementById('series_act_watched');
	if (watchedBtn) {
		var allWatched = selected > 0;
		for (var w = 0; w < checks.length; w++) {
			if (checks[w].checked && checks[w].getAttribute('data-watched') != '1') {
				allWatched = false;
			}
		}
		watchedBtn.setAttribute('data-action', allWatched ? 'unwatched' : 'watched');
		watchedBtn.textContent = allWatched ? 'Set unwatched' : 'Set watched';
	}
}

/* Command bar action on the selected episodes - the server sends the commands to
 * each recording's engine and returns the page again (same engine filter and season) */
function seriesAction(action) {
	var selected = selectedSeriesEpisodes();
	if (!currentSeries || selected.length == 0) {
		return;
	}
	var n = selected.length + ' recording' + (selected.length == 1 ? '' : 's');
	if (action == 'remove') {
		showConfirm('Remove ' + n + '?',
			'The file(s) will be deleted, but can be recorded again if they air again.',
			'Remove', false, function() { runSeriesAction(action, selected); });
		return;
	}
	if (action == 'kill') {
		showConfirm('Delete ' + n + ' permanently?',
			'The file(s) will be deleted and will NOT be recorded again.',
			'Delete permanently', true, function() { runSeriesAction(action, selected); });
		return;
	}
	runSeriesAction(action, selected);
}

function runSeriesAction(action, selected) {
	var items = [];
	for (var i = 0; i < selected.length; i++) {
		items.push(selected[i].storageId + ':' + selected[i].id);
	}
	var active = document.querySelector('#series_seasons a.series_season.active');
	var group = active ? active.getAttribute('data-group') : '';
	var buttons = document.querySelectorAll('#series_actions .series_action');
	for (var b = 0; b < buttons.length; b++) {
		buttons[b].disabled = true;   // until the page comes back
	}
	// the Recordings page counts change too - fetch them again next time it's shown
	recordedLoaded = false;
	seriesActionPage(currentSeries.key, currentSeries.storageId, action, items.join(','), group);
}

/* Reload button - refresh the page being shown without changing tabs. The
 * SiliconDust server cache is cleared first, so the page gets fresh answers
 * (and the page reloads even if clearing it failed). */
function reloadPage(evt) {
	if (activeTab != '') {
		var tab = activeTab;
		clearApiCache().always(function() {
			loadTab(tab, true);
		});
	}
}

function openTab(evt, tabname) {
	var i, tabcontent, tablinks, tab;
	// get elements with class="tabcontent" and hide
	tabcontent = document.getElementsByClassName("tabcontent");
	for (i=-0; i < tabcontent.length; i++) {
		tabcontent[i].style.display = "none";
	}
	
	// get elements with class="tablink" and remove the active
	tablinks = document.getElementsByClassName("tablink");
	for (i=0; i < tablinks.length; i++) {
		tablinks[i].className = tablinks[i].className.replace(" active", "");
	}
	
	// load the page
	activeTab = tabname;
	loadTab(tabname, false);

	//show the tablinks
  tab = document.getElementById(tabname)
  if (tab != null) {
  	tab.style.display = "block";
  }
	evt.currentTarget.className += " active";
}


function openLogFile(evt, value)
{
	// get elements with class="tablink" and remove the active
	tablinks = document.getElementsByClassName("loglink");
	for (i=0; i < tablinks.length; i++) {
		tablinks[i].className = tablinks[i].className.replace(" active", "");
	}

	getLogFile(value);
	
	evt.currentTarget.className += " active";
}

function deleteLogFile(value)
{
	rmLogFile(value);
}

/* Log viewer filter - the check boxes in #logfile_filter show/hide the entries
 * of each log type. Types switched off stay off when another log file is opened. */
var hiddenLogTypes = {};

function filterLogTypes()
{
	var checks = document.querySelectorAll('#logfile_filter .logfilter_check');
	var hide = {};
	var shown = 0;
	for (var i = 0; i < checks.length; i++) {
		if (checks[i].checked) {
			shown++;
			delete hiddenLogTypes[checks[i].value];
		} else {
			hide[checks[i].value] = true;
			hiddenLogTypes[checks[i].value] = true;
		}
	}
	var lines = document.querySelectorAll('#logfile_box .logline');
	for (var j = 0; j < lines.length; j++) {
		lines[j].style.display = hide[lines[j].getAttribute('data-logtype')] ? 'none' : '';
	}
	var all = document.getElementById('logfilter_all');
	if (all) {
		all.checked = (shown == checks.length);
		all.indeterminate = (shown > 0 && shown < checks.length);
	}
	// the download button says when it will only save the types shown
	var btn = document.querySelector('#logfile_name .logfile_download');
	if (btn) {
		var filtered = (shown < checks.length);
		btn.title = filtered ? 'Download the log entries shown (the types switched on)' : 'Download the log file';
		btn.querySelector('.logfile_download_label').textContent = filtered ? 'Download filtered' : 'Download';
		btn.disabled = (checks.length > 0 && shown == 0);
	}
	var copy = document.getElementById('logfile_copy');
	if (copy) {
		var title = (shown < checks.length) ? 'Copy the log entries shown (the types switched on) to the clipboard'
			: 'Copy the log entries shown to the clipboard';
		if (copy.copyTimer) {
			copy.copyTitle = title;   // showing Copied - put this back afterwards
		} else {
			copy.title = title;
		}
	}
}

/* Copy the log entries shown (the types switched on in the filter bar) to the
 * clipboard - one line per entry, as in the log file */
function copyLogFile()
{
	var lines = document.querySelectorAll('#logfile_box .logline');
	var text = [];
	for (var i = 0; i < lines.length; i++) {
		if (lines[i].style.display == 'none') {
			continue;
		}
		var parts = [];
		var spans = lines[i].getElementsByTagName('span');
		for (var j = 0; j < spans.length; j++) {
			var part = spans[j].textContent.trim();
			if (part != '') {
				parts.push(part);
			}
		}
		if (parts.length > 0) {
			text.push(parts.join(' '));
		}
	}
	if (text.length > 0) {
		copyToClipboard(text.join('\n'), document.getElementById('logfile_copy'));
	}
}

/* Download the open log file - the whole file, or only the lines of the
 * log types switched on in the filter bar when some are switched off */
function downloadLogFile(btn)
{
	var checks = document.querySelectorAll('#logfile_filter .logfilter_check');
	var types = [];
	for (var i = 0; i < checks.length; i++) {
		if (checks[i].checked) {
			types.push(checks[i].value);
		}
	}
	if (checks.length > 0 && types.length == 0) {
		return;
	}
	var url = 'logdownload.php?file=' + encodeURIComponent(btn.getAttribute('data-file'));
	if (types.length < checks.length) {
		url += '&types=' + encodeURIComponent(types.join(','));
	}
	var a = document.createElement('a');
	a.href = url;
	a.setAttribute('download', '');
	document.body.appendChild(a);
	a.click();
	document.body.removeChild(a);
}

function selectAllLogTypes(checked)
{
	var checks = document.querySelectorAll('#logfile_filter .logfilter_check');
	for (var i = 0; i < checks.length; i++) {
		checks[i].checked = checked;
	}
	filterLogTypes();
}

// a newly loaded log file gets a new filter bar - re-apply the types switched off
document.addEventListener('DOMContentLoaded', function() {
	var bar = document.getElementById('logfile_filter');
	if (bar && window.MutationObserver) {
		new MutationObserver(function() {
			var checks = bar.querySelectorAll('.logfilter_check');
			if (checks.length == 0) {
				return;
			}
			for (var i = 0; i < checks.length; i++) {
				if (hiddenLogTypes[checks[i].value]) {
					checks[i].checked = false;
				}
			}
			filterLogTypes();
		}).observe(bar, {childList: true});
	}
});

function updateServer() {
	var port = document.getElementById('Port').value;
	var path = document.getElementById('RecordPath').value;
	var streams = document.getElementById('RecordStreamsMax').value;
	var beta = document.getElementById('BetaEngine').value;
	updateServerConfig(port, path, streams, beta);
}


/* Dashboard - check for a newer record engine, and update to it. Nothing
 * happens when the engine is the latest. With a newer one it updates straight
 * away when the engine log shows nothing recording or about to start, otherwise
 * it asks first
 * (the update restarts the engine, which stops any recordings in progress). */
var engineUpdating = false;

function setEngineBusy(busy) {
	engineUpdating = busy;
	var link = document.querySelector('a.dashboard_ctrl_link_engine');
	if (link) {
		link.classList.toggle('busy', busy);
	}
}

/* Anything recording, about to record, or can't it be told - then ask first */
function engineUpdateNeedsConfirm(d) {
	return !d.recordingsKnown || d.recordings.length > 0 || (d.upcoming && d.upcoming.length > 0);
}

function engineRecordingsMessage(d) {
	var parts = [];
	if (!d.recordingsKnown) {
		parts.push('Could not tell from the engine log whether anything is recording ('
			+ d.recordingsReason + ').');
	} else if (d.recordings.length > 0) {
		parts.push((d.recordings.length == 1 ? '1 recording is' : d.recordings.length + ' recordings are')
			+ ' in progress:\n' + d.recordings.join('\n'));
	}
	if (d.upcoming && d.upcoming.length > 0) {
		parts.push((d.upcoming.length == 1 ? '1 recording starts' : d.upcoming.length + ' recordings start')
			+ ' about now:\n' + d.upcoming.join('\n'));
	}
	return 'Record engine ' + d.latest + ' is available (running ' + d.current + ').\n\n' + parts.join('\n\n')
		+ '\n\nUpdating restarts the record engine - recordings in progress or starting during'
		+ ' the update will be interrupted.';
}

function confirmEngineUpdate(d) {
	setEngineBusy(false);
	showConfirm('Update the record engine?', engineRecordingsMessage(d), 'Update anyway', true, function() {
		runEngineUpdate('1');
	});
}

function checkEngine(evt) {
	if (evt) {
		evt.preventDefault();
	}
	if (engineUpdating) {
		return;
	}
	setEngineBusy(true);
	dvruiToast('Checking for a newer record engine...', 'info');
	checkEngineUpdate().done(function(reply) {
		var d = (reply && reply.ok) ? reply.data : null;
		if (!d || d.state != 'newer') {
			// latest already, or the check failed - the message says which
			setEngineBusy(false);
		} else if (!engineUpdateNeedsConfirm(d)) {
			runEngineUpdate('0');
		} else {
			confirmEngineUpdate(d);
		}
	}).fail(function() {
		setEngineBusy(false);
	});
}

function runEngineUpdate(confirmed) {
	setEngineBusy(true);
	dvruiToast('Updating the record engine...', 'info');
	updateEngine(confirmed).done(function(reply) {
		var d = (reply && reply.ok) ? reply.data : null;
		if (d && d.state == 'updating') {
			waitForEngine(d.latest, d.current);
		} else if (d && d.state == 'recording') {
			// something started recording since the check
			confirmEngineUpdate(d);
		} else {
			setEngineBusy(false);
		}
	}).fail(function() {
		setEngineBusy(false);
	});
}

/* The engine downloads and installs the update as it restarts - wait for it
 * to report the new version (up to 3 minutes) */
function waitForEngine(expected, previous) {
	var tries = 0;
	dvruiLoadingShow();
	var done = function(msg, ok) {
		dvruiLoadingHide();
		setEngineBusy(false);
		dvruiToast(msg, ok ? 'ok' : 'error');
		// the dashboard shows the new version (and the status bar the engine running)
		if (ok && activeTab == 'dashboard_page') {
			openDashboard();
		}
	};
	var poll = function() {
		tries++;
		engineVersion().done(function(reply) {
			var version = (reply && reply.ok && reply.data) ? reply.data.version : '';
			var shown = document.getElementById('dvr_engine_version');
			if (shown && version) {
				shown.textContent = version;
			}
			if (version == expected) {
				done('Record engine updated from ' + previous + ' to ' + expected, true);
			} else if (tries >= 36) {
				done('The record engine has not reported version ' + expected + ' yet (it reports '
					+ (version || 'nothing') + ') - check the engine log');
			} else {
				if (tries == 1) {
					dvruiToast('Record engine restarting to update to ' + expected + '...', 'info');
				}
				setTimeout(poll, 5000);
			}
		}).fail(function() {
			if (tries >= 36) {
				done('The record engine has not come back after the update - check the engine log');
			} else {
				setTimeout(poll, 5000);
			}
		});
	};
	setTimeout(poll, 5000);
}
