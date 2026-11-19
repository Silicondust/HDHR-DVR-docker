<?php
	require_once("includes/dvrui_ajax.php");
	require_once("vars.php");
	require_once("statusmessage.php");
	require_once("includes/dvrui_common.php");
	require_once("includes/dvrui_recordengine_config.php");
	require_once("includes/dvrui_recordengine_logfile.php");
	require_once("includes/dvrui_recordengine_loglist.php");
	
	function openDashboard() {
		// prep
		ob_start();
		$tab = new DVRUI_AjaxResponse();

		//create output
		$serverConfig = new DVRUI_Engine_Config();
		$htmlStr  = file_get_contents('style/dashboard_page.html');
		$htmlStr = str_replace('<!-- dvrui_dashboard_server_ctrls -->',loadServerCtrlPane(),$htmlStr);
		$htmlStr = str_replace('<!-- dvrui_dashboard_server_params -->',loadServerParamPane($serverConfig),$htmlStr);
		$htmlStr = str_replace('<!-- dvrui_dashboard_hdhr -->',loadHDHRPane(),$htmlStr);
		$htmlStr = str_replace('<!-- dvrui_dashboard_logs -->',loadLogFilePane($serverConfig),$htmlStr);
		
		//get data
		$result = ob_get_contents();
		ob_end_clean();

		// get latest status	
		$statusmsg = getLatestHDHRStatus();
	
		//display
		$tab->setHtml("dashboard_box", $htmlStr);
		if ($result != '' && $result != NULL)
			$tab->setHtml("statusMessage", $result);
		else
			$tab->setHtml("statusMessage", $statusmsg);
		return $tab->getString();
	}


	function loadServerCtrlPane() {
		$htmlStr  = file_get_contents('style/dashboard_ctrls.html');
		return $htmlStr;
	}
	
	function loadServerParamPane($serverConfig) {
		$htmlStr  = file_get_contents('style/dashboard_params.html');
		if ($serverConfig->configFileExists()) {
			$serverParamEntry = file_get_contents('style/dashboard_ctrls_entry_ro.html');
			$serverParamEntry = str_replace('<!-- dvrui_param_name -->', 'Config File Location', $serverParamEntry);
			$serverParamEntry = str_replace('<!-- dvrui_param_value -->', $serverConfig->getConfigFileName(), $serverParamEntry);
			$htmlStr = str_replace('<!-- dvrui_config_file_name -->', $serverParamEntry, $htmlStr);

			$serverParamEntry = file_get_contents('style/dashboard_ctrls_entry_wr.html');
			$serverParamEntry = str_replace('<!-- dvrui_param_name -->', 'RecordPath', $serverParamEntry);
			$serverParamEntry = str_replace('<!-- dvrui_param_value -->', $serverConfig->getRecordPath(), $serverParamEntry);
			$htmlStr = str_replace('<!-- dvrui_config_recordpath_value -->', $serverParamEntry, $htmlStr);

			$serverParamEntry = file_get_contents('style/dashboard_ctrls_entry_wr.html');
			$serverParamEntry = str_replace('<!-- dvrui_param_name -->', 'RecordStreamsMax', $serverParamEntry);
			$serverParamEntry = str_replace('<!-- dvrui_param_value -->', $serverConfig->getRecordStreamsMax(), $serverParamEntry);
			$htmlStr = str_replace('<!-- dvrui_config_recordstreamsmax_value -->', $serverParamEntry, $htmlStr);

			$serverParamEntry = file_get_contents('style/dashboard_ctrls_entry_wr.html');
			$serverParamEntry = str_replace('<!-- dvrui_param_name -->', 'Port', $serverParamEntry);
			$serverParamEntry = str_replace('<!-- dvrui_param_value -->', $serverConfig->getServerPort(), $serverParamEntry);
			$htmlStr = str_replace('<!-- dvrui_config_port_value -->', $serverParamEntry, $htmlStr);

			// the engine sets the StorageID - shown, not edited
			$serverParamEntry = file_get_contents('style/dashboard_ctrls_entry_ro.html');
			$serverParamEntry = str_replace('<!-- dvrui_param_name -->', 'StorageID', $serverParamEntry);
			$serverParamEntry = str_replace('<!-- dvrui_param_value -->', $serverConfig->getStorageId(), $serverParamEntry);
			$htmlStr = str_replace('<!-- dvrui_config_storage_value -->', $serverParamEntry, $htmlStr);

			$serverParamEntry = file_get_contents('style/dashboard_ctrls_entry_wr.html');
			$serverParamEntry = str_replace('<!-- dvrui_param_name -->', 'BetaEngine', $serverParamEntry);
			$serverParamEntry = str_replace('<!-- dvrui_param_value -->', $serverConfig->getBetaEngine(), $serverParamEntry);
			$htmlStr = str_replace('<!-- dvrui_config_beta -->', $serverParamEntry, $htmlStr);

		} else {
			$htmlStr = "ERROR: Can't Parse Config File: " . $serverConfig->getConfigFileName();
		}
		return $htmlStr;
	}
	
	function loadHDHRPane() {
		// Discover HDHR Devices
		$hdhr = new DVRUI_HDHRjson();
		
		// Process Devices
		$devices =  $hdhr->device_count();
		$hdhr_data = '';
		for ($i=0; $i < $devices; $i++) {
			$hdhrEntry = file_get_contents('style/hdhrlist_entry.html');
			$hdhr_device_data = "<a href=" . $hdhr->get_device_baseurl($i) . ">" . $hdhr->get_device_id($i) . "</a>";
			$hdhr_lineup_data = "<a href=" . $hdhr->get_device_lineup($i) . ">" . $hdhr->get_device_channels($i) . " Channels</a>";
			$hdhr_name_data = str_replace("HDHomeRun", "HDHomeRun <br>", $hdhr->get_device_modelname($i));
			$hdhrEntry = str_replace('<!--hdhr_device-->',$hdhr_device_data,$hdhrEntry);
			$hdhrEntry = str_replace('<!--hdhr_channels-->',$hdhr_lineup_data,$hdhrEntry);
			$hdhrEntry = str_replace('<!--hdhr_model-->',$hdhr_name_data,$hdhrEntry);
			$hdhrEntry = str_replace('<!--hdhr_tuners-->',$hdhr->get_device_tuners($i) . ' tuners',$hdhrEntry);
			$hdhrEntry = str_replace('<!--hdhr_firmware_name-->',$hdhr->get_device_fwname($i),$hdhrEntry);
			$hdhrEntry = str_replace('<!--hdhr_firmware_ver-->',$hdhr->get_device_firmware($i),$hdhrEntry);
			$hdhrEntry = str_replace('<!--hdhr_image-->',$hdhr->get_device_image($i),$hdhrEntry);
			$hdhr_data .= $hdhrEntry ;	
		}

		// Process Engines - the engine in this container is highlighted
		$serverConfig = new DVRUI_Engine_Config();
		$myStorageID = $serverConfig->getStorageId();
		$engines =  $hdhr->engine_count();
		for ($i=0; $i < $engines; $i++) {
			$hdhrEntry = file_get_contents('style/enginelist_entry.html');
			$isLocal = (strcasecmp($hdhr->get_engine_storageId($i), $myStorageID) == 0);
			$hdhrEntry = str_replace('<!--hdhr_local_class-->', $isLocal ? ' hdhr_entry_local' : '', $hdhrEntry);
			$hdhrEntry = str_replace('<!--hdhr_local_title-->', $isLocal ? " title='Local record engine (this container)'" : '', $hdhrEntry);
			$hdhr_device_data = "<a href=" . $hdhr->get_engine_baseurl($i) . ">" . $hdhr->get_engine_id($i) . "</a>";
			$hdhr_storage_url = "<a href=" . $hdhr->get_engine_storageUrl($i) . ">" . $hdhr->get_engine_storageUrl($i) . "</a>";
			$hdhr_name_data = str_replace("HDHomeRun", "HDHomeRun <br>", $hdhr->get_engine_modelname($i));
			$hdhrEntry = str_replace('<!--hdhr_device-->',$hdhr_device_data,$hdhrEntry);
			$hdhrEntry = str_replace('<!--hdhr_model-->',$hdhr_name_data,$hdhrEntry);
			$hdhrEntry = str_replace('<!--hdhr_firmware-->',$hdhr->get_engine_firmware($i),$hdhrEntry);
			$hdhrEntry = str_replace('<!--hdhr_freespace-->',$hdhr->get_engine_freespace($i),$hdhrEntry);
			// clicking the engine image opens Recordings filtered to this engine (matched by StorageID)
			// - only in the full UI, the minimal one has no Recordings page
			$imgLink = dvruiFullUI() ? " class='hdhr_img_link' title='Show recordings on this engine' onClick=\"openEngineRecordings(event, '"
				. htmlspecialchars(addslashes($hdhr->get_engine_storageId($i)), ENT_QUOTES) . "')\"" : '';
			$hdhrEntry = str_replace('<!--hdhr_img_link-->',$imgLink,$hdhrEntry);
			$hdhrEntry = str_replace('<!--hdhr_storageid-->',$hdhr->get_engine_storageId($i),$hdhrEntry);
			$hdhrEntry = str_replace('<!--hdhr_storageurl-->',$hdhr_storage_url,$hdhrEntry);
			$hdhrEntry = str_replace('<!--hdhr_image-->',$hdhr->get_engine_image($i),$hdhrEntry);
			$hdhr_data .= $hdhrEntry ;	
		}

		return $hdhr_data;
	}
	
	function loadLogFilePane($serverConfig) {
		$htmlStr =  file_get_contents('style/dashboard_logs.html');
		$loglist = buildLogFileList($serverConfig->getRecordPath());
		$htmlStr =  str_replace('<!-- dvrui_loglist -->', $loglist, $htmlStr);
		return $htmlStr;
	}

	function buildLogFileList($logPath) {
		error_log("*** Building Log File List ***");
		$listStr = '';
		$logPathlist = array();
		if (strpos($logPath, ';') !== false) {
			// have multiple entries
			$logPathlist = explode(';',$logPath);
		} else {
			$logPathlist[0] = $logPath;
		}
		
		$logListEntry = file_get_contents('style/loglist_entry.html');
		$listStr = '<ul>';
		foreach ($logPathlist as $path) {
			if ($path != 'null') {
				$logList = new DVRUI_Engine_LogList($path);
				if ($logList->pathExists()) {
					for ($i = $logList->getListLength() - 1 ; $i >= 0 ; $i--) {
						$logfile = basename($logList->getNextLogFile($i),'.log');
						$logfullname = $logList->getNextLogFile($i);
						// show a readable date - the yyyymmdd file name is kept as the tooltip
						$logEntry = str_replace('<!--logfile-name -->',htmlspecialchars(dvruiLogDate($logfile)),$logListEntry);
						$logEntry = str_replace('<!--logfile-title -->',htmlspecialchars($logfile, ENT_QUOTES),$logEntry);
						$logEntry = str_replace('<!--logfile-fname -->',$logfullname,$logEntry);
						$listStr .= $logEntry;
					}
				} else {
					$listStr = "ERROR: recording path is invalid";
					error_log("ERROR: recording path is invalid");
				}
			} 
		}
		$listStr .= '</ul>';
		return $listStr;
	}

	/* Command bar above the log entries - a check box per log type in the file
	 * (with its count) to show/hide that type, and one to select/unselect all.
	 * Types are alphabetical, with 'Other' (lines without a type) last. */
	function logFilterBar($typeCounts, $typeClasses) {
		if (count($typeCounts) == 0) {
			return '';
		}
		$keys = array_keys($typeCounts);
		usort($keys, function($a, $b) {
			if ($a == 'Other' || $b == 'Other') {
				return ($a == 'Other') - ($b == 'Other');
			}
			return strcasecmp($a, $b);
		});
		$bar = "<label class='logfilter_all'><input type='checkbox' id='logfilter_all' checked"
			. " onclick='selectAllLogTypes(this.checked)'/>All</label>";
		foreach ($keys as $key) {
			$bar .= "<label class='logfilter_type " . $typeClasses[$key] . "'>"
				. "<input type='checkbox' class='logfilter_check' value='" . $key . "' checked"
				. " onclick='filterLogTypes()'/>" . $key
				. " <span class='logfilter_count'>(" . $typeCounts[$key] . ")</span></label>";
		}
		return $bar;
	}

	/* The strip above the filter bar - the log file's full path, and a button to
	 * download it (only the log types switched on, when some are switched off) */
	function logFileNameBar($filename) {
		return "<span class='logfile_path' title='" . htmlspecialchars($filename, ENT_QUOTES) . "'>"
			. htmlspecialchars($filename) . "</span>"
			. "<button type='button' class='logfile_download' data-file='" . htmlspecialchars($filename, ENT_QUOTES) . "'"
			. " title='Download the log file' onclick='downloadLogFile(this)'>"
			. "<svg viewBox='0 0 24 24' width='15' height='15' aria-hidden='true'><path fill='none' stroke='currentColor'"
			. " stroke-width='2' stroke-linecap='round' stroke-linejoin='round' d='M12 3v12m-5-5 5 5 5-5M4 20h16'/></svg>"
			. "<span class='logfile_download_label'>Download</span></button>";
	}

	function getLogFile($filename) {
		error_log("*** Parsing Log File ***");
		// prep
		ob_start();
		$tab = new DVRUI_AjaxResponse();
		$configFile = new DVRUI_Engine_Config();

		//create output
		$logfile = new DVRUI_Engine_LogFile($filename);
		$logEntry = file_get_contents('style/logfile_entry.html');
		$htmlStr = '';
		$typeCounts = array();    // filter key => number of entries
		$typeClasses = array();   // filter key => colour class

		for ($i=0; $i < $logfile->getNumEntry(); $i++) {
			$entry = $logfile->getEntryAt($i);
			$entryStr = str_replace('<!--log-time-->',$entry['Timestamp'],$logEntry);

			if ($logfile->isTypeError($i)) {
				$typeClass = 'logTypeError';
			} else if ($logfile->isTypeStatus($i)) {
				$typeClass = 'logTypeStatus';
			} else if ($logfile->isTypePlayback($i)) {
				$typeClass = 'logTypePlayback';
			} else if ($logfile->isTypeRecording($i)) {
				$typeClass = 'logTypeRecording';
			} else if ($logfile->isTypeRecorded($i)) {
				$typeClass = 'logTypeRecorded';
			} else {
				$typeClass = 'logTypeInfo';
			}
			$entryStr = str_replace('<!--logtype-class-->',$typeClass,$entryStr);

			// filter key - the type without its colon, lines without one are 'Other'
			$typeKey = preg_replace('/[^A-Za-z0-9]/', '', $entry['Type']);
			if ($typeKey == '') {
				$typeKey = 'Other';
			}
			if (!isset($typeCounts[$typeKey])) {
				$typeCounts[$typeKey] = 0;
				$typeClasses[$typeKey] = $typeClass;
			}
			$typeCounts[$typeKey]++;
			$entryStr = str_replace('<!--log-typekey-->',$typeKey,$entryStr);

			$entryStr = str_replace('<!--log-type-->',$entry['Type'],$entryStr);
			$entryStr = str_replace('<!--logsubtype-class-->','',$entryStr);
			$entryStr = str_replace('<!--log-subtype-->',$entry['SubType'],$entryStr);
			if ($logfile->infoContainsError($i)) {
				$entryStr = str_replace('<!--logentry-class-->','logInfoError',$entryStr);
			} else {
				$entryStr = str_replace('<!--logentry-class-->','',$entryStr);
			}
			$entryStr = str_replace('<!--log-entry-->',$entry['Info'],$entryStr);
			$htmlStr.= $entryStr;
		}
	
		$statusmsg = getLatestHDHRStatus();
		//get data
		$result = ob_get_contents();
		ob_end_clean();
	
		//display
		// entries first - the filter bar going in re-applies any types switched off
		$tab->setHtml("logfile_box", $htmlStr);
		$tab->setHtml("logfile_name", logFileNameBar($filename));
		$tab->setHtml("logfile_filter", logFilterBar($typeCounts, $typeClasses));
		if ($result != '' && $result != NULL)
			$tab->setHtml("statusMessage", $result);
		else
			$tab->setHtml("statusMessage", $statusmsg);
		return $tab->getString();
	}

	function updateServerConfig($port,$path,$streams,$beta) {
		// prep
		ob_start();
		$tab = new DVRUI_AjaxResponse();
		
		//create output
		$serverConfig = new DVRUI_Engine_Config();

		error_log('Updating Config File: '. $port . ' | ' . $streams . ' | ' . $path . ' | ' . $beta);
		$serverConfig->setRecordPath($path);
		$serverConfig->setRecordStreamsMax($streams);
		$serverConfig->setServerPort($port);
		$serverConfig->setBetaEngine($beta);
		$serverConfig->writeConfigFile();
	
	
		$htmlStr  = file_get_contents('style/dashboard_page.html');
		$htmlStr = str_replace('<!-- dvrui_dashboard_server_ctrls -->',loadServerCtrlPane(),$htmlStr);
		$htmlStr = str_replace('<!-- dvrui_dashboard_server_params -->',loadServerParamPane($serverConfig),$htmlStr);
		$htmlStr = str_replace('<!-- dvrui_dashboard_hdhr -->',loadHDHRPane(),$htmlStr);
		$htmlStr = str_replace('<!-- dvrui_dashboard_logs -->',loadLogFilePane($serverConfig),$htmlStr);
		
		$statusmsg = getLatestHDHRStatus();
	
		//get data
		$result = ob_get_contents();
		ob_end_clean();
		
		//display
		$tab->setHtml("dashboard_box", $htmlStr);
		if ($result != '' && $result != NULL)
			$tab->setHtml("statusMessage", $result);
		else
			$tab->setHtml("statusMessage", $statusmsg);
		return $tab->getString();
	}

?>
