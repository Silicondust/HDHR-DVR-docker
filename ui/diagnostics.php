<?php
/*
 * ui/diagnostics.php
 *
 * Copyright (c) 2020 Rik Dunphy
 * Copyright (c) 2026 Silicondust USA Inc.
 * Released under the MIT License - see LICENSE
 */
	require_once("includes/dvrui_ajax.php");
	require_once("vars.php");
	require_once("statusmessage.php");
	require_once("includes/dvrui_hdhrjson.php");
	require_once("includes/dvrui_common.php");

  function openDiagnosticsPage() {
		// prep
		ob_start();
		$tab = new DVRUI_AjaxResponse();

		// Diagnostics shows what the SiliconDust servers say now - its fetches
		// fill the cache again for the other pages
		dvruiApiCacheClear();

		//create output
		$htmlStr = "";

		//get data - one block per section, matching the sidebar in style/diagnostics.html
		$htmlStr .= diagSection('system', getSystemDiag());
		$htmlStr .= diagSection('config', getConfigDiag());
		$htmlStr .= diagSection('container', getContainerDiag());
		$htmlStr .= diagSection('connectivity', getConnectivityDiag());

  	$hdhr = new DVRUI_HDHRjson();
		$serverConfig = new DVRUI_Engine_Config();
		$hdhr->set_my_engine($serverConfig->getStorageId());

		$htmlStr .= diagSection('discovery', getDiscoveryDiag($hdhr));
		$htmlStr .= diagSection('tuners', getTunersDiag($hdhr));
		$htmlStr .= diagSection('engines', getEnginesDiag($hdhr));
		$htmlStr .= diagSection('account', getAccountDiag($hdhr));
		$htmlStr .= diagSection('series', getSeriesDiag($hdhr));
		$htmlStr .= diagSection('recordings', getRecordingsDiag($hdhr));

		$result = ob_get_contents();
		ob_end_clean();

	
		//display
		$tab->setHtml("diagnostics_box", $htmlStr);

		$tab->output($result);
		return $tab->getString();
  }

  // A section of the page - the sidebar shows one section, or all of them
  function diagSection($id, $htmlStr) {
  	return '<div class="diag_section" data-section="' . $id . '">' . $htmlStr . '</div>';
  }

  function getSystemDiag() {
  	$htmlStr = "---------- OS and ENV VARIABLES --------------------------------------------------<br/>";
  	$htmlStr .= 'Operating System = ' . php_uname() . "<br/>";
  	$htmlStr .= 'HTTP_HOST = ' . getenv('HTTP_HOST') . "<br/>";
  	$htmlStr .= 'SERVER_NAME = ' . getenv('SERVER_NAME') . "<br/>";
  	$htmlStr .= 'SERVER_ADDR = ' . getenv('SERVER_ADDR') . "<br/>";
  	$htmlStr .= 'SERVER_SOFTWARE = ' . getenv('SERVER_SOFTWARE') . "<br/>";
  	$htmlStr .= 'SYS TEMP = ' . sys_get_temp_dir() . "<br/>";
  	$htmlStr .= "---------- PHP INFO -------------------------------------------------------------<br/>";
  	$htmlStr .= 'php version = ' . phpversion() . "<br/>";
  	$htmlStr .= 'php.ini file = ' . php_ini_loaded_file() . "<br/>";
  	$htmlStr .= 'php.ini date.timezone = ' . ini_get('date.timezone') . "<br/>";
  	$htmlStr .= 'date_default_timezone_get() = ' . date_default_timezone_get() . "<br/>";
  	$htmlStr .= "---------- DVRUI VARS -----------------------------------------------------------<br/>";
  	$htmlStr .= 'DVRUI_DEBUG = ' . DVRUI_Vars::DVRUI_DEBUG . "<br/>";
  	$htmlStr .= 'DVRUI_name = ' . DVRUI_Vars::DVRUI_name . "<br/>";
  	$htmlStr .= 'DVRUI_version = ' . DVRUI_Vars::DVRUI_version . "<br/>";
  	$htmlStr .= 'DVRUI_TZ = ' . DVRUI_Vars::DVRUI_TZ . "<br/>";
  	$htmlStr .= "---------- PERMISSIONS ----------------------------------------------------------<br/>";
  	$htmlStr .= 'style = ' . substr(sprintf('%o', fileperms('style')), -4) . "<br/>";
  	$htmlStr .= 'style/style.css = ' . substr(sprintf('%o', fileperms('style/style.css')), -4) . "<br/>";
		return $htmlStr;
  }

  function getConfigDiag() {
		$serverConfig = new DVRUI_Engine_Config();
  	$htmlStr = "---------- Config File ----------------------------------------------------------<br/>";
  	$htmlStr .= 'Location = ' . $serverConfig->getConfigFileName() . "<br/>";
  	$htmlStr .= 'RecordPath = ' . $serverConfig->getRecordPath() . "<br/>";
  	$htmlStr .= 'RecordStreamsMax = ' . $serverConfig->getRecordStreamsMax() . "<br/>";
  	$htmlStr .= 'ServerPort = ' . $serverConfig->getServerPort() . "<br/>";
  	$htmlStr .= 'StorageID = ' . $serverConfig->getStorageId() . "<br/>";
		return $htmlStr;
  }
  
  /* The container - the local record engine (is it running, as whom, its file and
   * startup log), the users, and the volumes (mounts, owners, free space).
   * Raw values, as read. */
  function getContainerDiag() {
  	$engineFile = DVRUI_Vars::DVR_pkgPath . '/' . DVRUI_Vars::DVR_bin;
  	$users = diagReadIdFile('/etc/passwd');
  	$groups = diagReadIdFile('/etc/group');

  	$htmlStr = "---------- LOCAL RECORD ENGINE --------------------------------------------------<br/>";
  	$ctl = defined('DVRUI_SUPERVISORCTL') ? DVRUI_SUPERVISORCTL : '/usr/bin/supervisorctl -c /etc/supervisor.d/supervisord.conf';
  	$status = shell_exec($ctl . ' status dvr 2>&1');
  	$htmlStr .= 'supervisorctl status dvr = ' . htmlspecialchars(trim((string)$status)) . "<br/>";
  	$procs = diagEngineProcesses();
  	if (count($procs) == 0) {
  		$htmlStr .= 'engine process = none<br/>';
  	}
  	foreach ($procs as $p) {
  		$htmlStr .= 'engine process = pid ' . $p['pid'] . ' uid ' . htmlspecialchars($p['uid'])
  			. ' (' . htmlspecialchars(diagIdName($users, $p['uid'])) . ') ' . htmlspecialchars($p['cmd']) . "<br/>";
  	}

  	$htmlStr .= "---------- ENGINE FILE ----------------------------------------------------------<br/>";
  	$htmlStr .= diagPathInfo('engine file', $engineFile, $users, $groups);

  	$htmlStr .= "---------- ENGINE STARTUP LOG (last 50 lines of /tmp/dvrmgr.log) ---------------<br/>";
  	$log = @file('/tmp/dvrmgr.log', FILE_IGNORE_NEW_LINES);
  	if ($log === false) {
  		$htmlStr .= 'not available<br/>';
  	} else {
  		foreach (array_slice($log, -50) as $line) {
  			$htmlStr .= htmlspecialchars($line) . "<br/>";
  		}
  	}

  	$htmlStr .= "---------- USERS ----------------------------------------------------------------<br/>";
  	$ids = diagProcessIds('/proc/self/status');
  	$htmlStr .= 'UI (php) uid = ' . htmlspecialchars($ids['uid']) . ' (' . htmlspecialchars(diagIdName($users, $ids['uid'])) . ')'
  		. ' gid = ' . htmlspecialchars($ids['gid']) . ' (' . htmlspecialchars(diagIdName($groups, $ids['gid'])) . ')<br/>';
  	$dvrUid = array_search('dvr', $users, true);
  	if ($dvrUid === false) {
  		$htmlStr .= 'dvr (PUID) user = none<br/>';
  	} else {
  		$line = diagIdLine('/etc/passwd', 'dvr');
  		$htmlStr .= 'dvr (PUID) user = ' . htmlspecialchars($line) . "<br/>";
  	}

  	$htmlStr .= "---------- VOLUMES --------------------------------------------------------------<br/>";
  	$mounts = diagReadMounts();
  	foreach (array('/dvrdata', '/dvrrec', '/tmp') as $path) {
  		$htmlStr .= diagPathInfo($path, $path, $users, $groups);
  		$real = @realpath($path);
  		$mount = diagMountFor($mounts, $real !== false ? $real : $path);
  		$htmlStr .= $path . ' mount = ' . htmlspecialchars($mount) . "<br/>";
  		$free = @disk_free_space($path);
  		$total = @disk_total_space($path);
  		$htmlStr .= $path . ' free bytes = ' . ($free === false ? 'unknown' : sprintf('%.0f', $free))
  			. ' of ' . ($total === false ? 'unknown' : sprintf('%.0f', $total)) . "<br/>";
  	}
  	return $htmlStr;
  }

  // mode, owner, size, mtime and access (for the UI's user) of a file or directory
  function diagPathInfo($label, $path, $users, $groups) {
  	clearstatcache();
  	$st = @stat($path);
  	if ($st === false) {
  		return htmlspecialchars($label) . ' = ' . htmlspecialchars($path) . ' not found<br/>';
  	}
  	$htmlStr = htmlspecialchars($label) . ' = ' . htmlspecialchars($path);
  	if (is_link($path)) {
  		$htmlStr .= ' -> ' . htmlspecialchars((string)@readlink($path));
  	}
  	$htmlStr .= '<br/>';
  	$htmlStr .= htmlspecialchars($label) . ' mode = ' . sprintf('%o', $st['mode'] & 07777)
  		. ' owner = ' . $st['uid'] . ':' . $st['gid']
  		. ' (' . htmlspecialchars(diagIdName($users, $st['uid'])) . ':' . htmlspecialchars(diagIdName($groups, $st['gid'])) . ')'
  		. ' size = ' . $st['size'] . ' mtime = ' . $st['mtime'] . '<br/>';
  	$htmlStr .= htmlspecialchars($label) . ' UI access = '
  		. (is_readable($path) ? 'r' : '-') . (is_writable($path) ? 'w' : '-') . (is_executable($path) ? 'x' : '-') . '<br/>';
  	return $htmlStr;
  }

  // id => name from /etc/passwd or /etc/group
  function diagReadIdFile($file) {
  	$ids = array();
  	foreach ((array)@file($file, FILE_IGNORE_NEW_LINES) as $line) {
  		$f = explode(':', $line);
  		if (count($f) >= 3) {
  			$ids[$f[2]] = $f[0];
  		}
  	}
  	return $ids;
  }

  function diagIdName($ids, $id) {
  	return isset($ids[$id]) ? $ids[$id] : '?';
  }

  // the line for a name in /etc/passwd or /etc/group
  function diagIdLine($file, $name) {
  	foreach ((array)@file($file, FILE_IGNORE_NEW_LINES) as $line) {
  		if (strpos($line, $name . ':') === 0) {
  			return $line;
  		}
  	}
  	return '';
  }

  // real uid and gid from a /proc/<pid>/status file
  function diagProcessIds($statusFile) {
  	$ids = array('uid' => '?', 'gid' => '?');
  	foreach ((array)@file($statusFile, FILE_IGNORE_NEW_LINES) as $line) {
  		if (preg_match('/^(Uid|Gid):\s+(\d+)/', $line, $m)) {
  			$ids[strtolower($m[1])] = $m[2];
  		}
  	}
  	return $ids;
  }

  // the running engine programs (hdhomerun_record_<arch>, unpacked to /tmp)
  function diagEngineProcesses() {
  	$procs = array();
  	foreach ((array)@glob('/proc/[0-9]*', GLOB_ONLYDIR) as $dir) {
  		$cmd = @file_get_contents($dir . '/cmdline');
  		if ($cmd === false || $cmd === '') {
  			continue;
  		}
  		$cmd = trim(str_replace("\0", ' ', $cmd));
  		if (preg_match('#^\S*hdhomerun_record_\w+\s#', $cmd . ' ')) {
  			$ids = diagProcessIds($dir . '/status');
  			$procs[] = array('pid' => basename($dir), 'uid' => $ids['uid'], 'cmd' => $cmd);
  		}
  	}
  	return $procs;
  }

  // mount point => the /proc/mounts line
  function diagReadMounts() {
  	$mounts = array();
  	foreach ((array)@file('/proc/mounts', FILE_IGNORE_NEW_LINES) as $line) {
  		$f = explode(' ', $line);
  		if (count($f) >= 4) {
  			$mounts[$f[1]] = $line;   // a later mount on the same point hides the earlier one
  		}
  	}
  	return $mounts;
  }

  // the /proc/mounts line for the mount a path is on (the longest mount point that contains it)
  function diagMountFor($mounts, $path) {
  	$best = '';
  	foreach ($mounts as $point => $line) {
  		if (($path == $point || $point == '/' || strpos($path, rtrim($point, '/') . '/') === 0)
  			&& strlen($point) > strlen($best)) {
  			$best = $point;
  		}
  	}
  	return $best == '' ? 'unknown' : $mounts[$best];
  }

  function getConnectivityDiag() {
  	$htmlStr = '';
  	$myhdhrurl = DVRUI_Vars::DVRUI_apiurl . 'discover';
  	$htmlStr .= "---------- CONNECTIVITY CHECKS --------------------------------------------------<br/>";
  	$htmlStr .= 'DVRUI_apiurl = ' . DVRUI_Vars::DVRUI_apiurl . "<br/>";
  	$htmlStr .= 'Discover URL = ' . $myhdhrurl . "<br/>";
  	$apiport   = (parse_url($myhdhrurl, PHP_URL_SCHEME) == 'https') ? 443 : 80;
  	$file      = fsockopen (parse_url($myhdhrurl, PHP_URL_HOST), $apiport, $errno, $errstr, 10);
  	if (! $file) {
  		$htmlStr .= 'Unable to open connection to ' . parse_url($myhdhrurl, PHP_URL_HOST) ;
  		$htmlStr .= '  Failed with error ' . $errno;
  		$htmlStr .= ' -  ' . $errstr . '<br/>';
  	}
  	else {
  		$htmlStr .= 'Ping to ' . parse_url($myhdhrurl, PHP_URL_HOST) . ' success<br/>';
  	}
		if (in_array('curl', get_loaded_extensions())){
			$htmlStr .= 'curl extension = installed <br/>';
			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL, $myhdhrurl);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
			curl_setopt($ch, CURLOPT_TIMEOUT, 2);
			curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
			$json = curl_exec($ch);
			$htmlStr .= 'curl returned = ' . $json . '<br/>';
		} else { 
			$htmlStr .= 'curl extension = not available <br/>';
		}
		$htmlStr .= 'checking alternative connection <br/>';
		$context = stream_context_create(
				array('http' => array(
					'header'=>'Connection: close\r\n',
					'timeout' => 2.0)));
		$json = file_get_contents($myhdhrurl,false,$context);	
		$htmlStr .= 'stream returned = ' . $json . '<br/>';
  	return $htmlStr;
  }

  function getDiscoveryDiag($hdhr) {
  	$htmlStr =  "---------- HDHR DISCOVERY -------------------------------------------------------<br/>";
  	foreach ($hdhr->get_discover_log() as $msg) {
  		$htmlStr .= htmlspecialchars($msg) . "<br/>";
  	}
		return $htmlStr;
  }

  function getTunersDiag($hdhr) {
  	$devices =  $hdhr->device_count();
  	$htmlStr =  "---------- HDHR TUNERS-----------------------------------------------------------<br/>";
  	for ($i=0; $i < $devices; $i++) {
  		$htmlStr .=  'tuner(' . $i . ') id: ' . $hdhr->get_device_id($i) . "<br/>";
  		$htmlStr .=  'tuner(' . $i . ') found by: ' . $hdhr->get_device_source($i) . "<br/>";
  		$htmlStr .=  'tuner(' . $i . ') local IP: ' . $hdhr->get_device_localip($i) . "<br/>";
  		$htmlStr .=  'tuner(' . $i . ') model: ' . $hdhr->get_device_model($i) . "<br/>";
  		$htmlStr .=  'tuner(' . $i . ') firmware: ' . $hdhr->get_device_firmware($i) . "<br/>";
  		$htmlStr .=  'tuner(' . $i . ') baseurl: ' . $hdhr->get_device_baseurl($i) . "<br/>";
  		$htmlStr .=  'tuner(' . $i . ') auth: ' . $hdhr->get_device_auth($i) . "<br/>";
  	}
		return $htmlStr;
  }

  function getEnginesDiag($hdhr) {
  	$htmlStr =  "---------- HDHR DVR ENGINES------------------------------------------------------<br/>";
  	$engines = $hdhr->engine_count();
  	for ($i=0; $i < $engines; $i++) {
  		$htmlStr .=  'dvr(' . $i . ') name: ' . $hdhr->get_engine_modelname($i) . "<br/>";
  		$htmlStr .=  'dvr(' . $i . ') version: ' . $hdhr->get_engine_firmware($i) . "<br/>";
  		$htmlStr .=  'dvr(' . $i . ') found by: ' . $hdhr->get_engine_source($i) . "<br/>";
  		$htmlStr .=  'dvr(' . $i . ') base URL: ' . $hdhr->get_engine_baseurl($i) . "<br/>";
  		$htmlStr .=  'dvr(' . $i . ') storage URL: ' . $hdhr->get_engine_storageUrl($i) . "<br/>";
  		$htmlStr .=  'dvr(' . $i . ') storage ID: ' . $hdhr->get_engine_storageId($i) . "<br/>";
  		$htmlStr .=  'dvr(' . $i . ') local IP: ' . $hdhr->get_engine_localip($i) . "<br/>";
  	}
		return $htmlStr;
  }

  function getAccountDiag($hdhr) {
  	$htmlStr =  "---------- HDHR Account -----------------------------------------------------------<br/>";
  	$devices =  $hdhr->device_count();
  	$url = DVRUI_Vars::DVRUI_apiurl . 'api/account?DeviceAuth=';
  	$auth = '';
  	for ($i=0; $i < $devices; $i++) {
  	  $auth .= $hdhr->get_device_auth($i);
    }

		if (in_array('curl', get_loaded_extensions())){
			$htmlStr .= 'curl extension = installed <br/>';
			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL, $url . $auth);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
			curl_setopt($ch, CURLOPT_TIMEOUT, 2);
			curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
			$json = curl_exec($ch);
			$htmlStr .= 'curl returned = ' . $json . '<br/>';
		} else { 
			$htmlStr .= 'curl extension = not available <br/>';
		  $context = stream_context_create(
				array('http' => array(
					'header'=>'Connection: close\r\n',
					'timeout' => 2.0)));
	  	$json = file_get_contents($url . $auth,false,$context);	
  		$htmlStr .= 'stream returned = ' . $json . '<br/>';
		}
		return $htmlStr;
  }

  function getSeriesDiag($hdhr) {
  	$htmlStr =  "------------ DVR  Series ------------------------------------------------------<br/>";
 	  $seriesURL = $hdhr->get_storage_url() . '?DisplayGroupID=root';
 		$htmlStr .=  'Series URL = ' . htmlspecialchars($seriesURL) . '<br/>';
 		$seriesjson = getJsonFromURL($seriesURL);
 		if (!is_array($seriesjson)) {
 			return $htmlStr . 'No series data returned<br/>';
 		}
 		$htmlStr .= 'Series count = ' . count($seriesjson) . '<br/>';
 		foreach (array_values($seriesjson) as $i => $series) {
 			$htmlStr .= 'series(' . $i . ') ' . formatDiagEntry($series) . '<br/>';
 		}
		return $htmlStr;
  }

  // One line per entry: key=value pairs, nested values as JSON
  function formatDiagEntry($entry) {
  	if (!is_array($entry)) {
  		return htmlspecialchars((string)$entry);
  	}
  	$fields = array();
  	foreach ($entry as $key => $val) {
  		$fields[] = htmlspecialchars($key . '=' . formatDiagValue($val));
  	}
  	return implode(', ', $fields);
  }

  // Scalars as text, nested values as compact JSON
  function formatDiagValue($val) {
  	if (is_array($val)) {
  		return json_encode($val, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  	}
  	if (is_bool($val)) {
  		return $val ? 'true' : 'false';
  	}
  	return (string)$val;
  }

  function getRecordingsDiag($hdhr) {
  	$htmlStr =  "------------ DVR  Recordings ------------------------------------------------------<br/>";
 	  $recordingsURL = $hdhr->get_storage_url();
 		$htmlStr .=  'Recordings URL = ' . htmlspecialchars($recordingsURL) . '<br/>';
 		$recordingsjson = getJsonFromURL($recordingsURL);
 		if (!is_array($recordingsjson)) {
 			return $htmlStr . 'No recordings data returned<br/>';
 		}
 		$htmlStr .= 'Recordings count = ' . count($recordingsjson) . '<br/><br/>';
 		foreach (array_values($recordingsjson) as $i => $recording) {
 			$htmlStr .= 'recording(' . $i . ')<br/>' . formatDiagBlock($recording) . '<br/>';
 		}
		return $htmlStr;
  }

  // One field per line, indented under the entry header
  function formatDiagBlock($entry) {
  	if (!is_array($entry)) {
  		return '&nbsp;&nbsp;&nbsp;&nbsp;' . htmlspecialchars((string)$entry) . '<br/>';
  	}
  	$htmlStr = '';
  	foreach ($entry as $key => $val) {
  		$htmlStr .= '&nbsp;&nbsp;&nbsp;&nbsp;' . htmlspecialchars($key . ' = ' . formatDiagValue($val)) . '<br/>';
  	}
  	return $htmlStr;
  }

