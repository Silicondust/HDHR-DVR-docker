<?php
	/* Download a record engine log file as text - the whole file, or with
	 * types=Error,Status only the lines of those log types (the same type keys
	 * as the log viewer's filter bar, 'Other' for lines without a type).
	 * Only .log files in the engine's record path(s) can be downloaded. */
	require_once("vars.php");
	require_once("includes/dvrui_recordengine_config.php");
	require_once("includes/dvrui_recordengine_logentry.php");
	require_once("includes/dvrui_recordengine_loglist.php");

	function logDownloadFail($code, $msg) {
		http_response_code($code);
		header('Content-Type: text/plain; charset=utf-8');
		echo $msg . "\n";
		exit;
	}

	$requested = isset($_GET['file']) ? (string)$_GET['file'] : '';
	if ($requested == '') {
		logDownloadFail(400, 'No log file given');
	}

	// the file must be one the log list shows
	$serverConfig = new DVRUI_Engine_Config();
	$filename = '';
	foreach (explode(';', $serverConfig->getRecordPath()) as $path) {
		if ($path == '' || $path == 'null') {
			continue;
		}
		$logList = new DVRUI_Engine_LogList($path);
		for ($i = 0; $i < $logList->getListLength(); $i++) {
			if ($logList->getNextLogFile($i) === $requested) {
				$filename = $requested;
				break 2;
			}
		}
	}
	if ($filename == '' || !is_file($filename)) {
		logDownloadFail(404, 'Log file not found');
	}

	// types to keep - none given means the whole file
	$types = array();
	if (isset($_GET['types']) && $_GET['types'] !== '') {
		foreach (explode(',', (string)$_GET['types']) as $type) {
			$type = preg_replace('/[^A-Za-z0-9]/', '', $type);
			if ($type != '') {
				$types[$type] = true;
			}
		}
	}

	$base = basename($filename, '.log');
	$name = $base . (count($types) > 0 ? '-filtered' : '') . '.log';
	header('Content-Type: text/plain; charset=utf-8');
	header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $name) . '"');
	header('Cache-Control: no-store');

	if (count($types) == 0) {
		header('Content-Length: ' . filesize($filename));
		readfile($filename);
		exit;
	}

	$fh = fopen($filename, 'r');
	if ($fh === false) {
		logDownloadFail(500, 'Could not read the log file');
	}
	while (($line = fgets($fh)) !== false) {
		$entry = new DVRUI_Engine_LogEntry($line);
		$key = preg_replace('/[^A-Za-z0-9]/', '', $entry->getLogType());
		if ($key == '') {
			$key = 'Other';
		}
		if (isset($types[$key])) {
			echo $line;
		}
	}
	fclose($fh);
