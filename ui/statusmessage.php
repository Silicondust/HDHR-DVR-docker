<?php
	require_once("vars.php");
	require_once("includes/dvrui_hdhrjson.php");
	require_once("includes/dvrui_recordengine_loglist.php");

	function getLatestHDHRStatus() {
		
		$hdhr = DVRUI_Vars::DVR_pkgPath . '/' . DVRUI_Vars::DVR_bin;
		$DVRBin = new DVRUI_HDHRbintools($hdhr);
		$statusmsg = $DVRBin->get_DVR_status();

		$lastline='';
		$configFile = new DVRUI_Engine_Config();
		$logList = new DVRUI_Engine_LogList($configFile->getRecordPath());
		$logfile = $logList->getNewestLogFile();
		if (file_exists($logfile)) {
			// the log is empty for a moment when the engine has just started
			$lines = file($logfile);
			if ($lines !== false && count($lines) > 0) {
				$lastline = $lines[count($lines) - 1];
			}
		}
		
		$htmlstr = preg_replace('/\s+/', ' ', trim($statusmsg . ' | ' . $lastline));
		return $htmlstr;
	}

?>
