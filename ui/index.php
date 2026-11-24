<?php
/*
 * ui/index.php
 *
 * Copyright (c) 2020 Rik Dunphy
 * Copyright (c) 2026 Silicondust USA Inc.
 * Released under the MIT License - see LICENSE
 */

	require_once("vars.php");
	require_once("includes/dvrui_debug.php");
	require_once("includes/dvrui_common.php");

	require_once("includes/dvrui_ajax.php");

	require_once("includes/dvrui_hdhrbintools.php");
	require_once("statusmessage.php");
	require_once("dashboard.php");
	require_once("includes/dvrui_engine_update.php");
	require_once("diagnostics.php");
	require_once("settings.php");
	require_once("recorded.php");
	require_once("series.php");
	require_once("rules.php");

	/* The PHP functions the page can call (see dvruiCall in hdhomerundvr_ui.js) */
	$exported = array('getLogFile', 'updateServerConfig', 'openDashboard', 'openDiagnosticsPage',
		'checkEngineUpdate', 'updateEngine', 'engineVersion', 'openSettingsPage', 'saveSetting');
	// The Recordings and series pages (and their actions) are only in the full UI -
	// DVRUI_ENABLE=minimal is the Dashboard and Diagnostics. Functions that aren't
	// exported can't be called, so they aren't just hidden.
	$fullUI = dvruiFullUI();
	if ($fullUI) {
		array_push($exported, 'openRecordedPage', 'openSeriesPage', 'seriesActionPage',
			'openRulesPage', 'moveRulePage', 'deleteRulePage', 'openRuleEditor', 'saveRulePage');
	}

	/* An ajax call runs its function and exits here */
	dvruiAjaxDispatch($exported);

	// Prep data for the page
	error_log( "Get latest Status" );
	$statusmsg = getLatestHDHRStatus();

	// Get HDHR Version
	error_log( "Get HDHR DVR version" );
	$DVRBin = new DVRUI_HDHRbintools();
	$DVRBinVersion = $DVRBin->get_DVR_version();
	
	//Build navigation menu for pages
	error_log( "Build Navigation Pages" );
	// series_page stays hidden until a series is opened from Recordings - it then
	// takes the series title (see openSeries). Rules follows it, so it comes after
	// Recordings or the series tab, whichever is showing.
	if ($fullUI) {
		$pageTitles = array('Dashboard', 'Recordings', '', 'Rules', '.', '.');
		$pageNames = array('dashboard_page', 'recorded_page', 'series_page', 'rules_page', 'diagnostics_page', 'settings_page');
	} else {
		$pageTitles = array('Dashboard', '.', '.');
		$pageNames = array('dashboard_page', 'diagnostics_page', 'settings_page');
	}
	$menu_data = dvruiTemplate('pagemenu.html');
	$menuEntries = '';
	$firstPage = true;
	for ($i=0; $i < count($pageNames); $i++) {
		$menuEntry = str_replace('<!-- dvrui_menu_pagename-->',$pageNames[$i],dvruiTemplate('pagemenu_entry.html'));
		$menuEntry = str_replace('<!-- dvrui_menu_pagetitle-->',$pageTitles[$i],$menuEntry);
		if ($firstPage) {
			$menuEntry = str_replace('<!--dvrui_default_page_option-->',' id="defaultPage"',$menuEntry);
			$firstPage = false;
		} else {
			$menuEntry = str_replace('<!--dvrui_default_page_option-->','',$menuEntry);
		}
		$menuEntries .= $menuEntry;
	}
	// Reload action (sits next to diagnostics) - refreshes the active page, doesn't change tabs
	$menuAction = dvruiTemplate('pagemenu_action.html');
	$menuAction = str_replace('<!-- dvrui_menu_actionname-->', 'reload_page', $menuAction);
	$menuAction = str_replace('<!-- dvrui_menu_actiontip-->', 'Reload', $menuAction);
	$menuAction = str_replace('<!-- dvrui_menu_actionfunc-->', 'reloadPage', $menuAction);
	$menuAction = str_replace('<!-- dvrui_menu_actiontitle-->', '&#x21bb;', $menuAction);
	$menuEntries .= $menuAction;
	$menu_data = str_replace('<!-- dvrui_pagemenu_entries-->',$menuEntries,$menu_data);
	
	// --- Build Page Here ---
	error_log( "Build Index Page - get version" );
	$pageName = DVRUI_Vars::DVRUI_name;
	$UIVersion = "Version:" . DVRUI_Vars::DVRUI_version;
	$DVRVersion = "Record Engine Version: <i id='dvr_engine_version'>" . htmlspecialchars($DVRBinVersion) . "</i>";
	$pagecontent = "";

	// --- include header ---
	error_log( "Build Index Page - header" );
	$header = dvruiTemplate('header.html');
	$pagecontent = str_replace('[[pagetitle]]',$pageName,$header);

	// --- Build Body ---
	error_log( "Build Index Page - body" );
	$indexPage = dvruiTemplate('index_page.html');
	$topmenu = dvruiTemplate('topmenu.html');
	$dashboarddata = dvruiTemplate('dashboard.html');
	$diagnosticsdata = dvruiTemplate('diagnostics.html');
	$settingsdata = dvruiTemplate('settings.html');
	$recordeddata = dvruiTemplate('recorded.html');

	// the header shows the HDHomeRun HD box in place of the HD of HDHomeRun - the
	// box, then "HomeRun DVR Manager"
	$pageLogo = htmlspecialchars($pageName);
	if (strpos($pageName, 'HDHomeRun') === 0) {
		$pageLogo = "<img class='logo_img' src='images/hdhomerun-hd.png' alt='HD'/>"
			. "<span class='logo_name'>" . htmlspecialchars(substr($pageName, 2)) . "</span>";
	}
	$pageLogo = "<span class='logo_title'>" . $pageLogo . "</span>";
	$topmenu = str_replace('[[pagelogo]]',$pageLogo,$topmenu);
	$topmenu = str_replace('[[pagetitle]]',htmlspecialchars($pageName, ENT_QUOTES),$topmenu);
	$topmenu = str_replace('[[UI-Version]]',$UIVersion,$topmenu);
	$topmenu = str_replace('[[DVR-Version]]',$DVRVersion,$topmenu);

	$indexPage = str_replace('<!-- dvrui_topmenu -->',$topmenu,$indexPage);
	$indexPage = str_replace('<!-- dvrui_pagemenu -->',$menu_data,$indexPage);
	$indexPage = str_replace('<!-- dvrui_dashboard -->',$dashboarddata,$indexPage);
	$indexPage = str_replace('<!-- dvrui_diagnostics -->',$diagnosticsdata,$indexPage);
	$indexPage = str_replace('<!-- dvrui_settings -->',$settingsdata,$indexPage);
	$indexPage = str_replace('<!-- dvrui_recorded -->',$recordeddata,$indexPage);

	// -- Attach the Index to the Page
	error_log( "Build Index Page - attach to page" );
	$pagecontent .= $indexPage;

	// --- include footer ---
	error_log( "Build Index Page - footer" );
	$footer = dvruiTemplate('footer.html');
	$footer = str_replace('<!--dvr-statusmsg-->',$statusmsg,$footer);
	$pagecontent .= $footer;

	error_log( "Output page" );
	echo($pagecontent);
	error_log( "======= Debug Log END =========" );
?>

