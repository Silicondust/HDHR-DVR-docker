/******************************************************************************
 * @file hdhomerunedvrui.js
 * @description
 * @version 1.0
 * @author Rik Dunphy
 *
 * Copyright 2008 (c) Rik Dunphy
 * You are free to use this file in your own programs, but I do not accept
 * any liability for errors or problems arising from it's use. 
 *****************************************************************************/


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
	}
	if (tabname == 'series_page') {
		// loaded by openSeries - Reload fetches the same series again
		if (force && currentSeries) {
			openSeriesPage(currentSeries.key, currentSeries.storageId);
		}
	}
	if (tabname == 'diagnostics_page') {
		openDiagnosticsPage();
	}
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
			if (pendingRecordedEngine !== null && document.getElementById('recorded_engine_select')) {
				var id = pendingRecordedEngine;
				pendingRecordedEngine = null;
				selectRecordedEngine(id);
			}
		}).observe(box, {childList: true});
	}
});

/* Recordings card image - open the series page (menu tab titled with the series).
 * Limited to the engine the Recordings page is filtered to, if any. */
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
	if (sel && sel.value != 'all') {
		storageId = sel.options[sel.selectedIndex].getAttribute('data-storageid') || '';
	}
	var title = card.getElementsByClassName('recorded_title')[0].textContent;
	currentSeries = {key: card.getAttribute('data-seriesid'), storageId: storageId};

	link.textContent = title;
	link.title = title;
	link.parentNode.style.display = 'block';
	document.getElementById('series_box').innerHTML = "<p class='recorded_msg'>Loading...</p>";
	openTab({currentTarget: link}, 'series_page');
	openSeriesPage(currentSeries.key, currentSeries.storageId);
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

/* Reload button - refresh the page being shown without changing tabs */
function reloadPage(evt) {
	if (activeTab != '') {
		loadTab(activeTab, true);
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

/* Set the status message */
function setStatus(msg)
{
	isStatusIdle = 0;
	if(msg == '' || msg == null || msg == undefined)
	{
		isStatusIdle = 1;
		msg = "Idle.";
	}
	document.getElementById('statusMessage').innerHTML = msg;
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

