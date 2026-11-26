<?php
/*
 * ui/includes/dvrui_common.php
 *
 * Copyright (c) 2020 Rik Dunphy
 * Copyright (c) 2026 Silicondust USA Inc.
 * Released under the MIT License - see LICENSE
 */

	/*
	 * An HTML template from style/, without its header comment (file name and
	 * copyright) - templates are repeated for every entry of a list, so the header
	 * isn't sent to the browser
	 */
	function dvruiTemplate($name) {
		static $cache = array();
		if (!isset($cache[$name])) {
			$html = (string)@file_get_contents('style/' . $name);
			$cache[$name] = preg_replace('/^\s*<!--.*?Copyright.*?-->\r?\n?/s', '', $html, 1);
		}
		return $cache[$name];
	}

	function getJsonFromUrl($url) {
		if (in_array('curl', get_loaded_extensions())){
			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL, $url);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
			curl_setopt($ch, CURLOPT_TIMEOUT, 2);
			curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
			$json = curl_exec($ch);
		} else { 
			$context = stream_context_create(
				array('http' => array(
					'header'=>'Connection: close\r\n',
					'timeout' => 2.0)));
			// unreachable devices are expected - callers check for a null result
			$json = @file_get_contents($url,false,$context);
		}
		if (!is_string($json) || $json === '') {
			return null;
		}
		return json_decode($json, true);
	}

	/*
	 * Requests to the SiliconDust servers (DVRUI_apiurl) are cached for a short
	 * time (the SiliconDust server cache setting, default 30 seconds - 0 is off),
	 * so moving between pages doesn't ask them again for the same thing. The
	 * Reload button (clearApiCache), the Diagnostics page and anything that changes
	 * data on the servers (see rulesApiPost) clear the cache, so they get fresh
	 * answers - and those fetches fill the cache again.
	 *
	 * The cache is a file per URL in the temp folder, named by a hash of the URL
	 * (it holds the DeviceAuth), readable only by the PHP user. Only good answers
	 * (HTTP 2xx, valid JSON - "null" included) are cached; a failure is asked again.
	 */
	function dvruiApiCacheDir() {
		return sys_get_temp_dir() . '/dvrui_api_cache';
	}

	function dvruiApiCacheSecs() {
		if (!function_exists('dvruiSetting')) {
			require_once("includes/dvrui_settings.php");
		}
		return max(0, (int)dvruiSetting('DVRUI_apiCacheSecs'));
	}

	/* Forget everything cached - after a change is sent to the SiliconDust servers */
	function dvruiApiCacheClear() {
		foreach (glob(dvruiApiCacheDir() . '/*.json') ?: array() as $file) {
			@unlink($file);
		}
	}

	function dvruiApiCacheFile($url) {
		return dvruiApiCacheDir() . '/' . sha1($url) . '.json';
	}

	/* The cached answer for a URL when it is fresh - array(decoded JSON), or false */
	function dvruiApiCacheRead($url, $secs) {
		$file = dvruiApiCacheFile($url);
		if ($secs > 0 && is_file($file) && time() - filemtime($file) < $secs) {
			$json = @file_get_contents($file);
			if (is_string($json) && $json !== '') {
				return array(json_decode($json, true));
			}
		}
		return false;
	}

	/* Keep a good answer (HTTP 2xx, valid JSON) for a URL */
	function dvruiApiCacheWrite($url, $code, $json, $secs) {
		if ($secs <= 0 || $code < 200 || $code >= 300 || !is_string($json) || $json === '') {
			return;
		}
		json_decode($json);
		if (json_last_error() !== JSON_ERROR_NONE) {
			return;
		}
		$dir = dvruiApiCacheDir();
		if (!is_dir($dir) && !@mkdir($dir, 0700)) {
			return;
		}
		// write then rename, so another request never reads half a file
		$file = dvruiApiCacheFile($url);
		$tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
		if (@file_put_contents($tmp, $json) === strlen($json)) {
			@chmod($tmp, 0600);
			@rename($tmp, $file);
		} else {
			@unlink($tmp);
		}
	}

	/* Drop cached answers that have expired (e.g. for an old DeviceAuth) */
	function dvruiApiCachePrune($secs) {
		foreach (glob(dvruiApiCacheDir() . '/*.json') ?: array() as $old) {
			if (time() - @filemtime($old) >= $secs) {
				@unlink($old);
			}
		}
	}

	/* GET a SiliconDust server URL, from the cache when it is fresh - like getJsonFromUrl */
	function getJsonFromApi($url) {
		$results = getJsonFromApis(array(0 => $url), 1);
		return $results[0];
	}

	/* GET several SiliconDust server URLs in parallel, each from the cache when it is
	 * fresh - like getJsonFromUrls (key => url in, key => decoded JSON out) */
	function getJsonFromApis($urls, $parallel = 8) {
		$secs = dvruiApiCacheSecs();
		$results = array();
		$fetch = array();
		foreach ($urls as $key => $url) {
			$cached = dvruiApiCacheRead($url, $secs);
			if ($cached !== false) {
				$results[$key] = $cached[0];
			} else {
				$fetch[$key] = $url;
			}
		}
		if (count($fetch)) {
			if ($secs > 0) {
				dvruiApiCachePrune($secs);
			}
			foreach (dvruiFetchUrls($fetch, $parallel) as $key => $answer) {
				list($code, $json) = $answer;
				dvruiApiCacheWrite($fetch[$key], $code, $json, $secs);
				$results[$key] = (is_string($json) && $json !== '') ? json_decode($json, true) : null;
			}
		}
		// in the order asked for
		$ordered = array();
		foreach ($urls as $key => $url) {
			$ordered[$key] = $results[$key];
		}
		return $ordered;
	}

	/*
	 * Fetch several JSON URLs in parallel (curl multi), at most $parallel at a time.
	 * $urls is an array of key => url; returns key => decoded JSON (null on failure).
	 * Falls back to fetching one at a time if curl isn't available.
	 */
	function getJsonFromUrls($urls, $parallel = 8) {
		$results = array();
		foreach (dvruiFetchUrls($urls, $parallel) as $key => $answer) {
			$results[$key] = (is_string($answer[1]) && $answer[1] !== '') ? json_decode($answer[1], true) : null;
		}
		return $results;
	}

	/*
	 * GET several URLs, at most $parallel at a time - key => array(HTTP status, body
	 * or null). Without curl they are fetched one at a time (status 200 if it worked).
	 */
	function dvruiFetchUrls($urls, $parallel = 8) {
		$results = array();
		if (!function_exists('curl_multi_init')) {
			foreach ($urls as $key => $url) {
				$context = stream_context_create(array('http' => array(
					'header' => "Connection: close\r\n", 'timeout' => 2.0)));
				$body = @file_get_contents($url, false, $context);
				$results[$key] = ($body === false) ? array(0, null) : array(200, $body);
			}
			return $results;
		}

		$mh = curl_multi_init();
		$queue = $urls;
		$active = array();
		do {
			// keep up to $parallel requests running
			while (count($active) < $parallel && count($queue) > 0) {
				$key = array_key_first($queue);
				$url = $queue[$key];
				unset($queue[$key]);   // (array_shift would renumber integer keys)
				$ch = curl_init();
				curl_setopt($ch, CURLOPT_URL, $url);
				curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
				curl_setopt($ch, CURLOPT_TIMEOUT, 2);
				curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
				curl_multi_add_handle($mh, $ch);
				$active[(int)$ch] = array($key, $ch);
			}
			curl_multi_exec($mh, $running);
			if ($running) {
				curl_multi_select($mh, 0.5);
			}
			while ($info = curl_multi_info_read($mh)) {
				$id = (int)$info['handle'];
				list($key, $ch) = $active[$id];
				$body = curl_multi_getcontent($ch);
				$results[$key] = array((int)curl_getinfo($ch, CURLINFO_HTTP_CODE), is_string($body) ? $body : null);
				curl_multi_remove_handle($mh, $ch);
				unset($active[$id]);
			}
		} while (count($active) > 0 || count($queue) > 0);
		curl_multi_close($mh);
		return $results;
	}

	// The full UI (Recordings and series pages) - DVRUI_ENABLE=minimal is the Dashboard
	// and Diagnostics only (none doesn't start the web server at all). Anything but minimal is full.
	function dvruiFullUI() {
		return !defined('DVRUI_Vars::DVRUI_ENABLE') || strtolower(DVRUI_Vars::DVRUI_ENABLE) !== 'minimal';
	}

	/*
	 * Whether dates should be written month first ("Nov 18 2026") for the UI's
	 * timezone (DVRUI_TZ, applied to php.ini date.timezone). Only US timezones are
	 * month first - everywhere else uses day first ("18 Nov 2026").
	 */
	function dvruiMonthFirst($tz = null) {
		if ($tz === null) {
			$tz = date_default_timezone_get();
		}
		if (strpos($tz, 'US/') === 0) {
			return true;
		}
		$us = array('America/New_York', 'America/Detroit', 'America/Chicago', 'America/Denver',
			'America/Phoenix', 'America/Los_Angeles', 'America/Anchorage', 'America/Juneau',
			'America/Sitka', 'America/Yakutat', 'America/Nome', 'America/Metlakatla', 'America/Adak',
			'America/Boise', 'America/Menominee', 'Pacific/Honolulu', 'EST5EDT', 'CST6CDT',
			'MST7MDT', 'PST8PDT');
		return in_array($tz, $us)
			|| strpos($tz, 'America/Indiana/') === 0
			|| strpos($tz, 'America/Kentucky/') === 0
			|| strpos($tz, 'America/North_Dakota/') === 0;
	}

	// A date without the day name, in the order used for the UI's timezone
	function dvruiDateFormat() {
		return dvruiMonthFirst() ? 'M j Y' : 'j M Y';
	}

	/*
	 * Readable day for a date (a DateTime at any time of that day, in the UI timezone):
	 * "Today", the short day name for the 7 days before ("Tue", "Mon", ... including
	 * last week's same day, as today is shown as "Today"), otherwise the date
	 * ("18 Nov 2026", or "Nov 18 2026" for US timezones). Counted by calendar day.
	 */
	function dvruiRelativeDay($date, $now = null) {
		$tz = new DateTimeZone(date_default_timezone_get());
		$day = clone $date;
		$day->setTimezone($tz);
		$day->setTime(0, 0);
		$today = new DateTime('@' . (($now === null) ? time() : $now));
		$today->setTimezone($tz);
		$today->setTime(0, 0);
		$daysAgo = (int)$day->diff($today)->format('%r%a');
		if ($daysAgo == 0) {
			return 'Today';
		}
		if ($daysAgo > 0 && $daysAgo <= 7) {
			return $day->format('D');
		}
		return $day->format(dvruiDateFormat());
	}

	/*
	 * Readable label for a record engine log file named yyyymmdd (see dvruiRelativeDay).
	 * The engine names its logs by the UTC date, which can be ahead of the date in the
	 * UI timezone (e.g. evenings in the Americas) - a log dated after today is "Today".
	 * Anything that isn't a valid yyyymmdd date is returned unchanged.
	 */
	function dvruiLogDate($yyyymmdd, $now = null) {
		$tz = new DateTimeZone(date_default_timezone_get());
		$day = DateTime::createFromFormat('!Ymd', $yyyymmdd, $tz);
		if ($day === false || $day->format('Ymd') !== $yyyymmdd) {
			return $yyyymmdd;
		}
		$today = new DateTime('@' . (($now === null) ? time() : $now));
		$today->setTimezone($tz);
		if ($yyyymmdd > $today->format('Ymd')) {
			return 'Today';
		}
		return dvruiRelativeDay($day, $now);
	}

	function postToUrl($url, $vars) {
		if (in_array('curl', get_loaded_extensions())){
			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL, $url);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
			curl_setopt($ch, CURLOPT_TIMEOUT, 2);
			curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
			curl_setopt($ch, CURLOPT_POST, 1);
			curl_setopt($ch, CURLOPT_POSTFIELDS, $vars);
			$result = curl_exec($ch);
		} else { 
			$context = stream_context_create(
				array('http' => array(
					'header'=>'Connection: close\r\n',
					'method' => 'POST',
					'content' => $vars,
					'timeout' => 2.0)));
			$result = file_get_contents($url,false,$context);	
		}
	}
?>
