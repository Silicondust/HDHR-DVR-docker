<?php
	require_once("TinyAjaxBehavior.php");
	require_once("vars.php");
	require_once("statusmessage.php");
	require_once("includes/dvrui_common.php");
	require_once("recorded.php");

	/*
	 * Series page - opened by clicking a series image on the Recordings page.
	 * Seasons (or years, when the episodes have no season number) down the left,
	 * that group's episodes on the right.
	 *
	 * $seriesKey is the Recordings page's de-duplication key (lower case SeriesID,
	 * or "title:<title>"); $storageId limits it to one engine ('' = every engine) -
	 * set from the Recordings page's engine filter, then by the page's own filter.
	 * The episode lists are looked up from each engine's own series list, so only
	 * URLs the engines provide are ever fetched.
	 */
	function openSeriesPage($seriesKey, $storageId) {
		// prep
		ob_start();
		// Episode data from the engine is already UTF-8 - don't re-encode it
		$tab = new TinyAjaxBehavior(true);

		//create output
		$htmlStr = buildSeriesPage(getRecordEngines(), (string)$seriesKey, (string)$storageId);

		$result = ob_get_contents();
		ob_end_clean();

		// get latest status
		$statusmsg = getLatestHDHRStatus();

		//display
		$tab->add(TabInnerHtml::getBehavior("series_box", $htmlStr));
		if ($result != '' && $result != NULL)
			$tab->add(TabInnerHtml::getBehavior("statusMessage", $result));
		else
			$tab->add(TabInnerHtml::getBehavior("statusMessage", $statusmsg));
		return $tab->getString();
	}

	/*
	 * Command bar action on the selected episodes, then the page again (same engine
	 * filter and season) with the outcome in the status bar.
	 *   watched / unwatched - set the resume point to the end (4294967295) or the start
	 *   remove - delete, but let it record again if it airs again (rerecord=1)
	 *   kill   - delete, and remember it as recorded so it isn't recorded again (rerecord=0)
	 * $items is "storageId:recordingId,..." from the page. Only the CmdURL the engine
	 * itself lists for that recording is used, so nothing else can be sent commands.
	 */
	function seriesActionPage($seriesKey, $storageId, $action, $items, $activeGroup) {
		ob_start();
		$tab = new TinyAjaxBehavior(true);

		$engines = getRecordEngines();
		$status = seriesRunAction($engines, (string)$seriesKey, (string)$action, (string)$items);
		$htmlStr = buildSeriesPage($engines, (string)$seriesKey, (string)$storageId, (string)$activeGroup);

		$result = ob_get_contents();
		ob_end_clean();

		$tab->add(TabInnerHtml::getBehavior("series_box", $htmlStr));
		$tab->add(TabInnerHtml::getBehavior("statusMessage", ($result != '' && $result != NULL) ? $result : htmlspecialchars($status)));
		return $tab->getString();
	}

	function seriesRunAction($engines, $seriesKey, $action, $items) {
		$commands = array(
			'watched' => array('cmd' => 'set', 'Resume' => '4294967295'),
			'unwatched' => array('cmd' => 'set', 'Resume' => '0'),
			'remove' => array('cmd' => 'delete', 'rerecord' => '1'),
			'kill' => array('cmd' => 'delete', 'rerecord' => '0'));
		$done = array('watched' => 'Set %s as watched', 'unwatched' => 'Set %s as unwatched',
			'remove' => 'Removed %s (can record again)', 'kill' => 'Deleted %s permanently');
		if (!isset($commands[$action])) {
			return 'Unknown action';
		}

		// the selected recordings, by engine
		$wanted = array();
		foreach (explode(',', $items) as $item) {
			$parts = explode(':', trim($item), 2);
			if (count($parts) == 2 && $parts[0] !== '' && preg_match('/^[A-Za-z0-9]+$/', $parts[1])) {
				$wanted[strtolower($parts[0])][$parts[1]] = true;
			}
		}
		if (count($wanted) == 0) {
			return 'Nothing selected';
		}

		// look the recordings up in each engine's episode list for this series
		$cmdURLs = array();
		foreach (seriesEpisodeLists($engines, $seriesKey) as $e => $episodes) {
			$sid = strtolower($engines[$e]['StorageID']);
			if (!isset($wanted[$sid])) {
				continue;
			}
			foreach ($episodes as $episode) {
				$cmdURL = is_array($episode) ? recordedField($episode, array('CmdURL'), '') : '';
				if (preg_match('/[?&]id=([A-Za-z0-9]+)/', $cmdURL, $m) && isset($wanted[$sid][$m[1]])) {
					$cmdURLs[] = $cmdURL;
				}
			}
		}

		$ok = 0;
		foreach ($cmdURLs as $cmdURL) {
			// the CmdURL's own parameters (id=...) are kept, the command is added to them
			$url = $cmdURL . ((strpos($cmdURL, '?') === false) ? '?' : '&') . http_build_query($commands[$action]);
			if (seriesPost($url)) {
				$ok++;
			}
		}
		$selected = array_sum(array_map('count', $wanted));
		$msg = sprintf($done[$action], $ok . ' of ' . $selected . ' recording' . ($selected == 1 ? '' : 's'));
		if ($ok < $selected) {
			$msg .= ' - ' . ($selected - $ok) . ' failed or no longer there';
		}
		error_log('Series action ' . $action . ' on ' . $seriesKey . ': ' . $msg);
		return $msg;
	}

	// Each engine's episode list for the series (same lookup as the page), keyed like $engines
	function seriesEpisodeLists($engines, $seriesKey) {
		$rootURLs = array();
		foreach ($engines as $e => $engine) {
			$rootURLs[$e] = $engine['StorageURL'] . '?DisplayGroupID=root';
		}
		$episodeURLs = array();
		foreach (getJsonFromUrls($rootURLs) as $e => $seriesList) {
			foreach ((array)$seriesList as $series) {
				if (!is_array($series)) {
					continue;
				}
				$key = strtolower(recordedField($series, array('SeriesID'), 'title:' . recordedField($series, array('Title'), 'Unknown')));
				$url = recordedField($series, array('EpisodesURL'), '');
				if ($key === $seriesKey && $url != '') {
					$episodeURLs[$e] = $url;
				}
			}
		}
		$lists = array();
		foreach (getJsonFromUrls($episodeURLs) as $e => $episodes) {
			$lists[$e] = is_array($episodes) ? $episodes : array();
		}
		return $lists;
	}

	// The engine only accepts commands as a POST (a GET gets 400 Bad Request)
	function seriesPost($url) {
		if (!function_exists('curl_init')) {
			$context = stream_context_create(array('http' => array('method' => 'POST',
				'header' => "Content-Length: 0\r\nConnection: close\r\n", 'timeout' => 5.0)));
			return @file_get_contents($url, false, $context) !== false;
		}
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, '');
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_TIMEOUT, 5);
		curl_exec($ch);
		$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		return $code >= 200 && $code < 300;
	}

	// Watched to the end: Resume 4294967295, or a resume point at/after the end
	function seriesIsWatched($info) {
		return $info['Resume'] !== null
			&& ($info['Resume'] < 0 || ($info['Duration'] > 0 && $info['Resume'] >= $info['Duration']));
	}

	function buildSeriesPage($engines, $seriesKey, $storageId, $activeGroup = '') {
		if (count($engines) == 0) {
			return "<p class='recorded_msg'>No record engines found - check the Diagnostics page</p>";
		}

		// Find the series in each engine's list (same key as the Recordings page uses).
		// Every engine is looked at, so the engine filter can list all that have it.
		$rootURLs = array();
		foreach ($engines as $e => $engine) {
			$rootURLs[$e] = $engine['StorageURL'] . '?DisplayGroupID=root';
		}
		$title = '';
		$image = '';
		$episodeURLs = array();
		foreach (getJsonFromUrls($rootURLs) as $e => $seriesList) {
			if (!is_array($seriesList)) {
				continue;
			}
			foreach ($seriesList as $series) {
				if (!is_array($series)) {
					continue;
				}
				$seriesTitle = recordedField($series, array('Title'), 'Unknown');
				$key = strtolower(recordedField($series, array('SeriesID'), 'title:' . $seriesTitle));
				if ($key !== $seriesKey) {
					continue;
				}
				if ($title == '') {
					$title = $seriesTitle;
				}
				$seriesImage = recordedField($series, array('ImageURL'), '');
				if ($image == '' && preg_match('#^https?://#i', $seriesImage)) {
					$image = $seriesImage;
				}
				$url = recordedField($series, array('EpisodesURL'), '');
				if ($url != '') {
					$episodeURLs[$e] = $url;
				}
			}
		}
		if (count($episodeURLs) == 0) {
			return "<p class='recorded_msg'>This series is no longer available - use Reload on the Recordings page</p>";
		}

		// Every recording of the series on each engine
		$byEngine = array();
		foreach (getJsonFromUrls($episodeURLs) as $e => $episodes) {
			$byEngine[$e] = array();
			if (!is_array($episodes)) {
				continue;
			}
			foreach ($episodes as $episode) {
				if (is_array($episode)) {
					$info = seriesEpisodeInfo($episode);
					$info['Engine'] = !empty($engines[$e]['Host']) ? $engines[$e]['Host'] : $engines[$e]['Name'];
					$info['StorageID'] = $engines[$e]['StorageID'];
					$byEngine[$e][] = $info;
				}
			}
		}
		ksort($byEngine);   // same order as the Recordings page (this container's engine first)

		// Engine filter: All, or one engine (as chosen on the Recordings page, or here).
		// An engine that doesn't have the series (any more) falls back to All.
		$selected = null;
		foreach ($byEngine as $e => $list) {
			if ($storageId != '' && strcasecmp($engines[$e]['StorageID'], $storageId) == 0) {
				$selected = $e;
			}
		}
		$allCount = array_sum(array_map('count', $byEngine));
		$engineOptions = "<option value=''" . (($selected === null) ? ' selected' : '') . ">All engines - "
			. $allCount . " episode" . ($allCount == 1 ? '' : 's') . "</option>";
		foreach ($byEngine as $e => $list) {
			$engineOptions .= "<option value='" . htmlspecialchars($engines[$e]['StorageID'], ENT_QUOTES) . "'"
				. (($selected === $e) ? ' selected' : '') . ">" . htmlspecialchars($engines[$e]['Name'])
				. " - " . count($list) . " episode" . (count($list) == 1 ? '' : 's') . "</option>";
		}
		$engineFilter = "<p id='series_filter'><label for='series_engine_select'>Record engine: </label>"
			. "<select id='series_engine_select' onchange='changeSeriesEngine(this)'>" . $engineOptions . "</select></p>";

		// The chosen recordings, grouped by season (or year)
		$groups = array();
		foreach ($byEngine as $e => $list) {
			if ($selected !== null && $selected !== $e) {
				continue;
			}
			foreach ($list as $info) {
				if (!isset($groups[$info['GroupKey']])) {
					$groups[$info['GroupKey']] = array('Label' => $info['GroupLabel'],
						'Order' => $info['GroupOrder'], 'Episodes' => array());
				}
				$groups[$info['GroupKey']]['Episodes'][] = $info;
			}
		}
		if (count($groups) == 0) {
			return "<div class='series_view'><div class='series_fg'><div id='series_header'>"
				. "<h3 id='series_title'>" . htmlspecialchars($title) . "</h3>" . $engineFilter . "</div>"
				. "<p class='recorded_msg'>No recordings of " . htmlspecialchars($title) . " found</p></div></div>";
		}

		// Latest season (or year) first; seasons before years
		uasort($groups, function($a, $b) {
			return $b['Order'] <=> $a['Order'];
		});

		$total = 0;
		$seasons = 0;
		$latest = null;   // most and least recently recorded, for the header
		$oldest = null;
		$seasonList = '';
		$episodeLists = '';
		// the season to show: the one asked for (e.g. after an action) if it's still there,
		// otherwise the first (latest)
		if ($activeGroup === '' || !isset($groups[$activeGroup])) {
			$activeGroup = array_key_first($groups);
		}
		foreach ($groups as $groupKey => $group) {
			$first = ($groupKey === $activeGroup);
			if (strpos($groupKey, 'season') === 0) {
				$seasons++;
			}
			foreach ($group['Episodes'] as $info) {
				if ($latest === null || $info['Recorded'] > $latest['Recorded']) {
					$latest = $info;
				}
				if ($oldest === null || $info['Recorded'] < $oldest['Recorded']) {
					$oldest = $info;
				}
			}
			// in a season by episode number, otherwise by air date - then when recorded
			usort($group['Episodes'], function($a, $b) {
				return ($a['Episode'] <=> $b['Episode']) ?: ($a['Aired'] <=> $b['Aired'])
					?: ($a['Recorded'] <=> $b['Recorded']);
			});
			$count = count($group['Episodes']);
			$total += $count;
			$groupAttr = htmlspecialchars($groupKey, ENT_QUOTES);
			$seasonList .= "<li><a href='#' class='series_season" . ($first ? ' active' : '') . "' data-group='" . $groupAttr . "'"
				. " title='" . $count . " recording" . ($count == 1 ? '' : 's') . "'"
				. " onclick='selectSeason(event, this); return false;'>" . htmlspecialchars($group['Label']) . "</a></li>";

			$rows = '';
			foreach ($group['Episodes'] as $info) {
				$rows .= seriesEpisodeRow($info);
			}
			$episodeLists .= "<div class='series_episodes' data-group='" . $groupAttr . "'"
				. ($first ? '' : " style='display:none'") . ">" . $rows . "</div>";
		}

		// Title, then: seasons and episodes, the latest recording, the oldest recording,
		// and the engine filter just above the season list
		$counts = array();
		if ($seasons > 0) {
			$counts[] = $seasons . ' season' . ($seasons == 1 ? '' : 's');
		}
		$counts[] = $total . ' episode' . ($total == 1 ? '' : 's');
		$header = "<div id='series_header'>"
			. "<h3 id='series_title'>" . htmlspecialchars($title) . "</h3>"
			. "<p id='series_count'>" . implode(' &middot; ', $counts) . "</p>"
			. seriesHeaderLine('Latest', $latest, $title)
			. (($total > 1) ? seriesHeaderLine('Oldest', $oldest, $title) : '')
			. $engineFilter
			. "</div>";

		// Series image as the backdrop: sharp at the top, blurring out and fading into
		// the page further down (a sharp and a blurred copy, masked - see style.css)
		$backdrop = '';
		if ($image != '') {
			$src = htmlspecialchars($image, ENT_QUOTES);
			$backdrop = "<div class='series_backdrop' aria-hidden='true'>"
				. "<img class='series_bd_sharp' src='" . $src . "' onerror=\"this.parentNode.style.display='none';\"/>"
				. "<img class='series_bd_blur' src='" . $src . "'/>"
				. "</div>";
		}

		// The hidden sizer item sets the list's width - just enough for "Season 999"
		return "<div class='series_view" . ($image != '' ? ' has_backdrop' : '') . "'>" . $backdrop
			. "<div class='series_fg'>" . $header
			. "<div id='series_body'>"
			. "<ul id='series_seasons'>" . $seasonList
			. "<li class='series_season_sizer' aria-hidden='true'><span class='series_season'>Season 999</span></li>"
			. "</ul>"
			. "<div id='series_episode_list'>"
			// command bar - select all (in the season shown); actions go in series_actions
			. "<div id='series_cmdbar'>"
			. "<label class='series_select_all'><input type='checkbox' id='series_select_all' onclick='selectAllSeriesEpisodes(this)'/> Select all</label>"
			. "<span id='series_selected_count'></span>"
			. "<span id='series_actions'>"
			. "<button type='button' id='series_act_watched' class='series_action' disabled onclick='seriesAction(this.getAttribute(\"data-action\"))'"
			. " data-action='watched' title='Set the selected episodes as watched (or unwatched if they all are)'>Set watched</button>"
			. "<button type='button' class='series_action' disabled onclick='seriesAction(\"remove\")'"
			. " title='Delete the selected recordings - they can be recorded again if they air again'>Remove</button>"
			. "<button type='button' class='series_action series_action_danger' disabled onclick='seriesAction(\"kill\")'"
			. " title='Delete the selected recordings - they will not be recorded again'>Delete permanently</button>"
			. "</span>"
			. "</div>"
			// only the episodes scroll - the bar above them stays put
			. "<div id='series_episode_scroll'>" . $episodeLists . "</div></div>"
			. "</div></div></div>";
	}

	/*
	 * What the page needs from one recording. Episodes numbered "S03E13" go under
	 * "Season 3"; anything without a season number goes under the year it first aired
	 * (OriginalAirdate), else the year it was recorded.
	 */
	function seriesEpisodeInfo($episode) {
		$number = recordedField($episode, array('EpisodeNumber'), '');
		$aired = (int)recordedField($episode, array('OriginalAirdate'), '0');
		$recorded = (int)recordedField($episode, array('RecordStartTime', 'StartTime'), '0');
		$recordEnd = (int)recordedField($episode, array('RecordEndTime', 'EndTime'), '0');

		$season = 0;
		$episodeNo = 0;
		if (preg_match('/^S(\d+)E(\d+)/i', $number, $m)) {
			$season = (int)$m[1];
			$episodeNo = (int)$m[2];
		}
		if ($season > 0) {
			$groupKey = 'season' . $season;
			$groupLabel = 'Season ' . $season;
			$groupOrder = 100000 + $season;
		} else {
			// OriginalAirdate is midnight UTC of the air date - read it as UTC
			$year = ($aired > 0) ? (int)gmdate('Y', $aired) : (($recorded > 0) ? (int)date('Y', $recorded) : 0);
			$groupKey = ($year > 0) ? 'year' . $year : 'other';
			$groupLabel = ($year > 0) ? (string)$year : 'Other';
			$groupOrder = $year;
		}

		// Recording problems: the engine sets RecordError (e.g. "0x0004: Partial recording")
		// or RecordSuccess 0 - partial recordings get a warning, anything else an error
		$statusText = recordedField($episode, array('RecordError'), '');
		$status = '';
		if ($statusText != '') {
			$status = (stripos($statusText, 'partial') !== false) ? 'partial' : 'error';
		} elseif (recordedField($episode, array('RecordSuccess'), '') === '0') {
			$status = 'error';
			$statusText = 'Recording failed';
		}

		// Resume: where playback was stopped, in seconds; 4294967295 (0xFFFFFFFF) means
		// watched to the end - compared as a string as it doesn't fit a 32-bit int
		$resumeText = recordedField($episode, array('Resume'), '');
		$resume = null;
		if ($resumeText === '4294967295') {
			$resume = -1;
		} elseif (preg_match('/^\d+$/', $resumeText)) {   // (no ctype extension in the image)
			$resume = (int)$resumeText;
		}

		// Movie rating - the synopsis usually repeats it at the end, e.g. "... (2.5/4.0)"
		$score = recordedField($episode, array('MovieScore'), '');
		$synopsis = recordedField($episode, array('Synopsis'), '');
		$scoreSuffix = '(' . $score . ')';
		if ($score != '' && substr($synopsis, -strlen($scoreSuffix)) === $scoreSuffix) {
			$synopsis = rtrim(substr($synopsis, 0, -strlen($scoreSuffix)));
		}

		return array(
			'GroupKey' => $groupKey,
			'GroupLabel' => $groupLabel,
			'GroupOrder' => $groupOrder,
			'Episode' => $episodeNo,
			'Number' => $number,
			'Title' => recordedField($episode, array('EpisodeTitle'), recordedField($episode, array('Title'), '')),
			'Synopsis' => $synopsis,
			'Channel' => trim(recordedField($episode, array('ChannelName'), '') . ' '
				. recordedField($episode, array('ChannelNumber'), '')),
			'Aired' => $aired,
			'Recorded' => $recorded,
			'Duration' => ($recorded > 0 && $recordEnd > $recorded) ? $recordEnd - $recorded : 0,
			'Affiliate' => recordedField($episode, array('ChannelAffiliate'), ''),
			'ChannelLogo' => recordedField($episode, array('ChannelImageURL'), ''),
			'New' => recordedField($episode, array('FirstAiring'), '0') == '1',
			'MovieScore' => $score,
			'Status' => $status,
			'StatusText' => $statusText,
			'Resume' => $resume,
			// the engine's id for the recording (from its CmdURL "...?id=ca12c07e4eb13a54"),
			// for actions on selected episodes
			'RecordingID' => preg_match('/[?&]id=([A-Za-z0-9]+)/', recordedField($episode, array('CmdURL', 'PlayURL'), ''), $idMatch)
				? $idMatch[1] : '');
	}

	// Header line for one recording: "Latest: S03E13 Title · Aired 2 Mar 2022 · Recorded Thu 21:00"
	function seriesHeaderLine($label, $info, $seriesTitle) {
		if ($info === null) {
			return '';
		}
		$parts = array();
		// an episode without its own title (or a movie) just has the series title - already shown
		$episodeTitle = (strcasecmp($info['Title'], $seriesTitle) == 0) ? '' : $info['Title'];
		$name = trim(($info['Number'] != '' ? $info['Number'] . ' ' : '') . $episodeTitle);
		if ($info['Aired'] > 0) {
			// OriginalAirdate is midnight UTC of the air date - read it as UTC
			$parts[] = 'Aired ' . gmdate(dvruiDateFormat(), $info['Aired']);
		}
		if ($info['Recorded'] > 0) {
			$parts[] = 'Recorded ' . htmlspecialchars(recordedDate($info['Recorded']));
		}
		// the episode on the label's line, its dates on an indented line below
		// (an episode with no number or title of its own puts the dates on the label's line)
		$dates = implode(' &middot; ', $parts);
		$labelHtml = "<span class='series_hdr_label'>" . $label . ":</span> ";
		if ($name == '') {
			return "<p class='series_hdr_line'>" . $labelHtml . $dates . "</p>";
		}
		return "<p class='series_hdr_line'>" . $labelHtml
			. "<span class='series_hdr_name'>" . htmlspecialchars($name) . "</span></p>"
			. (($dates != '') ? "<p class='series_hdr_line series_hdr_dates'>" . $dates . "</p>" : '');
	}

	function seriesEpisodeRow($info) {
		$heading = htmlspecialchars($info['Title']);
		if ($info['Number'] != '') {
			$heading = "<span class='series_ep_number'>" . htmlspecialchars($info['Number']) . "</span> " . $heading;
		}

		// after the title: a gold rosette for a first airing, then a yellow ! for a partial
		// recording or a red X for a failed one (the engine's message is the tooltip)
		$flags = '';
		if ($info['New']) {
			$flags .= "<span class='series_ep_flag series_ep_new' title='New - first airing'>" . seriesRosette() . "</span>";
		}
		if ($info['Status'] == 'partial') {
			$flags .= "<span class='series_ep_flag series_ep_partial' title='" . htmlspecialchars($info['StatusText'], ENT_QUOTES) . "'>!</span>";
		} elseif ($info['Status'] == 'error') {
			$flags .= "<span class='series_ep_flag series_ep_error' title='" . htmlspecialchars($info['StatusText'], ENT_QUOTES) . "'>&#10005;</span>";
		}

		// one line each: when it aired, the recording (when, how long), where it was
		// recorded from and the engine it's on
		$lines = '';
		if ($info['Aired'] > 0) {
			$lines .= "<p class='series_ep_meta'>Aired " . gmdate(dvruiDateFormat(), $info['Aired']) . "</p>";
		}
		$meta = array();
		if ($info['Recorded'] > 0) {
			$meta[] = 'Recorded ' . recordedDate($info['Recorded']);
		}
		if ($info['Duration'] > 0) {
			$minutes = (int)round($info['Duration'] / 60);
			$meta[] = ($minutes >= 60) ? sprintf('%dh %02dm', intdiv($minutes, 60), $minutes % 60) : $minutes . 'm';
		}
		if (count($meta) > 0) {
			$lines .= "<p class='series_ep_meta'>" . implode(' &middot; ', $meta) . "</p>";
		}

		$source = array();
		if ($info['Channel'] != '') {
			$source[] = 'Channel: ' . htmlspecialchars($info['Channel'])
				. (($info['Affiliate'] != '') ? ' (' . htmlspecialchars($info['Affiliate']) . ')' : '');
		}
		$source[] = 'Engine: ' . htmlspecialchars($info['Engine']);
		$logo = '';
		if (preg_match('#^https?://#i', $info['ChannelLogo'])) {
			$logo = "<img class='series_ep_logo' src='" . htmlspecialchars($info['ChannelLogo'], ENT_QUOTES) . "' loading='lazy' alt=''"
				. " onerror=\"this.style.display='none';\"/>";
		}
		$lines .= "<p class='series_ep_meta series_ep_source'>" . $logo . implode(' &middot; ', $source) . "</p>";

		// movie rating as stars in the bottom right ("2.5/4.0" -> 2.5 of 4 stars)
		$stars = '';
		if (preg_match('#^([\d.]+)\s*/\s*([\d.]+)$#', $info['MovieScore'], $m) && (float)$m[2] > 0) {
			$pct = max(0, min(100, round((float)$m[1] / (float)$m[2] * 100)));
			$stars = "<span class='series_ep_stars' title='Rating " . htmlspecialchars($info['MovieScore'], ENT_QUOTES) . "'>"
				. "<span class='series_ep_stars_bg'>&#9733;&#9733;&#9733;&#9733;</span>"
				. "<span class='series_ep_stars_fg' style='width:" . $pct . "%'>&#9733;&#9733;&#9733;&#9733;</span></span>";
		}

		// how much has been watched, as a thin bar along the bottom
		$watched = '';
		if ($info['Resume'] !== null) {
			$pct = 0;
			$tip = '';
			if (seriesIsWatched($info)) {
				$pct = 100;
				$tip = 'Watched';
			} elseif ($info['Duration'] > 0) {
				$pct = max(1, round($info['Resume'] / $info['Duration'] * 100));
				$tip = 'Watched to ' . seriesClock($info['Resume']) . ' of ' . seriesClock($info['Duration']) . ' (' . $pct . '%)';
			}
			if ($pct > 0) {
				$watched = "<div class='series_ep_watched' title='" . $tip . "'><div style='width:" . $pct . "%'></div></div>";
			}
		}

		// selection checkbox, tagged with the recording and its engine for the command bar actions
		$check = "<input type='checkbox' class='series_ep_check' title='Select'"
			. " data-id='" . htmlspecialchars($info['RecordingID'], ENT_QUOTES) . "'"
			. " data-storageid='" . htmlspecialchars($info['StorageID'], ENT_QUOTES) . "'"
			. " data-watched='" . (seriesIsWatched($info) ? '1' : '0') . "'"
			. " onclick='updateSeriesSelection()'/>";

		return "<div class='series_episode" . ($stars != '' ? ' has_stars' : '') . "'>"
			. $check
			. "<div class='series_ep_info'>"
			. "<h4 class='series_ep_title'>" . $heading . $flags . "</h4>"
			. $lines
			. (($info['Synopsis'] != '') ? "<p class='series_ep_synopsis'>" . htmlspecialchars($info['Synopsis']) . "</p>" : '')
			. "</div>" . $stars . $watched . "</div>";
	}

	// Gold rosette ribbon (new / first airing): two ribbon tails behind a scalloped badge
	function seriesRosette() {
		return "<svg class='series_rosette' viewBox='0 0 16 22' width='14' height='19' aria-hidden='true'>"
			. "<polygon points='3.5,11 7,12.5 5.5,21.5 3.6,19.4 1,20.2' fill='#b8860b'/>"
			. "<polygon points='12.5,11 9,12.5 10.5,21.5 12.4,19.4 15,20.2' fill='#b8860b'/>"
			. "<polygon points='8,0 9.6,1.61 11.8,1.02 12.38,3.22 14.58,3.8 13.99,6 15.6,7.6 13.99,9.2 14.58,11.4"
			. " 12.38,11.98 11.8,14.18 9.6,13.59 8,15.2 6.4,13.59 4.2,14.18 3.62,11.98 1.42,11.4 2.01,9.2 0.4,7.6"
			. " 2.01,6 1.42,3.8 3.62,3.22 4.2,1.02 6.4,1.61' fill='#f5c518' stroke='#b8860b' stroke-width='0.6'/>"
			. "<circle cx='8' cy='7.6' r='4' fill='#ffd84d' stroke='#b8860b' stroke-width='0.7'/>"
			. "</svg>";
	}

	// 2324 -> "38:44", 3660 -> "1:01:00"
	function seriesClock($seconds) {
		$seconds = (int)$seconds;
		return ($seconds >= 3600) ? sprintf('%d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60)
			: sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
	}
?>
