<?php
/*
 * ui/statusmessage.php
 *
 * Copyright (c) 2020 Rik Dunphy
 * Copyright (c) 2026 Silicondust USA Inc.
 * Released under the MIT License - see LICENSE
 */
	require_once("vars.php");
	require_once("includes/dvrui_hdhrjson.php");
	require_once("includes/dvrui_hdhrbintools.php");
	require_once("includes/dvrui_recordengine_loglist.php");

	/*
	 * The status bar - the record engine only: whether it is running, and the
	 * last line of its newest log. Messages about what the UI did are toasts
	 * (DVRUI_AjaxResponse::message), so they don't replace it.
	 */

	/* array(state, status text, last log line) - state is running, stopped or unknown */
	function dvruiEngineStatus() {
		$hdhr = DVRUI_Vars::DVR_pkgPath . '/' . DVRUI_Vars::DVR_bin;
		$DVRBin = new DVRUI_HDHRbintools($hdhr);
		$status = trim(preg_replace('/\s+/', ' ', (string)$DVRBin->get_DVR_status()));
		if (preg_match('/not running|stopped/i', $status)) {
			$state = 'stopped';
		} elseif (preg_match('/running/i', $status)) {
			$state = 'running';
		} else {
			$state = 'unknown';
		}

		$lastline = '';
		$configFile = new DVRUI_Engine_Config();
		$logList = new DVRUI_Engine_LogList($configFile->getRecordPath());
		$logfile = $logList->getNewestLogFile();
		if ($logfile != '' && is_file($logfile) && is_readable($logfile)) {
			// just the end of the log - it can be large, and this runs on every request
			$fp = @fopen($logfile, 'r');
			if ($fp) {
				$size = filesize($logfile);
				fseek($fp, max(0, $size - 4096));
				$tail = (string)stream_get_contents($fp);
				fclose($fp);
				// the log is empty for a moment when the engine has just started
				$lines = preg_split('/\r\n|\n|\r/', trim($tail));
				$lastline = trim((string)end($lines));
			}
		}
		return array($state, $status, preg_replace('/\s+/', ' ', $lastline));
	}

	/* The status as plain text, e.g. "HDHomeRun RECORD running | 20261126-15:40:16 Status: ..." */
	function getLatestHDHRStatus() {
		list($state, $status, $lastline) = dvruiEngineStatus();
		return trim($status . ' | ' . $lastline);
	}

	/* The status bar: the engine state with an icon - a green check when it is
	 * running, a red ! when it isn't (an error), a yellow ? when it can't be told
	 * (a warning) - then the last log line */
	function dvruiStatusHtml() {
		list($state, $status, $lastline) = dvruiEngineStatus();
		$labels = array('running' => 'Running', 'stopped' => 'Not running', 'unknown' => 'Unknown');
		$icons = array('running' => '&#10003;', 'stopped' => '!', 'unknown' => '?');
		return "<span class='dvr_state dvr_state_" . $state . "' title='" . htmlspecialchars($status != '' ? $status : 'The record engine did not report its status', ENT_QUOTES) . "'>"
			. "<span class='dvr_state_icon' aria-hidden='true'>" . $icons[$state] . "</span>"
			. "Record engine: " . $labels[$state] . "</span>"
			. "<span class='dvr_status_text' title='" . htmlspecialchars('Last line of the record engine log: ' . $lastline, ENT_QUOTES) . "'>"
			. htmlspecialchars($lastline) . "</span>";
	}

	/* The page asks for the status now and then, so the bar stays up to date */
	function dvrStatus() {
		$tab = new DVRUI_AjaxResponse();
		return $tab->getString();
	}

?>
