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
	 * Fetch several JSON URLs in parallel (curl multi), at most $parallel at a time.
	 * $urls is an array of key => url; returns key => decoded JSON (null on failure).
	 * Falls back to fetching one at a time if curl isn't available.
	 */
	function getJsonFromUrls($urls, $parallel = 8) {
		$results = array();
		if (!function_exists('curl_multi_init')) {
			foreach ($urls as $key => $url) {
				$results[$key] = getJsonFromUrl($url);
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
				$json = curl_multi_getcontent($ch);
				$results[$key] = (is_string($json) && $json !== '') ? json_decode($json, true) : null;
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
