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

function updateServer() {
	var port = document.getElementById('Port').value;
	var path = document.getElementById('RecordPath').value;
	var streams = document.getElementById('RecordStreamsMax').value;
	var beta = document.getElementById('BetaEngine').value;
	updateServerConfig(port, path, streams, beta);
}

