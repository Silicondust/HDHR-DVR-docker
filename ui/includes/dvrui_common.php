<?php
	function getJsonFromUrl($url) {
		if (in_array('curl', get_loaded_extensions())){
			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL, $url);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
			curl_setopt($ch, CURLOPT_TIMEOUT, 2);
			curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
			$json = curl_exec($ch);
			curl_close($ch);
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
			curl_close($ch);
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
