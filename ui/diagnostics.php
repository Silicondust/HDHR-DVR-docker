<?php
	require_once("TinyAjaxBehavior.php");
	require_once("vars.php");
	require_once("statusmessage.php");
	require_once("includes/dvrui_hdhrjson.php");
	require_once("includes/dvrui_common.php");

  function openDiagnosticsPage() {
		// prep
		ob_start();
		$tab = new TinyAjaxBehavior();

		//create output
		$htmlStr = "";
		
		//get data
		$htmlStr .= getServerDiag();
		$htmlStr .= getConnectivityDiag();

  	$hdhr = new DVRUI_HDHRjson();
		$serverConfig = new DVRUI_Engine_Config();
		$hdhr->set_my_engine($serverConfig->getStorageId());

		$htmlStr .= getHDHRDiag($hdhr);
		$htmlStr .= getAccountDiag($hdhr);
		$htmlStr .= getSeriesDiag($hdhr);
		$htmlStr .= getRecordingsDiag($hdhr);
		
		$result = ob_get_contents();
		ob_end_clean();

		// get latest status	
		$statusmsg = getLatestHDHRStatus();
	
		//display
		$tab->add(TabInnerHtml::getBehavior("diagnostics_box", $htmlStr));

		if ($result != '' && $result != NULL)
			$tab->add(TabInnerHtml::getBehavior("statusMessage", $result));
		else
			$tab->add(TabInnerHtml::getBehavior("statusMessage", $statusmsg));
		return $tab->getString();
  }	
  	
  function getServerDiag() {
		$serverConfig = new DVRUI_Engine_Config();
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
  	$htmlStr .= "---------- Config File ----------------------------------------------------------<br/>";
  	$htmlStr .= 'Location = ' . $serverConfig->getConfigFileName() . "<br/>";
  	$htmlStr .= 'RecordPath = ' . $serverConfig->getRecordPath() . "<br/>";
  	$htmlStr .= 'RecordStreamsMax = ' . $serverConfig->getRecordStreamsMax() . "<br/>";
  	$htmlStr .= 'ServerPort = ' . $serverConfig->getServerPort() . "<br/>";
  	$htmlStr .= 'StorageID = ' . $serverConfig->getStorageId() . "<br/>";
		return $htmlStr;
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

  function getHDHRDiag($hdhr) {
  	$devices =  $hdhr->device_count();
  	$hdhr_data = '';
  	$htmlStr =  "---------- HDHR DISCOVERY -------------------------------------------------------<br/>";
  	foreach ($hdhr->get_discover_log() as $msg) {
  		$htmlStr .= htmlspecialchars($msg) . "<br/>";
  	}
  	$htmlStr .=  "---------- HDHR TUNERS-----------------------------------------------------------<br/>";
  	for ($i=0; $i < $devices; $i++) {
  		$htmlStr .=  'tuner(' . $i . ') id: ' . $hdhr->get_device_id($i) . "<br/>";
  		$htmlStr .=  'tuner(' . $i . ') found by: ' . $hdhr->get_device_source($i) . "<br/>";
  		$htmlStr .=  'tuner(' . $i . ') local IP: ' . $hdhr->get_device_localip($i) . "<br/>";
  		$htmlStr .=  'tuner(' . $i . ') model: ' . $hdhr->get_device_model($i) . "<br/>";
  		$htmlStr .=  'tuner(' . $i . ') firmware: ' . $hdhr->get_device_firmware($i) . "<br/>";
  		$htmlStr .=  'tuner(' . $i . ') baseurl: ' . $hdhr->get_device_baseurl($i) . "<br/>";
  		$htmlStr .=  'tuner(' . $i . ') auth: ' . $hdhr->get_device_auth($i) . "<br/>";
  	}
  	
  	$htmlStr .=  "---------- HDHR DVR ENGINES------------------------------------------------------<br/>";
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

