<?php
	require_once("TinyAjaxBehavior.php");
	require_once("vars.php");
	require_once("statusmessage.php");
	require_once("includes/dvrui_common.php");
	require_once("includes/dvrui_hdhrjson.php");

	/*
	 * Rules page - the recording rules of the account, from the SiliconDust cloud
	 * (api/recording_rules), highest priority first. The rules belong to the account
	 * the tuners are registered to, so the DeviceAuth of every tuner found is sent.
	 */
	function openRulesPage() {
		ob_start();
		$hdhr = new DVRUI_HDHRjson();
		$htmlStr = buildRulesPage(rulesFetch($hdhr), $hdhr);
		return rulesResponse($htmlStr, '');
	}

	/*
	 * Drag and drop on the Rules page - move a rule to just after another one
	 * ($afterRuleId 0 = highest priority). The SiliconDust servers return the rules
	 * as they now are, which rebuilds the page; then every record engine is told to
	 * work out its upcoming recordings again.
	 */
	function moveRulePage($ruleId, $afterRuleId) {
		ob_start();
		$hdhr = new DVRUI_HDHRjson();
		$ruleId = trim((string)$ruleId);
		$afterRuleId = trim((string)$afterRuleId);
		if (!preg_match('/^\d+$/', $ruleId) || !preg_match('/^\d+$/', $afterRuleId) || $ruleId === $afterRuleId) {
			return rulesResponse(buildRulesPage(rulesFetch($hdhr), $hdhr), 'Unable to move the rule - invalid rule');
		}
		$auth = rulesDeviceAuth($hdhr);
		$rules = null;
		if ($auth != '') {
			$rules = rulesApiPost(array('DeviceAuth' => $auth, 'Cmd' => 'change',
				'RecordingRuleID' => $ruleId, 'AfterRecordingRuleID' => $afterRuleId));
		}
		if (!is_array($rules)) {
			return rulesResponse(buildRulesPage(rulesFetch($hdhr), $hdhr),
				'Unable to change the rule priority on the SiliconDust servers');
		}
		$fetched = array('Rules' => array_values($rules), 'Error' => '');
		$synced = rulesSyncEngines($hdhr);

		// where the rule ended up, for the status message
		$message = 'Rule moved';
		$sorted = rulesSorted($fetched['Rules']);
		foreach ($sorted as $pos => $rule) {
			if (rulesText($rule, 'RecordingRuleID') === $ruleId) {
				$message = 'Moved ' . rulesText($rule, 'Title') . ' to priority ' . ($pos + 1);
				break;
			}
		}
		$message .= ' - ' . $synced[0] . ' of ' . $synced[1] . ' record engine' . ($synced[1] == 1 ? '' : 's') . ' updated';
		return rulesResponse(buildRulesPage($fetched, $hdhr), $message);
	}

	/*
	 * Delete button on a rule (after the confirmation in the page). The rules are
	 * fetched again afterwards, so the page shows what SiliconDust now has, and every
	 * record engine is told to work out its upcoming recordings again.
	 * Recordings already made are kept.
	 */
	function deleteRulePage($ruleId) {
		ob_start();
		$hdhr = new DVRUI_HDHRjson();
		$ruleId = trim((string)$ruleId);
		if (!preg_match('/^\d+$/', $ruleId)) {
			return rulesResponse(buildRulesPage(rulesFetch($hdhr), $hdhr), 'Unable to delete the rule - invalid rule');
		}
		// the title, for the status message
		$before = rulesFetch($hdhr);
		$title = null;
		foreach ((array)$before['Rules'] as $rule) {
			if (rulesText($rule, 'RecordingRuleID') === $ruleId) {
				$title = rulesText($rule, 'Title');
			}
		}
		if ($title === null && is_array($before['Rules'])) {
			// e.g. deleted from another page or the HDHomeRun app meanwhile
			return rulesResponse(buildRulesPage($before, $hdhr), 'That rule has already been deleted');
		}
		if ($title === null || $title === '') {
			$title = 'the rule';
		}
		$auth = rulesDeviceAuth($hdhr);
		$result = false;
		if ($auth != '') {
			$result = rulesApiPost(array('DeviceAuth' => $auth, 'Cmd' => 'delete', 'RecordingRuleID' => $ruleId));
		}
		$fetched = rulesFetch($hdhr);
		if ($result === false) {
			return rulesResponse(buildRulesPage($fetched, $hdhr), 'Unable to delete ' . $title . ' on the SiliconDust servers');
		}
		foreach ((array)$fetched['Rules'] as $rule) {
			if (rulesText($rule, 'RecordingRuleID') === $ruleId) {
				return rulesResponse(buildRulesPage($fetched, $hdhr), 'Unable to delete ' . $title . ' - the rule is still there');
			}
		}
		$synced = rulesSyncEngines($hdhr);
		return rulesResponse(buildRulesPage($fetched, $hdhr), 'Deleted the rule for ' . $title . ' - ' . $synced[0]
			. ' of ' . $synced[1] . ' record engine' . ($synced[1] == 1 ? '' : 's') . ' updated');
	}

	/*
	 * Edit button - the dialog for a rule: its channels, current season (recent only) or all seasons,
	 * and the start/end padding, with the episodes coming up in the guide. The channels
	 * to choose from are the ones the upcoming episodes are on (and any the rule
	 * already has); with nothing upcoming the rule stays on all channels.
	 */
	function openRuleEditor($ruleId) {
		ob_start();
		$hdhr = new DVRUI_HDHRjson();
		$ruleId = trim((string)$ruleId);
		$rule = null;
		if (preg_match('/^\d+$/', $ruleId)) {
			$fetched = rulesFetch($hdhr);
			foreach ((array)$fetched['Rules'] as $r) {
				if (rulesText($r, 'RecordingRuleID') === $ruleId) {
					$rule = $r;
				}
			}
		}
		$tab = new TinyAjaxBehavior(true);
		$result = ob_get_contents();
		ob_end_clean();
		if ($rule === null) {
			$tab->add(TabInnerHtml::getBehavior("rules_dialog", ''));
			$tab->add(TabInnerHtml::getBehavior("statusMessage", 'That rule no longer exists - press Reload to see the rules as they are now'));
			return $tab->getString();
		}
		$episodes = rulesUpcoming($hdhr, rulesText($rule, 'SeriesID'));
		$tab->add(TabInnerHtml::getBehavior("rules_dialog",
			rulesEditorHtml($rule, $episodes, rulesChannelNames($hdhr, array($rule)))));
		$tab->add(TabInnerHtml::getBehavior("statusMessage", ($result != '' && $result != NULL) ? $result : getLatestHDHRStatus()));
		return $tab->getString();
	}

	/*
	 * Save from the Edit dialog. SiliconDust updates a rule with Cmd=add for its
	 * series (it replaces the rule that is there), so every setting is sent. The
	 * rules are fetched again to check what was saved, the rule is put back in its
	 * place if the update moved it, and the record engines are told to resync.
	 * $channels is '' for all channels, or channel numbers separated by |.
	 */
	function saveRulePage($ruleId, $channels, $recentOnly, $startPadding, $endPadding) {
		ob_start();
		$hdhr = new DVRUI_HDHRjson();
		$ruleId = trim((string)$ruleId);
		$channelList = rulesChannelList((string)$channels);
		$valid = preg_match('/^\d+$/', $ruleId)
			&& ($channelList !== null)
			&& in_array((string)$recentOnly, array('0', '1'), true)
			&& preg_match('/^\d+$/', (string)$startPadding) && (int)$startPadding <= 3600
			&& preg_match('/^\d+$/', (string)$endPadding) && (int)$endPadding <= 10800;
		if (!$valid) {
			return rulesEditorFailed('Unable to save - the settings are not valid');
		}
		$before = rulesFetch($hdhr);
		$rule = null;
		$afterId = '0';
		$prevId = '0';
		foreach (rulesSorted((array)$before['Rules']) as $r) {
			if (rulesText($r, 'RecordingRuleID') === $ruleId) {
				$rule = $r;
				$afterId = $prevId;
			}
			if (rulesText($r, 'DateTimeOnly') == '') {
				$prevId = rulesText($r, 'RecordingRuleID');
			}
		}
		if ($rule === null) {
			return rulesEditorFailed('Unable to save - that rule no longer exists');
		}
		$title = rulesText($rule, 'Title');
		$seriesId = rulesText($rule, 'SeriesID');
		$oneTime = rulesText($rule, 'DateTimeOnly');
		$params = array('DeviceAuth' => rulesDeviceAuth($hdhr), 'Cmd' => 'add', 'SeriesID' => $seriesId,
			'StartPadding' => (int)$startPadding, 'EndPadding' => (int)$endPadding);
		if ($oneTime != '') {
			// a one-time rule keeps its time and channel - only the padding changes
			$params['DateTimeOnly'] = $oneTime;
			$params['ChannelOnly'] = rulesText($rule, 'ChannelOnly');
			$channelList = rulesChannelList($params['ChannelOnly']);
		} else {
			$params['ChannelOnly'] = implode('|', $channelList);
			$params['RecentOnly'] = (string)$recentOnly;
			// the add replaces the whole rule - keep the limits the dialog doesn't edit
			foreach (array('TeamOnly', 'AfterOriginalAirdateOnly') as $keep) {
				if (rulesText($rule, $keep) != '') {
					$params[$keep] = rulesText($rule, $keep);
				}
			}
		}
		if ($params['DeviceAuth'] == '' || rulesApiPost($params) === false) {
			return rulesEditorFailed('Unable to save the rule for ' . $title . ' on the SiliconDust servers');
		}

		// the rule as it is now (it may have a new ID)
		$find = function($rules) use ($seriesId, $oneTime) {
			$prev = '0';
			foreach (rulesSorted((array)$rules) as $r) {
				if (rulesText($r, 'SeriesID') === $seriesId && rulesText($r, 'DateTimeOnly') === $oneTime) {
					return array($r, $prev);
				}
				if (rulesText($r, 'DateTimeOnly') == '') {
					$prev = rulesText($r, 'RecordingRuleID');
				}
			}
			return array(null, '0');
		};
		$after = rulesFetch($hdhr);
		list($saved, $savedAfter) = $find($after['Rules']);
		if ($saved === null) {
			return rulesEditorFailed('The rule for ' . $title . ' was not found after saving - press Reload to check it');
		}
		if ($oneTime == '' && $savedAfter !== $afterId && $afterId !== rulesText($saved, 'RecordingRuleID')) {
			// keep its priority
			rulesApiPost(array('DeviceAuth' => $params['DeviceAuth'], 'Cmd' => 'change',
				'RecordingRuleID' => rulesText($saved, 'RecordingRuleID'), 'AfterRecordingRuleID' => $afterId));
			$after = rulesFetch($hdhr);
			list($saved, $savedAfter) = $find($after['Rules']);
		}

		// anything SiliconDust didn't take
		$kept = array();
		if ($saved !== null) {
			$savedChannels = rulesChannelList(rulesText($saved, 'ChannelOnly'));
			$wanted = $channelList;
			sort($savedChannels);
			sort($wanted);
			if ($savedChannels !== $wanted) {
				$kept[] = 'channels ' . (count($savedChannels) ? implode(', ', $savedChannels) : 'all');
			}
			if ($oneTime == '' && (rulesText($saved, 'RecentOnly') == '1') !== ((string)$recentOnly === '1')) {
				$kept[] = (rulesText($saved, 'RecentOnly') == '1') ? 'current season' : 'all seasons';
			}
			if ((int)rulesText($saved, 'StartPadding') !== (int)$startPadding) {
				$kept[] = 'start ' . rulesPadding(rulesText($saved, 'StartPadding')) . ' early';
			}
			if ((int)rulesText($saved, 'EndPadding') !== (int)$endPadding) {
				$kept[] = 'end ' . rulesPadding(rulesText($saved, 'EndPadding')) . ' late';
			}
		}
		$synced = rulesSyncEngines($hdhr);
		$message = count($kept)
			? 'Saved the rule for ' . $title . ', but SiliconDust kept: ' . implode(', ', $kept)
			: 'Saved the rule for ' . $title;
		$message .= ' - ' . $synced[0] . ' of ' . $synced[1] . ' record engine' . ($synced[1] == 1 ? '' : 's') . ' updated';
		$tab = new TinyAjaxBehavior(true);
		$result = ob_get_contents();
		ob_end_clean();
		$tab->add(TabInnerHtml::getBehavior("rules_box", buildRulesPage($after, $hdhr)));
		$tab->add(TabInnerHtml::getBehavior("rules_dialog", ''));
		$tab->add(TabInnerHtml::getBehavior("statusMessage", ($result != '' && $result != NULL) ? $result : htmlspecialchars($message)));
		return $tab->getString();
	}

	// Saving failed - the dialog stays open with the reason, and can be saved again
	function rulesEditorFailed($message) {
		$tab = new TinyAjaxBehavior(true);
		ob_end_clean();
		$tab->add(TabInnerHtml::getBehavior("re_footer", rulesEditorFooter($message)));
		$tab->add(TabInnerHtml::getBehavior("statusMessage", htmlspecialchars($message)));
		return $tab->getString();
	}

	// '' or "5.1|7.1" as a list of channel numbers, or null if it isn't one
	function rulesChannelList($channels) {
		$list = array();
		foreach (explode('|', $channels) as $number) {
			$number = trim($number);
			if ($number === '') {
				continue;
			}
			if (!preg_match('/^[0-9]+(\.[0-9]+)?$/', $number)) {
				return null;
			}
			$list[$number] = $number;
		}
		return array_values($list);
	}

	// The series' episodes coming up in the guide (api/episodes), soonest first
	function rulesUpcoming($hdhr, $seriesId) {
		$auth = rulesDeviceAuth($hdhr);
		if ($auth == '' || $seriesId == '') {
			return array();
		}
		$episodes = getJsonFromUrl(DVRUI_Vars::DVRUI_apiurl . 'api/episodes?DeviceAuth=' . urlencode($auth)
			. '&SeriesID=' . urlencode($seriesId));
		if (!is_array($episodes)) {
			return array();
		}
		$episodes = array_values(array_filter($episodes, 'is_array'));
		usort($episodes, function($a, $b) {
			return (int)rulesText($a, 'StartTime') - (int)rulesText($b, 'StartTime');
		});
		return $episodes;
	}

	function rulesEditorHtml($rule, $episodes, $channelNames) {
		$title = rulesText($rule, 'Title');
		$oneTime = rulesText($rule, 'DateTimeOnly') != '';
		$ruleChannels = rulesChannelList(rulesText($rule, 'ChannelOnly'));
		if ($ruleChannels === null) {
			$ruleChannels = array();
		}
		// channels to choose from: those of the upcoming episodes, then any the rule has
		$channels = array();
		foreach ($episodes as $episode) {
			$number = rulesText($episode, 'ChannelNumber');
			if ($number != '' && !isset($channels[$number])) {
				$channels[$number] = array('Name' => rulesText($episode, 'ChannelName'),
					'Logo' => rulesText($episode, 'ChannelImageURL'), 'Count' => 0);
			}
			if ($number != '') {
				$channels[$number]['Count']++;
			}
		}
		foreach ($ruleChannels as $number) {
			if (!isset($channels[$number])) {
				$channels[$number] = array('Name' => isset($channelNames[$number]) ? $channelNames[$number] : '',
					'Logo' => '', 'Count' => 0);
			}
		}
		uksort($channels, 'strnatcmp');
		$onlySome = count($ruleChannels) > 0;

		$h = "<div class='modal_overlay' id='rule_editor' data-ruleid='" . htmlspecialchars(rulesText($rule, 'RecordingRuleID'), ENT_QUOTES) . "'"
			. " onclick='if (event.target == this) closeRuleEditor()'>"
			. "<div class='modal_box rule_editor' role='dialog' aria-modal='true' aria-labelledby='re_title'>"
			. "<h3 class='modal_title' id='re_title'>Edit rule &middot; " . htmlspecialchars($title) . "</h3>"
			. "<form id='re_form' onsubmit='saveRuleEditor(); return false;'>";

		if ($oneTime) {
			$h .= "<p class='re_note'>A one-time recording - only the padding can be changed.</p>";
		} else {
			// channels
			$h .= "<fieldset class='re_section'><legend>Channels</legend>"
				. "<label class='re_choice'><input type='radio' name='re_channels' value='all'" . ($onlySome ? '' : ' checked')
				. " onchange='updateRuleEditor()'/>All channels</label>";
			if (count($channels)) {
				$h .= "<label class='re_choice'><input type='radio' name='re_channels' value='only'" . ($onlySome ? ' checked' : '')
					. " onchange='updateRuleEditor()'/>Only these channels:</label><div class='re_channel_list'>";
				foreach ($channels as $number => $channel) {
					$checked = in_array((string)$number, $ruleChannels, true);
					$h .= "<label class='re_channel'><input type='checkbox' class='re_channel_check' value='"
						. htmlspecialchars($number, ENT_QUOTES) . "'" . ($checked ? ' checked' : '') . " onchange='updateRuleEditor()'/>";
					if ($channel['Logo'] != '') {
						$h .= "<img class='re_channel_logo' src='" . htmlspecialchars($channel['Logo'], ENT_QUOTES) . "' alt='' onerror=\"this.style.display='none'\"/>";
					}
					$h .= "<span>" . htmlspecialchars($number . ($channel['Name'] != '' ? ' ' . $channel['Name'] : '')) . "</span>";
					if ($channel['Count'] == 0) {
						$h .= " <span class='re_dim'>(nothing upcoming)</span>";
					}
					$h .= "</label>";
				}
				$h .= "</div>";
			} else {
				$h .= "<p class='re_note'>The channels can be chosen once an episode shows up in the guide.</p>";
			}
			$h .= "</fieldset>";

			// limits the dialog doesn't change - shown so it's clear they stay
			$other = array();
			foreach (rulesOptions($rule) as $option) {
				if (strpos($option[0], 'Team') === 0 || strpos($option[0], 'Aired after') === 0) {
					$other[] = $option[0];
				}
			}
			if (count($other)) {
				$h .= "<p class='re_note re_kept'>Also limited to " . htmlspecialchars(implode(' · ', $other)) . " - kept as it is.</p>";
			}

			// episodes
			$recent = rulesText($rule, 'RecentOnly') == '1';
			$h .= "<fieldset class='re_section'><legend>Seasons</legend>"
				. "<label class='re_choice'><input type='radio' name='re_recent' value='0'" . ($recent ? '' : ' checked') . "/>All seasons</label>"
				. "<label class='re_choice'><input type='radio' name='re_recent' value='1'" . ($recent ? ' checked' : '') . "/>Current season</label>"
				. "</fieldset>";
		}

		// padding
		$h .= "<fieldset class='re_section'><legend>Padding</legend><div class='re_padding'>"
			. "<label>Start <select id='re_start'>" . rulesPaddingOptions(rulesText($rule, 'StartPadding'), 3600) . "</select> early</label>"
			. "<label>End <select id='re_end'>" . rulesPaddingOptions(rulesText($rule, 'EndPadding'), 10800) . "</select> late</label>"
			. "</div></fieldset>";

		// what's coming up
		$h .= "<div class='re_upcoming'><div class='re_upcoming_title'>Coming up in the guide</div>";
		if (count($episodes)) {
			$h .= "<div class='re_upcoming_list'><table>";
			foreach ($episodes as $episode) {
				$start = (int)rulesText($episode, 'StartTime');
				$name = trim(rulesText($episode, 'EpisodeNumber') . ' ' . rulesText($episode, 'EpisodeTitle'));
				$h .= "<tr data-channel='" . htmlspecialchars(rulesText($episode, 'ChannelNumber'), ENT_QUOTES) . "'>"
					. "<td class='re_when'>" . htmlspecialchars($start > 0 ? date('D ' . dvruiDateFormat() . ' H:i', $start) : '') . "</td>"
					. "<td class='re_ch'>" . htmlspecialchars(trim(rulesText($episode, 'ChannelNumber') . ' ' . rulesText($episode, 'ChannelName'))) . "</td>"
					. "<td class='re_ep'>" . htmlspecialchars($name != '' ? $name : rulesText($episode, 'Title')) . "</td>"
					. "<td class='re_rec'>" . rulesRecordCell(rulesText($episode, 'RecordingRuleExt')) . "</td></tr>";
			}
			$h .= "</table></div>";
		} else {
			$h .= "<p class='re_none'>There are no episodes of this show coming up in the guide.</p>";
		}
		$h .= "</div></form><div id='re_footer'>" . rulesEditorFooter('') . "</div></div></div>";
		return $h;
	}

	function rulesEditorFooter($error) {
		return ($error != '' ? "<p class='re_error'>" . htmlspecialchars($error) . "</p>" : '')
			. "<div class='modal_buttons'>"
			. "<button type='button' class='modal_button' onclick='closeRuleEditor()'>Cancel</button>"
			. "<button type='button' class='modal_button modal_ok' id='re_save' onclick='saveRuleEditor()'>Save</button></div>";
	}

	// The padding choices (seconds), with the rule's own value if it isn't one of them
	function rulesPaddingOptions($current, $max) {
		$values = array(0, 30, 60, 120, 300, 600, 900, 1800, 3600, 7200, 10800);
		$current = (int)$current;
		if (!in_array($current, $values)) {
			$values[] = $current;
			sort($values);
		}
		$html = '';
		foreach ($values as $value) {
			if ($value > $max && $value != $current) {
				continue;
			}
			$html .= "<option value='" . $value . "'" . ($value == $current ? ' selected' : '') . ">" . rulesPadding($value) . "</option>";
		}
		return $html;
	}

	// What the engine will do with an upcoming episode - a green dot for will record,
	// a yellow dot for might record (only if not recorded yet) (the words are the tooltip), otherwise the words
	function rulesRecordCell($ext) {
		$text = rulesRecordText($ext);
		$dots = array('RecordDuplicate' => 're_dot_green', 'RecordIfNotRecorded' => 're_dot_yellow');
		if (isset($dots[$ext])) {
			return "<span class='re_dot " . $dots[$ext] . "' title='" . htmlspecialchars($text, ENT_QUOTES) . "'"
				. " role='img' aria-label='" . htmlspecialchars($text, ENT_QUOTES) . "'></span>";
		}
		return htmlspecialchars($text);
	}

	// RecordingRuleExt ("RecordIfNotRecorded") as words
	function rulesRecordText($ext) {
		$known = array('RecordIfNotRecorded' => 'Will record if not recorded yet', 'RecordDuplicate' => 'Will record');
		if (isset($known[$ext])) {
			return $known[$ext];
		}
		return ucfirst(strtolower(trim(preg_replace('/(?<!^)([A-Z])/', ' $1', $ext))));
	}

	// Page and status message out (ob_start is called by the caller)
	function rulesResponse($htmlStr, $message) {
		// the cloud JSON is UTF-8 already - don't re-encode it
		$tab = new TinyAjaxBehavior(true);
		$result = ob_get_contents();
		ob_end_clean();
		$tab->add(TabInnerHtml::getBehavior("rules_box", $htmlStr));
		if ($result != '' && $result != NULL) {
			$tab->add(TabInnerHtml::getBehavior("statusMessage", $result));
		} else if ($message != '') {
			$tab->add(TabInnerHtml::getBehavior("statusMessage", htmlspecialchars($message)));
		} else {
			$tab->add(TabInnerHtml::getBehavior("statusMessage", getLatestHDHRStatus()));
		}
		return $tab->getString();
	}

	// The rules are for the account of the tuners - all their DeviceAuth strings together
	function rulesDeviceAuth($hdhr) {
		$auth = '';
		for ($i = 0; $i < $hdhr->device_count(); $i++) {
			$deviceAuth = $hdhr->get_device_auth($i);
			if ($deviceAuth != '' && $deviceAuth != '??') {
				$auth .= $deviceAuth;
			}
		}
		return $auth;
	}

	/*
	 * Fetch the rules - returns array('Rules' => array or null, 'Error' => message)
	 */
	function rulesFetch($hdhr) {
		$auth = rulesDeviceAuth($hdhr);
		if ($auth == '') {
			return array('Rules' => null,
				'Error' => 'No HDHomeRun tuners found - the recording rules are kept by SiliconDust for the account the tuners are registered to.');
		}
		$rules = getJsonFromUrl(DVRUI_Vars::DVRUI_apiurl . 'api/recording_rules?DeviceAuth=' . urlencode($auth));
		if ($rules === null) {
			// the API answers "null" when there are no rules
			return array('Rules' => array(), 'Error' => '');
		}
		if (!is_array($rules)) {
			return array('Rules' => null, 'Error' => 'Unable to get the recording rules from the SiliconDust servers.');
		}
		return array('Rules' => array_values($rules), 'Error' => '');
	}

	// POST a command to api/recording_rules - returns what it sends back (decoded
	// JSON: the rules for change, may be null), or false if the request failed
	function rulesApiPost($params) {
		$url = DVRUI_Vars::DVRUI_apiurl . 'api/recording_rules';
		$body = http_build_query($params);
		if (function_exists('curl_init')) {
			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL, $url);
			curl_setopt($ch, CURLOPT_POST, true);
			curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_TIMEOUT, 10);
			$json = curl_exec($ch);
			$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		} else {
			$context = stream_context_create(array('http' => array('method' => 'POST',
				'header' => "Content-Type: application/x-www-form-urlencoded\r\nConnection: close\r\n",
				'content' => $body, 'timeout' => 10.0)));
			$json = @file_get_contents($url, false, $context);
			$code = ($json === false) ? 0 : 200;
		}
		if ($code < 200 || $code >= 300 || !is_string($json)) {
			error_log('recording_rules ' . (isset($params['Cmd']) ? $params['Cmd'] : '') . ' failed: HTTP ' . $code);
			return false;
		}
		return json_decode($json, true);
	}

	/*
	 * Tell every record engine the rules changed, so it works out its upcoming
	 * recordings again (POST <BaseURL>/recording_events.post?sync).
	 * Returns array(engines told, engines found).
	 */
	function rulesSyncEngines($hdhr) {
		$ok = 0;
		$count = $hdhr->engine_count();
		for ($i = 0; $i < $count; $i++) {
			$base = rtrim($hdhr->get_engine_baseurl($i), '/');
			if ($base == '') {
				continue;
			}
			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL, $base . '/recording_events.post?sync');
			curl_setopt($ch, CURLOPT_POST, true);
			curl_setopt($ch, CURLOPT_POSTFIELDS, '');
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_TIMEOUT, 5);
			curl_exec($ch);
			$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
			if ($code >= 200 && $code < 300) {
				$ok++;
			} else {
				error_log('recording_events sync failed for ' . $base . ': HTTP ' . $code);
			}
		}
		return array($ok, $count);
	}

	/*
	 * In the order the record engine uses them: one-time (DateTimeOnly) rules first -
	 * they always come before the others - then the highest priority first
	 */
	function rulesSorted($rules) {
		usort($rules, function($a, $b) {
			$oa = rulesText($a, 'DateTimeOnly') != '';
			$ob = rulesText($b, 'DateTimeOnly') != '';
			if ($oa != $ob) {
				return $oa ? -1 : 1;
			}
			$pa = isset($a['Priority']) ? (int)$a['Priority'] : 0;
			$pb = isset($b['Priority']) ? (int)$b['Priority'] : 0;
			if ($pa != $pb) {
				return $pb - $pa;
			}
			return strcasecmp(rulesText($a, 'Title'), rulesText($b, 'Title'));
		});
		return $rules;
	}

	function buildRulesPage($fetched, $hdhr = null) {
		if ($fetched['Rules'] === null) {
			return "<p class='recorded_msg'>" . htmlspecialchars($fetched['Error']) . "</p>";
		}
		$rules = $fetched['Rules'];
		if (count($rules) == 0) {
			return "<p class='recorded_msg'>There are no recording rules.</p>";
		}
		$rules = rulesSorted($rules);
		$channelNames = rulesChannelNames($hdhr, $rules);

		$htmlStr = "<div id='rules_header'><span id='rules_count'>" . count($rules) . ' recording rule'
			. (count($rules) == 1 ? '' : 's') . "</span> &middot; highest priority first"
			. " <span id='rules_hint'>&middot; drag a rule up or down to change its priority</span></div>";
		$htmlStr .= "<div id='rules_list'>";
		foreach ($rules as $pos => $rule) {
			$htmlStr .= rulesEntry($rule, $pos + 1, $channelNames);
		}
		return $htmlStr . "</div>";
	}

	// One rule: priority position, image, title, synopsis and its options
	function rulesEntry($rule, $position, $channelNames = array()) {
		$title = rulesText($rule, 'Title');
		$image = rulesText($rule, 'ImageURL');
		// one-time rules are always first - they can't be moved
		$fixed = rulesText($rule, 'DateTimeOnly') != '';
		$entry = "<div class='rule_entry" . ($fixed ? ' rule_fixed' : '') . "' data-ruleid='"
			. htmlspecialchars(rulesText($rule, 'RecordingRuleID'), ENT_QUOTES) . "'"
			. ($fixed ? " title='One-time recordings always come first'" : " draggable='true' title='Drag to change the priority'") . ">"
			. "<div class='rule_priority'>" . $position . "</div>"
			. "<img class='rule_img' loading='lazy' alt='' draggable='false' src='"
			. htmlspecialchars($image != '' ? $image : './images/no-preview.png', ENT_QUOTES) . "'"
			. " onerror=\"this.onerror=null;this.src='./images/no-preview.png';\"/>"
			. "<div class='rule_info'>"
			. "<h4 class='rule_title'>" . htmlspecialchars($title != '' ? $title : 'Untitled') . "</h4>";
		$synopsis = rulesText($rule, 'Synopsis');
		if ($synopsis != '') {
			$entry .= "<p class='rule_synopsis' title='" . htmlspecialchars($synopsis, ENT_QUOTES) . "'>"
				. htmlspecialchars($synopsis) . "</p>";
		}
		$entry .= "<div class='rule_options'>";
		foreach (rulesOptions($rule, $channelNames) as $option) {
			$entry .= "<span class='rule_opt" . ($option[1] ? ' rule_opt_limit' : '') . "'>" . htmlspecialchars($option[0]) . "</span>";
		}
		$entry .= "</div></div>";
		// trash can icon (stroke follows the button text colour)
		$trash = "<svg viewBox='0 0 24 24' width='18' height='18' aria-hidden='true' fill='none' stroke='currentColor'"
			. " stroke-width='2' stroke-linecap='round' stroke-linejoin='round'>"
			. "<path d='M3 6h18'/><path d='M8 6V4h8v2'/><path d='M5 6l1 14h12l1-14'/><path d='M10 11v6M14 11v6'/></svg>";
		// pencil icon
		$pencil = "<svg viewBox='0 0 24 24' width='18' height='18' aria-hidden='true' fill='none' stroke='currentColor'"
			. " stroke-width='2' stroke-linecap='round' stroke-linejoin='round'>"
			. "<path d='M4 20h4l11-11-4-4L4 16v4z'/><path d='M13.5 6.5l4 4'/></svg>";
		return $entry . "<div class='rule_actions'>"
			. "<button type='button' class='rule_edit' draggable='false' title='Edit this rule'"
			. " aria-label='Edit this rule' onclick='editRule(this)'>" . $pencil . "</button>"
			. "<button type='button' class='rule_delete' draggable='false' title='Delete this rule'"
			. " aria-label='Delete this rule' onclick='deleteRule(this)'>" . $trash . "</button></div></div>";
	}

	/*
	 * Channel names for the channels the rules are limited to, from the tuners'
	 * lineups (GuideNumber => GuideName). Only fetched when a rule has channels.
	 */
	function rulesChannelNames($hdhr, $rules) {
		$names = array();
		$needed = false;
		foreach ($rules as $rule) {
			if (rulesText($rule, 'ChannelOnly') != '') {
				$needed = true;
				break;
			}
		}
		if (!$needed || $hdhr === null) {
			return $names;
		}
		$urls = array();
		for ($i = 0; $i < $hdhr->device_count(); $i++) {
			$lineup = $hdhr->get_device_lineup($i);
			if (is_string($lineup) && preg_match('#^https?://#', $lineup)) {
				$urls[$i] = $lineup;
			}
		}
		foreach (getJsonFromUrls($urls) as $lineup) {
			if (!is_array($lineup)) {
				continue;
			}
			foreach ($lineup as $channel) {
				$number = rulesText((array)$channel, 'GuideNumber');
				$name = rulesText((array)$channel, 'GuideName');
				if ($number != '' && $name != '' && !isset($names[$number])) {
					$names[$number] = $name;
				}
			}
		}
		return $names;
	}

	/*
	 * What the rule records, as array(label, limits-the-rule): the channels (all of
	 * them unless the rule lists some), one time, teams, the seasons (current season,
	 * or all seasons for a series rule), aired after, then the padding
	 */
	function rulesOptions($rule, $channelNames = array()) {
		$options = array();
		$channels = rulesText($rule, 'ChannelOnly');
		$list = array_values(array_filter(array_map('trim', explode('|', $channels)), 'strlen'));
		if (count($list) == 0) {
			$options[] = array('All channels', false);
		} else {
			foreach ($list as $i => $number) {
				if (isset($channelNames[$number])) {
					$list[$i] = $number . ' ' . $channelNames[$number];
				}
			}
			$options[] = array((count($list) == 1 ? 'Channel ' : 'Channels ') . implode(', ', $list), true);
		}
		$dateTime = rulesText($rule, 'DateTimeOnly');
		if (preg_match('/^\d+$/', $dateTime)) {
			$options[] = array('One time: ' . date(dvruiDateFormat() . ' H:i', (int)$dateTime), true);
		}
		// TeamOnly is a | separated list, e.g. "England|Wales"
		$teams = array_values(array_filter(array_map('trim', explode('|', rulesText($rule, 'TeamOnly'))), 'strlen'));
		if (count($teams)) {
			$options[] = array((count($teams) == 1 ? 'Team: ' : 'Teams: ') . implode(', ', $teams), true);
		}
		if (rulesText($rule, 'RecentOnly') == '1') {
			$options[] = array('Current season', true);
		} else if (!preg_match('/^\d+$/', $dateTime)) {
			$options[] = array('All seasons', false);
		}
		// original air dates are midnight UTC
		$after = rulesText($rule, 'AfterOriginalAirdateOnly');
		if (preg_match('/^\d+$/', $after)) {
			$options[] = array('Aired after ' . gmdate(dvruiDateFormat(), (int)$after), true);
		}
		$start = rulesText($rule, 'StartPadding');
		$end = rulesText($rule, 'EndPadding');
		if ($start != '' || $end != '') {
			$options[] = array('Starts ' . rulesPadding($start) . ' early · ends ' . rulesPadding($end) . ' late', false);
		}
		return $options;
	}

	// Padding seconds as "30s", "2m", "1m 30s"
	function rulesPadding($seconds) {
		$seconds = (int)$seconds;
		if ($seconds < 60) {
			return $seconds . 's';
		}
		$text = intdiv($seconds, 60) . 'm';
		if ($seconds % 60) {
			$text .= ' ' . ($seconds % 60) . 's';
		}
		return $text;
	}

	function rulesText($rule, $key) {
		return (isset($rule[$key]) && is_scalar($rule[$key])) ? trim((string)$rule[$key]) : '';
	}
?>
