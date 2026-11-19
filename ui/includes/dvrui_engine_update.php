<?php
	/* Record engine update - is a newer engine available from SiliconDust, is
	 * anything recording, and restart the engine so it installs the newer one.
	 *
	 * The UI runs as the web user, so it doesn't install the engine itself:
	 * restarting the dvr program (supervisorctl) runs hdhomerun.sh again, and
	 * that downloads and installs the newest engine as root. The download and
	 * version compare here follow the same rules (see update_engine there). */

	require_once("vars.php");
	require_once("includes/dvrui_ajax.php");
	require_once("includes/dvrui_hdhrbintools.php");
	require_once("includes/dvrui_recordengine_config.php");
	require_once("includes/dvrui_recordengine_loglist.php");

	const DVRUI_ENGINE_URL      = 'https://download.silicondust.com/hdhomerun/hdhomerun_record_linux';
	const DVRUI_ENGINE_BETA_URL = 'https://download.silicondust.com/hdhomerun/hdhomerun_record_linux_beta';
	const DVRUI_SUPERVISORCTL   = '/usr/bin/supervisorctl -c /etc/supervisor.d/supervisord.conf';
	// the engine writes a status block every 2 minutes - a log quiet for longer
	// than this means the engine isn't running (or isn't logging)
	const DVRUI_ENGINE_LOG_STALE = 360;
	// the latest version found is kept this long, so the update doesn't download it again
	const DVRUI_ENGINE_CACHE_SECS = 600;
	// a recording starting within this long counts as about to start - the update
	// (download and restart) takes a minute or so
	const DVRUI_ENGINE_SOON_SECS = 900;
	// and one that started this long ago may not be in a status block yet
	const DVRUI_ENGINE_STARTED_SECS = 300;

	/* A number that orders engine versions - 20261107 => 2026110700,
	 * 20261107beta2 => 2026110702 (0 when it isn't a version) */
	function dvruiEngineVersionKey($version) {
		if (!preg_match('/^(\d{8})(?:beta(\d+))?/', (string)$version, $m)) {
			return 0;
		}
		return intval($m[1]) * 100 + (isset($m[2]) ? min(intval($m[2]), 99) : 0);
	}

	/* The engine's log files, newest first (all the record paths) */
	function dvruiEngineLogFiles() {
		$config = new DVRUI_Engine_Config();
		$files = array();
		foreach (explode(';', $config->getRecordPath()) as $path) {
			if ($path == '' || $path == 'null') {
				continue;
			}
			$list = new DVRUI_Engine_LogList($path);
			for ($i = 0; $i < $list->getListLength(); $i++) {
				$file = $list->getNextLogFile($i);
				if (is_file($file)) {
					$files[$file] = filemtime($file);
				}
			}
		}
		arsort($files);
		return $files;
	}

	/* Recordings that start soon (or have only just started, so may not be in
	 * a status block yet), from the events the engine logs as it plans them:
	 *   20261119-20:05:12 Recording: event Élément Terre 20130411 [20261119-2020] in future
	 * The bracket is the start time, in UTC. Events with a stop or 'already ended'
	 * line after them have finished and are left out. Only the newest two logs are read -
	 * the engine logs its planned events every time it downloads them. */
	function dvruiEngineUpcoming($logFiles) {
		$now = time();
		$from = $now - DVRUI_ENGINE_STARTED_SECS;
		$until = $now + DVRUI_ENGINE_SOON_SECS;
		$utc = new DateTimeZone('UTC');
		$events = array();
		$ended = array();
		// oldest log first, so a later stop/ended line wins
		foreach (array_reverse(array_slice(array_keys($logFiles), 0, 2)) as $file) {
			$fh = @fopen($file, 'rb');
			if (!$fh) {
				continue;
			}
			while (($line = fgets($fh)) !== false) {
				if (strpos($line, ' Recording: ') === false) {
					continue;
				}
				// a recording that has finished:
				//   Recording: stop Élément Terre 20130411 [20261119-2020] (result = 0x0000)
				//   Recording: event Élément Terre 20130411 [20261119-2020] already ended
				if (preg_match('/ Recording: stop (.*) \[(\d{8}-\d{4})\]/', $line, $m)
						|| preg_match('/ Recording: event (.*) \[(\d{8}-\d{4})\] already ended/', $line, $m)) {
					$ended[$m[2] . ' ' . trim($m[1])] = true;
					continue;
				}
				if (!preg_match('/ Recording: event (.*) \[(\d{8}-\d{4})\] in future\s*$/', $line, $m)) {
					continue;
				}
				$start = DateTime::createFromFormat('!Ymd-Hi', $m[2], $utc);
				if ($start === false) {
					continue;
				}
				$t = $start->getTimestamp();
				if ($t >= $from && $t <= $until) {
					$events[$m[2] . ' ' . trim($m[1])] = array('name' => dvruiAjaxUtf8(trim($m[1])), 'start' => $t);
				}
			}
			fclose($fh);
		}
		$events = array_diff_key($events, $ended);
		ksort($events);
		$upcoming = array();
		foreach ($events as $event) {
			$mins = (int)round(($event['start'] - $now) / 60);
			$when = ($mins > 0) ? 'in ' . $mins . ' min' : (($mins == 0) ? 'now' : 'started ' . -$mins . ' min ago');
			// shown in the UI's time zone (DVRUI_TZ)
			$upcoming[] = $event['name'] . ' - ' . date('H:i T', $event['start']) . ' (' . $when . ')';
		}
		return $upcoming;
	}

	/* What the engine is recording, from the newest log file's last status block:
	 *   20261119-20:15:14 Status: Recording: The Context USA 20250707 [20261119-2000] from 1260AFEF (28MB)
	 * and what it is about to record (dvruiEngineUpcoming). known is false when
	 * it can't be told (no log, no status block, or the log hasn't been written
	 * to for a while). */
	function dvruiEngineRecordings() {
		$result = array('known' => false, 'recordings' => array(), 'upcoming' => array(),
			'logfile' => '', 'reason' => '');
		$logFiles = dvruiEngineLogFiles();
		if (count($logFiles) == 0) {
			$result['reason'] = 'no engine log file was found';
			return $result;
		}
		$result['upcoming'] = dvruiEngineUpcoming($logFiles);
		$newest = array_key_first($logFiles);
		$newestTime = $logFiles[$newest];
		$result['logfile'] = $newest;
		if (time() - $newestTime > DVRUI_ENGINE_LOG_STALE) {
			$result['reason'] = 'the engine log ' . basename($newest) . ' has not been written to for '
				. round((time() - $newestTime) / 60) . ' minutes';
			return $result;
		}

		// the status block is at the end - only the last part of the file is needed
		$tail = '';
		$fh = @fopen($newest, 'rb');
		if ($fh) {
			$size = filesize($newest);
			$want = 256 * 1024;
			if ($size > $want) {
				fseek($fh, $size - $want);
			}
			$tail = stream_get_contents($fh);
			fclose($fh);
		}
		$lines = preg_split('/\r\n|\n|\r/', $tail);
		$stamp = '';
		for ($i = count($lines) - 1; $i >= 0; $i--) {
			if (preg_match('/^(\d{8}-\d{2}:\d{2}:\d{2}) Status: /', $lines[$i], $m)) {
				$stamp = $m[1];
				break;
			}
		}
		if ($stamp == '') {
			$result['reason'] = 'the engine log ' . basename($newest) . ' has no status entries';
			return $result;
		}
		// the block's lines all carry the same time stamp
		for (; $i >= 0 && strncmp($lines[$i], $stamp, strlen($stamp)) == 0; $i--) {
			if (preg_match('/^\S+ Status: Recording: (.*)$/', $lines[$i], $m)) {
				array_unshift($result['recordings'], dvruiAjaxUtf8(trim($m[1])));
			}
		}
		$result['known'] = true;
		return $result;
	}

	/* The engine build for this machine - as the download's own script picks it */
	function dvruiEngineMachine() {
		$m = php_uname('m');
		if (preg_match('/^i.86$/', $m)) {
			return 'x86';
		} elseif ($m == 'x86_64') {
			return 'x64';
		} elseif ($m == 'aarch64') {
			return 'arm64';
		} elseif (strncmp($m, 'arm', 3) == 0) {
			return 'arm';
		}
		return $m;
	}

	/* Download an engine into $dir and return its version ('' if that failed) */
	function dvruiEngineFetchVersion($url, $dir) {
		$file = $dir . '/' . basename($url);
		$fh = @fopen($file, 'wb');
		if (!$fh) {
			error_log('engine update: cannot write ' . $file);
			return '';
		}
		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_FILE, $fh);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
		curl_setopt($ch, CURLOPT_TIMEOUT, 120);
		curl_setopt($ch, CURLOPT_FAILONERROR, true);
		$ok = curl_exec($ch);
		if (!$ok) {
			error_log('engine update: download of ' . $url . ' failed: ' . curl_error($ch));
		}
		curl_close($ch);
		fclose($fh);
		$version = '';
		if ($ok && filesize($file) > 0) {
			// The download is a shell script that unpacks the engine to /tmp and runs
			// it - but /tmp has the engine that's running (root's), which the web user
			// can't replace, so it would report the running version. Unpack it here.
			$bin = $dir . '/hdhomerun_record_' . dvruiEngineMachine();
			$out = shell_exec('dd if=' . escapeshellarg($file) . ' bs=4096 skip=1 2>/dev/null'
				. ' | tar -xzC ' . escapeshellarg($dir) . ' ' . escapeshellarg(basename($bin)) . ' 2>/dev/null'
				. ' && ' . escapeshellarg($bin) . ' version 2>/dev/null');
			@unlink($bin);
			// first line is "HDHomeRun RECORD version 20261107"
			if ($out != NULL) {
				$words = explode(' ', trim(strtok($out, "\r\n")));
				if (count($words) >= 4 && dvruiEngineVersionKey($words[3]) > 0) {
					$version = $words[3];
				}
			}
		}
		@unlink($file);
		return $version;
	}

	/* The newest engine SiliconDust has - the release, and the beta when the
	 * config has BetaEngine=1. Returns array(version, beta) or NULL. */
	function dvruiEngineLatest($useCache) {
		$config = new DVRUI_Engine_Config();
		$beta = ($config->getBetaEngine() == '1');
		$cacheFile = sys_get_temp_dir() . '/dvrui_engine_latest.json';
		if ($useCache && is_file($cacheFile) && time() - filemtime($cacheFile) < DVRUI_ENGINE_CACHE_SECS) {
			$cached = json_decode(file_get_contents($cacheFile), true);
			if (is_array($cached) && isset($cached['version']) && $cached['betaEngine'] === $beta) {
				return $cached;
			}
		}

		$dir = sys_get_temp_dir() . '/dvrui_engine_' . bin2hex(random_bytes(6));
		if (!@mkdir($dir, 0700)) {
			error_log('engine update: cannot create ' . $dir);
			return NULL;
		}
		$latest = NULL;
		$stable = dvruiEngineFetchVersion(DVRUI_ENGINE_URL, $dir);
		if ($stable != '') {
			$latest = array('version' => $stable, 'beta' => false);
		}
		if ($beta) {
			$betaVer = dvruiEngineFetchVersion(DVRUI_ENGINE_BETA_URL, $dir);
			if ($betaVer != '' && ($latest == NULL
					|| dvruiEngineVersionKey($betaVer) > dvruiEngineVersionKey($latest['version']))) {
				$latest = array('version' => $betaVer, 'beta' => true);
			}
		}
		@rmdir($dir);
		if ($latest != NULL) {
			$latest['betaEngine'] = $beta;
			@file_put_contents($cacheFile, json_encode($latest));
		}
		return $latest;
	}

	/* Everything the update needs to decide what to do */
	function dvruiEngineUpdateCheck($useCache) {
		$bin = new DVRUI_HDHRbintools();
		$current = $bin->get_DVR_version();
		$check = array('current' => $current, 'latest' => '', 'newer' => false,
			'recordings' => dvruiEngineRecordings(), 'error' => '');
		$latest = dvruiEngineLatest($useCache);
		if ($latest == NULL) {
			$check['error'] = 'Could not get the latest record engine from SiliconDust';
			return $check;
		}
		$check['latest'] = $latest['version'];
		if (dvruiEngineVersionKey($current) == 0) {
			// don't restart an engine that can't be told apart from the latest
			$check['error'] = 'Could not tell the version of the record engine running ('
				. $current . ') - SiliconDust has ' . $latest['version'];
			return $check;
		}
		$check['newer'] = dvruiEngineVersionKey($latest['version']) > dvruiEngineVersionKey($current);
		return $check;
	}

	function dvruiEngineUpdateReply($check, $state, $message) {
		$tab = new DVRUI_AjaxResponse();
		$tab->setHtml('statusMessage', htmlspecialchars($message));
		$tab->setData('state', $state);
		$tab->setData('message', $message);
		$tab->setData('current', $check['current']);
		$tab->setData('latest', $check['latest']);
		$tab->setData('recordingsKnown', $check['recordings']['known']);
		$tab->setData('recordings', $check['recordings']['recordings']);
		$tab->setData('recordingsReason', $check['recordings']['reason']);
		$tab->setData('upcoming', $check['recordings']['upcoming']);
		return $tab->getString();
	}

	/* Dashboard - is there a newer record engine. state is one of
	 *   error   - couldn't find out
	 *   current - the engine running is the newest, nothing to do
	 *   newer   - a newer engine is available (recordings says what's recording) */
	function checkEngineUpdate() {
		ob_start();
		$check = dvruiEngineUpdateCheck(false);
		ob_end_clean();
		if ($check['error'] != '') {
			return dvruiEngineUpdateReply($check, 'error', $check['error']);
		}
		if (!$check['newer']) {
			return dvruiEngineUpdateReply($check, 'current',
				'Record engine ' . $check['current'] . ' is the latest (SiliconDust has ' . $check['latest'] . ')');
		}
		return dvruiEngineUpdateReply($check, 'newer',
			'Record engine ' . $check['latest'] . ' is available (running ' . $check['current'] . ')');
	}

	/* Dashboard - restart the engine so it updates to the newer one. Checks
	 * again first: nothing happens unless the engine is newer, and while
	 * something is recording (or it can't be told) only with $confirmed = '1'.
	 *   state: error, current, recording (needs confirming), updating */
	function updateEngine($confirmed) {
		ob_start();
		$check = dvruiEngineUpdateCheck(true);
		ob_end_clean();
		if ($check['error'] != '') {
			return dvruiEngineUpdateReply($check, 'error', $check['error']);
		}
		if (!$check['newer']) {
			return dvruiEngineUpdateReply($check, 'current',
				'Record engine ' . $check['current'] . ' is the latest - not updating');
		}
		$rec = $check['recordings'];
		if ($confirmed !== '1' && (!$rec['known'] || count($rec['recordings']) > 0 || count($rec['upcoming']) > 0)) {
			return dvruiEngineUpdateReply($check, 'recording',
				'Not updating the record engine - recordings may be in progress or about to start');
		}

		error_log('engine update: restarting the engine to update ' . $check['current'] . ' to ' . $check['latest']
			. ($confirmed === '1' ? ' (confirmed by the user)' : ''));
		$out = shell_exec(DVRUI_SUPERVISORCTL . ' restart dvr 2>&1');
		error_log('engine update: supervisorctl: ' . trim((string)$out));
		if ($out == NULL || strpos($out, 'dvr: started') === false) {
			return dvruiEngineUpdateReply($check, 'error',
				'Could not restart the record engine: ' . trim((string)$out));
		}
		return dvruiEngineUpdateReply($check, 'updating',
			'Record engine restarting to update to ' . $check['latest'] . '...');
	}

	/* The engine's version now - the page polls this after an update */
	function engineVersion() {
		ob_start();
		$bin = new DVRUI_HDHRbintools();
		$version = $bin->get_DVR_version();
		ob_end_clean();
		$tab = new DVRUI_AjaxResponse();
		$tab->setData('version', $version);
		return $tab->getString();
	}
?>
