<?php
/*
 * ui/recorded.php
 *
 * Copyright (c) 2020 Rik Dunphy
 * Copyright (c) 2026 Silicondust USA Inc.
 * Released under the MIT License - see LICENSE
 */
	require_once("includes/dvrui_ajax.php");
	require_once("vars.php");
	require_once("statusmessage.php");
	require_once("includes/dvrui_common.php");
	require_once("includes/dvrui_recordengine_config.php");
	require_once("includes/dvrui_hdhrjson.php");

	/*
	 * Recorded page - one card per recorded series (image, title, description)
	 */
	function openRecordedPage() {
		// prep
		ob_start();
		// Series data from the engine is already UTF-8 - don't re-encode it
		$tab = new DVRUI_AjaxResponse();

		//create output
		$htmlStr = loadRecordedSeries();

		$result = ob_get_contents();
		ob_end_clean();


		//display
		$tab->setHtml("recorded_box", $htmlStr);
		$tab->output($result);
		return $tab->getString();
	}

	function loadRecordedSeries() {
		return buildRecordedPage(getRecordEngines());
	}

	/*
	 * Every record engine found by discovery, this container's engine first
	 * Returns array of array('Name', 'StorageURL', 'StorageID', 'Local')
	 */
	function getRecordEngines() {
		$serverConfig = new DVRUI_Engine_Config();
		$myStorageID = $serverConfig->getStorageId();
		$hdhr = new DVRUI_HDHRjson();

		$engines = array();
		for ($i = 0; $i < $hdhr->engine_count(); $i++) {
			$host = parse_url($hdhr->get_engine_baseurl($i), PHP_URL_HOST);
			$isLocal = (strcasecmp($hdhr->get_engine_storageId($i), $myStorageID) == 0);
			$engines[] = array(
				'Name' => $hdhr->get_engine_modelname($i) . ($host ? ' (' . $host . ')' : '')
					. ($isLocal ? ' - this engine' : ''),
				'Host' => $host ? $host : '',
				'StorageURL' => $hdhr->get_engine_storageUrl($i),
				'StorageID' => $hdhr->get_engine_storageId($i),
				'Local' => $isLocal);
		}
		usort($engines, function($a, $b) { return $b['Local'] <=> $a['Local']; });
		return $engines;
	}

	/*
	 * Engine filter bar across the top, then one card per series.
	 * A series recorded on several engines gets a single card listing each engine.
	 * Cards and engine lines are tagged with their engine(s) so the filter works
	 * in the browser without requesting the series again.
	 */
	function buildRecordedPage($engines) {
		if (count($engines) == 0) {
			return "<p class='recorded_msg'>No record engines found - check the Diagnostics page</p>";
		}

		$options = "<option value='all' selected>All engines</option>";
		$messages = '';
		$emptyNotes = '';
		$allSeries = array();   // de-duplicated, keyed by SeriesID (or title)

		foreach ($engines as $e => $engine) {
			$engineKey = 'engine' . $e;
			$engineName = htmlspecialchars($engine['Name']);
			// lets the dashboard pick this engine in the filter (see openEngineRecordings)
			$engineAttr = " data-storageid='" . htmlspecialchars($engine['StorageID'], ENT_QUOTES) . "'";
			$seriesList = getJsonFromUrl($engine['StorageURL'] . '?DisplayGroupID=root');

			if (!is_array($seriesList)) {
				$options .= "<option value='" . $engineKey . "'" . $engineAttr . ">" . $engineName . " - unavailable</option>";
				$messages .= "<p class='recorded_msg' data-engine='" . $engineKey . "'>Unable to load recorded series from "
					. $engineName . "</p>";
				continue;
			}

			$count = 0;
			foreach ($seriesList as $series) {
				if (!is_array($series)) {
					continue;
				}
				$title = recordedField($series, array('Title'), 'Unknown');
				$key = strtolower(recordedField($series, array('SeriesID'), 'title:' . $title));
				if (!isset($allSeries[$key])) {
					$allSeries[$key] = array('Title' => $title, 'Image' => '', 'Engines' => array(),
						'Group' => recordedGroup(recordedField($series, array('Category'), '')));
				}
				// keep the first usable image any engine provides
				$image = recordedField($series, array('ImageURL'), '');
				if ($allSeries[$key]['Image'] == '' && preg_match('#^https?://#i', $image)) {
					$allSeries[$key]['Image'] = $image;
				}
				if (!isset($allSeries[$key]['Engines'][$engineKey])) {
					$allSeries[$key]['Engines'][$engineKey] = array(
						// cards just show the engine's IP (full name is in the filter dropdown)
						'Name' => 'Engine: ' . htmlspecialchars(!empty($engine['Host']) ? $engine['Host'] : $engine['Name']),
						'EpisodesURL' => recordedField($series, array('EpisodesURL'), ''),
						'Count' => 0,
						'Latest' => 0);
					$count++;
				}
			}
			$options .= "<option value='" . $engineKey . "'" . $engineAttr . ">" . $engineName . " - " . $count . " series</option>";
			if ($count == 0) {
				// hidden in the All view - only shown when filtered to this engine
				$emptyNotes .= "<p class='recorded_msg' data-engine='" . $engineKey . "' data-filtered-only='1' style='display:none'>"
					. "No recordings on " . $engineName . "</p>";
			}
		}

		// Ask each engine for the recordings of each of its series (in parallel)
		$episodeURLs = array();
		foreach ($allSeries as $key => $series) {
			foreach ($series['Engines'] as $engineKey => $engine) {
				if ($engine['EpisodesURL'] != '') {
					$episodeURLs[$key . '|' . $engineKey] = $engine['EpisodesURL'];
				}
			}
		}
		$recent = array();   // every recording, for the Latest recordings cards
		foreach (getJsonFromUrls($episodeURLs) as $fetchKey => $episodes) {
			list($key, $engineKey) = explode('|', $fetchKey, 2);
			list($count, $latest) = recordedEpisodeStats($episodes);
			$allSeries[$key]['Engines'][$engineKey]['Count'] = $count;
			$allSeries[$key]['Engines'][$engineKey]['Latest'] = $latest;
			recordedCollectEpisodes($recent, $key, $episodes);
		}

		// Most recently recorded first (latest across all engines), then by title
		foreach ($allSeries as $key => $series) {
			$allSeries[$key]['Latest'] = max(array_column($series['Engines'], 'Latest'));
		}
		uasort($allSeries, function($a, $b) {
			return ($b['Latest'] <=> $a['Latest']) ?: strcasecmp($a['Title'], $b['Title']);
		});

		// trimmed so no whitespace between the inline-block cards adds to the gap
		$entryTemplate = trim(dvruiTemplate('recorded_entry.html'));
		$groupCards = array();
		$groupCounts = array();
		foreach (array_keys(recordedGroups()) as $group) {
			$groupCards[$group] = '';
			$groupCounts[$group] = 0;
		}
		foreach ($allSeries as $seriesKey => $series) {
			// per-engine figures go on each engine line (used when filtered to that engine),
			// the combined figures on the card (used for All engines)
			$engineLines = '';
			$totalCount = 0;
			$totalLatest = 0;
			foreach ($series['Engines'] as $engineKey => $engine) {
				$engineLines .= "<p class='recorded_engine' data-engine='" . $engineKey . "'"
					. " data-count='" . $engine['Count'] . "'"
					. " data-latest='" . $engine['Latest'] . "'"
					. " data-last='" . htmlspecialchars(recordedDate($engine['Latest']), ENT_QUOTES) . "'>"
					. $engine['Name'] . "</p>";
				$totalCount += $engine['Count'];
				$totalLatest = max($totalLatest, $engine['Latest']);
			}
			$lastText = recordedDate($totalLatest);
			$card = str_replace('<!--recorded_total_count-->', $totalCount, $entryTemplate);
			$card = str_replace('<!--recorded_total_latest-->', $totalLatest, $card);
			$card = str_replace('<!--recorded_total_last-->', htmlspecialchars($lastText, ENT_QUOTES), $card);
			$card = str_replace('<!--recorded_badge_style-->', ($totalCount > 1) ? '' : " style='display:none'", $card);
			$card = str_replace('<!--recorded_badge_count-->', $totalCount, $card);
			$card = str_replace('<!--recorded_last_style-->', ($lastText != '') ? '' : " style='visibility:hidden'", $card);
			$card = str_replace('<!--recorded_last-->', htmlspecialchars($lastText), $card);

			$image = ($series['Image'] != '') ? $series['Image'] : './images/no-preview.png';
			// the series page looks the series up again by this key (see series.php)
			$card = str_replace('<!--recorded_series_key-->', htmlspecialchars($seriesKey, ENT_QUOTES), $card);
			$card = str_replace('<!--recorded_engine_keys-->', implode(' ', array_keys($series['Engines'])), $card);
			$card = str_replace('<!--recorded_img_class-->', ($series['Image'] != '') ? '' : ' no_preview', $card);
			$card = str_replace('<!--recorded_image-->', htmlspecialchars($image, ENT_QUOTES), $card);
			$card = str_replace('<!--recorded_title-->', htmlspecialchars($series['Title']), $card);
			$card = str_replace('<!--recorded_engines-->', $engineLines, $card);
			$groupCards[$series['Group']] .= $card;
			$groupCounts[$series['Group']]++;
		}

		$total = count($allSeries);
		if ($total == 0) {
			$messages .= "<p class='recorded_msg'>No recordings available yet</p>";
		} else {
			$messages .= $emptyNotes;
			// shown by the filter when the chosen engine + category has nothing to show
			$messages .= "<p id='recorded_no_match' class='recorded_msg' data-filtered-only='1' style='display:none'>"
				. "No matching recordings</p>";
		}

		// Every card reserves room for the most engine lines any card has, so all cards
		// are the same size (the filter drops this to 1 when showing a single engine)
		$engineLines = 1;
		foreach ($allSeries as $series) {
			$engineLines = max($engineLines, count($series['Engines']));
		}

		// One section per category (in recordedGroups() order), empty ones left out
		$sections = '';
		$groupOptions = "<option value='all' selected>All categories</option>";
		foreach (recordedGroups() as $group => $label) {
			if ($groupCounts[$group] == 0) {
				continue;
			}
			$groupOptions .= "<option value='" . $group . "'>" . $label . "</option>";
			$sections .= "<div class='recorded_group' data-group='" . $group . "'>"
				. "<h3 class='recorded_group_title'>" . $label
				. " <span class='recorded_group_count'>(" . $groupCounts[$group] . ")</span></h3>"
				. "<div class='recorded_group_list'>" . $groupCards[$group] . "</div></div>";
		}

		$filter = "<div id='recorded_filter'>"
			. "<label for='recorded_engine_select'>Record engine: </label>"
			. "<select id='recorded_engine_select' onchange='filterRecorded()'>" . $options . "</select>"
			. "<label for='recorded_group_select'>Category: </label>"
			. "<select id='recorded_group_select' class='recorded_group_select' onchange='filterRecorded()'>" . $groupOptions . "</select>"
			. "<span id='recorded_count'>" . $total . " series</span>"
			. "</div>";
		// the whole page - Latest recordings, the filters and the series cards - scrolls
		// up and down together, with arrows instead of a scrollbar
		return dvruiVScroller('recorded_scroller', "id='recorded_scroll'", '.recorded_entry',
			recordedLatestList($allSeries, recordedRecentEpisodes($recent, 48 * 3600, 10))
			. $filter . "<div id='recorded_messages'>" . $messages . "</div>"
			. "<div id='recorded_list' data-engine-lines='" . $engineLines . "' style='--engine-lines: " . $engineLines . "'>"
			. $sections . "</div>");
	}

	// Sections on the Recorded page, in display order
	function recordedGroups() {
		return array('tv' => 'TV', 'movie' => 'Movies', 'sport' => 'Sports');
	}

	// SiliconDust Category -> section: movie and sport have their own, everything else
	// (series, news, unknown) is TV
	function recordedGroup($category) {
		$category = strtolower(trim($category));
		if ($category == 'movie') {
			return 'movie';
		}
		if ($category == 'sport' || $category == 'sports') {
			return 'sport';
		}
		return 'tv';
	}

	/*
	 * Add an engine's recordings of one series to the Latest recordings candidates:
	 * series key, recording time (RecordStartTime, else StartTime) and the episode.
	 * The same recording on two engines (same series, episode and time) is kept once.
	 */
	function recordedCollectEpisodes(&$recent, $seriesKey, $episodes) {
		if (!is_array($episodes)) {
			return;
		}
		foreach ($episodes as $episode) {
			if (!is_array($episode)) {
				continue;
			}
			$time = (int)recordedField($episode, array('RecordStartTime', 'StartTime'), '0');
			if ($time <= 0) {
				continue;
			}
			$id = $seriesKey . '|' . $time . '|' . recordedField($episode, array('EpisodeNumber', 'EpisodeTitle', 'ProgramID'), '');
			$recent[$id] = array('Key' => $seriesKey, 'Time' => $time, 'Episode' => $episode);
		}
	}

	/*
	 * The recordings to show, newest first: everything recorded in the last $window
	 * seconds, or the latest $minimum if that's fewer
	 */
	function recordedRecentEpisodes($recent, $window, $minimum, $now = null) {
		$recent = array_values($recent);
		usort($recent, function($a, $b) { return $b['Time'] <=> $a['Time']; });
		$since = (($now === null) ? time() : $now) - $window;
		$shown = array();
		foreach ($recent as $i => $item) {
			if ($i >= $minimum && $item['Time'] < $since) {
				break;
			}
			$shown[] = $item;
		}
		return $shown;
	}

	/*
	 * Latest recordings, above the filters: one card per recording (see
	 * recordedRecentEpisodes), from all engines and categories, so the filters don't
	 * change it. Cards like the ones below: the series image and title, the episode,
	 * and when it was recorded. Clicking the image opens the series page.
	 * The cards are outside the category sections, so filterRecorded/sortRecorded
	 * leave them alone.
	 */
	function recordedLatestList($allSeries, $recent) {
		$cards = '';
		foreach ($recent as $item) {
			$seriesKey = $item['Key'];
			$series = $allSeries[$seriesKey];
			$episode = $item['Episode'];
			$number = recordedField($episode, array('EpisodeNumber'), '');
			$name = recordedField($episode, array('EpisodeTitle'), '');
			if (strcasecmp($name, $series['Title']) == 0) {
				$name = '';   // movies and specials repeat the series title
			}
			$episodeText = trim(($number != '' ? "<span class='recorded_latest_number'>" . htmlspecialchars($number) . "</span> " : '')
				. htmlspecialchars($name));
			$image = ($series['Image'] != '') ? $series['Image'] : './images/no-preview.png';
			$cards .= "<div class='recorded_entry recorded_latest' data-seriesid='" . htmlspecialchars($seriesKey, ENT_QUOTES) . "'>"
				. "<div class='recorded_img_cnt'>"
				. "<img src='" . htmlspecialchars($image, ENT_QUOTES) . "'"
				. " class='recorded_img" . (($series['Image'] != '') ? '' : ' no_preview') . "' loading='lazy'"
				. " title='Show the recordings of this series' onclick='openSeries(event, this)'"
				. " onerror=\"this.onerror=null;this.className+=' no_preview';this.src='./images/no-preview.png';\"/>"
				. "</div>"
				. "<div class='recorded_info'>"
				. "<h4 class='recorded_title'>" . htmlspecialchars($series['Title']) . "</h4>"
				// kept (hidden) when there's no episode so every card is the same size
				. "<p class='recorded_last'" . ($episodeText != '' ? '' : " style='visibility:hidden'") . ">"
				. ($episodeText != '' ? $episodeText : '&nbsp;') . "</p>"
				. "<p class='recorded_latest_when'>Recorded: " . htmlspecialchars(recordedDayDate($item['Time'])) . "</p>"
				. "</div></div>";
		}
		if ($cards == '') {
			return '';
		}
		// one row that scrolls sideways - the arrows on each side page through it and
		// are only shown when there's more that way (see scrollLatest/updateLatestArrows)
		return "<div id='recorded_latest'>"
			. "<h3 class='recorded_group_title'>Latest recordings</h3>"
			. "<div class='recorded_latest_scroller'>"
			. "<button type='button' class='recorded_latest_arrow left' aria-label='Earlier recordings' title='Earlier recordings'"
			. " onclick='scrollLatest(-1)' hidden>&#x276E;</button>"
			. "<div class='recorded_latest_list' onscroll='updateLatestArrows()'>" . $cards . "</div>"
			. "<button type='button' class='recorded_latest_arrow right' aria-label='More recordings' title='More recordings'"
			. " onclick='scrollLatest(1)' hidden>&#x276F;</button>"
			. "</div></div>";
	}

	/*
	 * Number of recordings in an engine's episode list, and the most recent
	 * recording time (RecordStartTime, else StartTime) as a unix timestamp
	 */
	function recordedEpisodeStats($episodes) {
		if (!is_array($episodes)) {
			return array(0, 0);
		}
		$count = 0;
		$latest = 0;
		foreach ($episodes as $episode) {
			if (!is_array($episode)) {
				continue;
			}
			$count++;
			$time = (int)recordedField($episode, array('RecordStartTime', 'StartTime'), '0');
			$latest = max($latest, $time);
		}
		return array($count, $latest);
	}

	/*
	 * Day, date and time in the UI timezone: "Tue 24 Nov 21:00" ("Tue Nov 24 21:00"
	 * for US timezones), with the year added when it isn't this year
	 */
	function recordedDayDate($timestamp, $now = null) {
		if ($timestamp <= 0) {
			return '';
		}
		$thisYear = date('Y', ($now === null) ? time() : $now);
		$date = dvruiMonthFirst() ? 'D M j' : 'D j M';
		if (date('Y', $timestamp) != $thisYear) {
			$date .= ' Y';
		}
		return date($date . ' H:i', $timestamp);
	}

	/*
	 * Recording time in the UI's timezone (DVRUI_TZ, applied to php.ini date.timezone):
	 * the day as dvruiRelativeDay gives it, plus the time - "Today 21:00", "Tue 21:00"
	 * for the 7 days before, older "26 Aug 2024 01:59" ("Aug 26 2024 01:59" for US timezones)
	 */
	function recordedDate($timestamp, $now = null) {
		if ($timestamp <= 0) {
			return '';
		}
		return dvruiRelativeDay(new DateTime('@' . $timestamp), $now) . ' ' . date('H:i', $timestamp);
	}

	// First non-empty value of the given keys
	function recordedField($series, $keys, $default) {
		foreach ($keys as $key) {
			if (array_key_exists($key, $series) && is_scalar($series[$key]) && trim((string)$series[$key]) != '') {
				return trim((string)$series[$key]);
			}
		}
		return $default;
	}
?>
