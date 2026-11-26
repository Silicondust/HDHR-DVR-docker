<?php
/*
 * ui/rules.php
 *
 * Copyright (c) 2020 Rik Dunphy
 * Copyright (c) 2026 Silicondust USA Inc.
 * Released under the MIT License - see LICENSE
 */
	require_once("includes/dvrui_ajax.php");
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
		$tab = new DVRUI_AjaxResponse();
		$result = ob_get_contents();
		ob_end_clean();
		if ($rule === null) {
			$tab->setHtml("rules_dialog", '');
			$tab->setHtml("statusMessage", 'That rule no longer exists - press Reload to see the rules as they are now');
			return $tab->getString();
		}
		$episodes = rulesUpcoming($hdhr, rulesText($rule, 'SeriesID'));
		$tab->setHtml("rules_dialog",
			rulesEditorHtml($rule, $episodes, rulesChannelNames($hdhr, array($rule))));
		$tab->setHtml("statusMessage", ($result != '' && $result != NULL) ? $result : getLatestHDHRStatus());
		return $tab->getString();
	}

	/*
	 * Save from the Edit dialog. SiliconDust updates a rule with Cmd=add for its
	 * series (it replaces the rule that is there), so every setting is sent. The
	 * rules are fetched again to check what was saved, the rule is put back in its
	 * place if the update moved it, and the record engines are told to resync.
	 * $channels is '' for all channels, or channel numbers separated by |, and
	 * $teams '' for all games or team names separated by |.
	 */
	function saveRulePage($ruleId, $channels, $recentOnly, $startPadding, $endPadding, $teams = '') {
		ob_start();
		$hdhr = new DVRUI_HDHRjson();
		$ruleId = trim((string)$ruleId);
		$channelList = rulesChannelList((string)$channels);
		$teamList = rulesTeamList($teams);
		$valid = preg_match('/^\d+$/', $ruleId)
			&& ($channelList !== null) && ($teamList !== null)
			&& in_array((string)$recentOnly, array('0', '1', 'new'), true)
			&& preg_match('/^\d+$/', (string)$startPadding) && (int)$startPadding <= 3600
			&& preg_match('/^\d+$/', (string)$endPadding) && (int)$endPadding <= 10800;
		if (!$valid) {
			return rulesEditorFailed('Unable to save - the settings are not valid');
		}
		// the save replaces the whole rule from what is read here, so read it fresh
		dvruiApiCacheClear();
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
			$params['RecentOnly'] = ((string)$recentOnly === '1') ? '1' : '0';
			if ((string)$recentOnly === 'new') {
				// new episodes only - keeps the date the rule has, or from today
				$after = rulesText($rule, 'AfterOriginalAirdateOnly');
				$params['AfterOriginalAirdateOnly'] = preg_match('/^\d+$/', $after) ? $after : rulesNewEpisodesFrom();
			}
			if (count($teamList)) {
				$params['TeamOnly'] = implode('|', $teamList);
			} elseif (rulesTeamList(rulesText($rule, 'TeamOnly')) === null) {
				// teams the dialog couldn't show - keep them as they are
				$params['TeamOnly'] = rulesText($rule, 'TeamOnly');
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
			$savedTeams = (array)rulesTeamList(rulesText($saved, 'TeamOnly'));
			$wantedTeams = isset($params['TeamOnly']) ? (array)rulesTeamList($params['TeamOnly']) : array();
			sort($savedTeams);
			sort($wantedTeams);
			if ($oneTime == '' && $savedTeams !== $wantedTeams) {
				$kept[] = count($savedTeams) ? 'teams ' . implode(', ', $savedTeams) : 'all games';
			}
			if ($oneTime == '' && (rulesText($saved, 'RecentOnly') == '1') !== ((string)$recentOnly === '1')) {
				$kept[] = (rulesText($saved, 'RecentOnly') == '1') ? 'current season' : 'all seasons';
			}
			$wantedAfter = isset($params['AfterOriginalAirdateOnly']) ? (string)$params['AfterOriginalAirdateOnly'] : '';
			if ($oneTime == '' && rulesText($saved, 'AfterOriginalAirdateOnly') !== $wantedAfter) {
				$kept[] = rulesText($saved, 'AfterOriginalAirdateOnly') == '' ? 'all episodes'
					: 'new episodes first aired from ' . gmdate(dvruiDateFormat(), (int)rulesText($saved, 'AfterOriginalAirdateOnly'));
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
		$tab = new DVRUI_AjaxResponse();
		$result = ob_get_contents();
		ob_end_clean();
		$tab->setHtml("rules_box", buildRulesPage($after, $hdhr));
		$tab->setHtml("rules_dialog", '');
		$tab->setHtml("statusMessage", ($result != '' && $result != NULL) ? $result : htmlspecialchars($message));
		return $tab->getString();
	}

	// Saving failed - the dialog stays open with the reason, and can be saved again
	function rulesEditorFailed($message) {
		$tab = new DVRUI_AjaxResponse();
		ob_end_clean();
		$tab->setHtml("re_footer", rulesEditorFooter($message));
		$tab->setHtml("statusMessage", htmlspecialchars($message));
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
		return rulesEpisodesSorted(getJsonFromApi(rulesEpisodesUrl($auth, $seriesId)));
	}

	// api/episodes for a series - the same URL everywhere, so the cache is shared
	function rulesEpisodesUrl($auth, $seriesId) {
		return DVRUI_Vars::DVRUI_apiurl . 'api/episodes?DeviceAuth=' . urlencode($auth)
			. '&SeriesID=' . urlencode($seriesId);
	}

	function rulesEpisodesSorted($episodes) {
		if (!is_array($episodes)) {
			return array();
		}
		$episodes = array_values(array_filter($episodes, 'is_array'));
		usort($episodes, function($a, $b) {
			return (int)rulesText($a, 'StartTime') - (int)rulesText($b, 'StartTime');
		});
		return $episodes;
	}

	/*
	 * Teams - a sports rule can be limited to games with some teams (TeamOnly, the
	 * team names separated by |). The guide gives each game's teams as Team1 and Team2.
	 */

	// an airing's teams (array of names)
	function rulesEpisodeTeams($episode) {
		$teams = array();
		foreach (array('Team1', 'Team2') as $key) {
			$team = rulesText($episode, $key);
			if ($team != '') {
				$teams[] = $team;
			}
		}
		return $teams;
	}

	// '' or "England|Wales" as a list of team names, or null if it isn't one
	function rulesTeamList($teams) {
		$list = array();
		foreach (explode('|', (string)$teams) as $team) {
			$team = trim($team);
			if ($team === '') {
				continue;
			}
			if (mb_strlen($team) > 100 || preg_match('/[\x00-\x1f]/', $team)) {
				return null;
			}
			$list[$team] = $team;
		}
		return count($list) <= 100 ? array_values($list) : null;
	}

	/*
	 * The Teams choice for a dialog ($prefix re or nr): a scrolling list with All
	 * teams first, then a box for each team in the upcoming airings (with how many
	 * games) and any the rule already has. Ticking a team unticks All teams, and
	 * unticking the last team ticks it again (see ruleTeamsPick in the page's script).
	 * '' when there are no teams to choose from.
	 */
	function rulesTeamsChoices($prefix, $episodes, $selected, $onchange) {
		$teams = array();
		foreach ($episodes as $episode) {
			foreach (rulesEpisodeTeams($episode) as $team) {
				$teams[$team] = isset($teams[$team]) ? $teams[$team] + 1 : 1;
			}
		}
		foreach ($selected as $team) {
			if (!isset($teams[$team])) {
				$teams[$team] = 0;
			}
		}
		if (count($teams) == 0) {
			return '';
		}
		uksort($teams, 'strnatcasecmp');
		$all = count($selected) == 0;
		$h = "<div class='re_team_list' role='group' aria-label='Teams'>"
			. "<label class='re_team_row re_team_all'><input type='checkbox' class='" . $prefix . "_team_all'" . ($all ? ' checked' : '')
			. " onchange='ruleTeamsPick(this, \"" . $prefix . "\"); " . $onchange . "'/><span class='re_team_name'>All teams</span>"
			. "<span class='re_team_count'>" . count(array_filter($teams)) . " team" . (count(array_filter($teams)) == 1 ? '' : 's') . "</span></label>";
		foreach ($teams as $team => $count) {
			$h .= "<label class='re_team_row'><input type='checkbox' class='" . $prefix . "_team_check' value='"
				. htmlspecialchars($team, ENT_QUOTES) . "'" . (in_array((string)$team, $selected, true) ? ' checked' : '')
				. " onchange='ruleTeamsPick(this, \"" . $prefix . "\"); " . $onchange . "'/>"
				. "<span class='re_team_name'>" . htmlspecialchars($team) . "</span>"
				. "<span class='re_team_count'>" . ($count == 0 ? 'nothing upcoming' : $count . ' game' . ($count == 1 ? '' : 's')) . "</span>"
				. "</label>";
		}
		return $h . "</div>";
	}

	// data-teams for an airing's row, so the dialog can filter by team
	function rulesTeamsAttr($episode) {
		return " data-teams='" . htmlspecialchars(implode('|', rulesEpisodeTeams($episode)), ENT_QUOTES) . "'";
	}

	// When an episode airs, e.g. "Thu 26 Nov 20:00" - no year, the guide only covers the next 14 days
	function rulesWhen($start) {
		$start = (int)$start;
		return $start > 0 ? date('D ' . trim(str_replace('Y', '', dvruiDateFormat())) . ' H:i', $start) : '';
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
			. "<h3 class='modal_title' id='re_title'>Editing Rule &ldquo;" . htmlspecialchars($title) . "&rdquo;</h3>"
			. "<form id='re_form' onsubmit='saveRuleEditor(); return false;'>"
			// the series image, with the settings to its right
			. "<div class='re_top'><img class='re_image' alt='' src='"
			. htmlspecialchars(rulesText($rule, 'ImageURL') != '' ? rulesText($rule, 'ImageURL') : './images/no-preview.png', ENT_QUOTES) . "'"
			. " onerror=\"this.onerror=null;this.src='./images/no-preview.png';\"/>"
			. "<div class='re_settings'>";

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

			// teams - for a sports series (or a rule that already has teams)
			$ruleTeams = rulesTeamList(rulesText($rule, 'TeamOnly'));
			$teams = rulesTeamsChoices('re', $episodes, $ruleTeams === null ? array() : $ruleTeams, 'updateRuleEditor()');
			if ($teams != '') {
				$h .= "<fieldset class='re_section'><legend>Teams</legend>" . $teams . "</fieldset>";
			}

			// what it records: all, the current season, or new episodes only (first
			// aired on or after a date - a rule that has one keeps its date)
			$after = rulesText($rule, 'AfterOriginalAirdateOnly');
			$record = preg_match('/^\d+$/', $after) ? 'new' : (rulesText($rule, 'RecentOnly') == '1' ? 'recent' : 'all');
			$newLabel = preg_match('/^\d+$/', $after)
				? 'New episodes only (first aired from ' . gmdate(dvruiDateFormat(), (int)$after) . ')'
				: 'New episodes only';
			$h .= "<fieldset class='re_section'><legend>Record</legend>"
				. "<label class='re_choice'><input type='radio' name='re_recent' value='0'" . ($record == 'all' ? ' checked' : '') . "/>All seasons</label>"
				. "<label class='re_choice'><input type='radio' name='re_recent' value='1'" . ($record == 'recent' ? ' checked' : '') . "/>Current season</label>"
				. "<label class='re_choice' title='Episodes first aired from " . ($after != '' ? 'that date' : 'today') . " on - reruns of older episodes are skipped'>"
				. "<input type='radio' name='re_recent' value='new'" . ($record == 'new' ? ' checked' : '') . "/>" . htmlspecialchars($newLabel) . "</label>"
				. "</fieldset>";
		}

		// padding
		$h .= "<fieldset class='re_section'><legend>Padding</legend><div class='re_padding'>"
			. "<label>Start <select id='re_start'>" . rulesPaddingOptions(rulesText($rule, 'StartPadding'), 3600) . "</select> early</label>"
			. "<label>End <select id='re_end'>" . rulesPaddingOptions(rulesText($rule, 'EndPadding'), 10800) . "</select> late</label>"
			. "</div></fieldset>"
			. "</div></div>";

		// what's coming up
		$h .= "<div class='re_upcoming'><div class='re_upcoming_title'>Upcoming episodes</div>";
		if (count($episodes)) {
			$h .= "<div class='re_upcoming_list'><table>"
				. "<thead><tr><th>When</th><th>Channel</th><th>Episode</th><th class='re_rec'>Recording</th></tr></thead><tbody>";
			foreach ($episodes as $episode) {
				$name = trim(rulesText($episode, 'EpisodeNumber') . ' ' . rulesText($episode, 'EpisodeTitle'));
				$h .= "<tr data-channel='" . htmlspecialchars(rulesText($episode, 'ChannelNumber'), ENT_QUOTES) . "'" . rulesTeamsAttr($episode) . ">"
					. "<td class='re_when'>" . htmlspecialchars(rulesWhen(rulesText($episode, 'StartTime'))) . "</td>"
					. "<td class='re_ch'>" . htmlspecialchars(trim(rulesText($episode, 'ChannelNumber') . ' ' . rulesText($episode, 'ChannelName'))) . "</td>"
					. "<td class='re_ep'>" . htmlspecialchars($name != '' ? $name : rulesText($episode, 'Title')) . "</td>"
					. "<td class='re_rec'>" . rulesRecordCell(rulesText($episode, 'RecordingRuleExt')) . "</td></tr>";
			}
			$h .= "</tbody></table></div>";
		} else {
			$h .= "<p class='re_none'>There are no upcoming episodes of this show in the guide.</p>";
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

	/*
	 * New Rule dialog (+ New Rule on the Rules page). Search the guide
	 * (api/search), pick a series from what matches to see its upcoming airings,
	 * then record all of it or the current season (a rule on all channels), or a
	 * single airing (a one-time rule). The padding starts at the Start/End padding
	 * settings and can be changed for each rule. The dialog stays open, so more
	 * can be added - the rules behind it are rebuilt after each one.
	 */
	function newRuleOpen() {
		ob_start();
		return newRuleResponse(array('rules_dialog' => newRuleDialogHtml()), '');
	}

	function newRuleDialogHtml() {
		return "<div class='modal_overlay' id='rule_new' onclick='if (event.target == this) closeNewRule()'"
			. " onkeydown='newRuleKey(event)'>"
			. "<div class='modal_box rule_editor rule_new' role='dialog' aria-modal='true' aria-labelledby='nr_title'>"
			. "<h3 class='modal_title' id='nr_title'>New Rule</h3>"
			. "<form id='nr_search_form' onsubmit='searchNewRule(); return false;'>"
			. "<input type='search' id='nr_search' maxlength='100' autocomplete='off'"
			. " placeholder='Search the guide - a show, film, team or topic' aria-label='Search the guide'/>"
			. "<button type='submit' class='modal_button' id='nr_search_button'>Search</button></form>"
			. "<div id='nr_body'>"
			. "<div id='nr_results'><p class='re_note'>Search for something to record - the shows in the guide that match are listed with how many times they air in the next two weeks.</p></div>"
			. "<div id='nr_series'></div></div>"
			. "<fieldset class='re_section'><legend>Padding for this rule</legend><div class='re_padding'>"
			. "<label>Start <input type='number' id='nr_start' min='0' max='3600' step='1' value='"
			. (int)dvruiSetting('DVRUI_startPadding') . "'/> seconds early</label>"
			. "<label>End <input type='number' id='nr_end' min='0' max='10800' step='1' value='"
			. (int)dvruiSetting('DVRUI_endPadding') . "' oninput='this.setAttribute(\"data-edited\", \"1\")'/> seconds late"
			. " <span id='nr_end_note' class='re_dim'></span></label>"
			. "</div></fieldset>"
			. "<div id='nr_footer'><p id='nr_message' role='status'></p><div class='modal_buttons'>"
			. "<button type='button' class='modal_button nr_default' id='nr_cancel' onclick='closeNewRule()'>Cancel</button>"
			. "<button type='button' class='modal_button modal_ok' id='nr_save' onclick='saveNewRule()' disabled"
			. " title='Search for a show and pick one first'>Save</button></div></div>"
			. "</div></div>";
	}

	/* Search button - the series that match, with how many times each airs */
	function newRuleSearch($term) {
		ob_start();
		$term = trim(preg_replace('/\s+/', ' ', (string)$term));
		if (mb_strlen($term) < 2 || mb_strlen($term) > 100) {
			return newRuleResponse(array('nr_results' => newRuleNote('Type at least two letters to search for.'),
				'nr_series' => ''), '');
		}
		$hdhr = new DVRUI_HDHRjson();
		$auth = rulesDeviceAuth($hdhr);
		if ($auth == '') {
			return newRuleResponse(array('nr_results' => newRuleNote('No HDHomeRun tuners found - the guide is searched for the account the tuners are registered to.', true),
				'nr_series' => ''), '');
		}
		$found = getJsonFromApi(DVRUI_Vars::DVRUI_apiurl . 'api/search?DeviceAuth=' . urlencode($auth)
			. '&Search=' . urlencode($term));
		$series = array();
		foreach (is_array($found) ? $found : array() as $s) {
			if (is_array($s) && rulesText($s, 'SeriesID') != '') {
				$series[rulesText($s, 'SeriesID')] = $s;
			}
		}
		if (count($series) == 0) {
			// the API answers "null" when nothing matches
			$text = ($found === null || is_array($found))
				? 'Nothing in the guide matches “' . $term . '”.'
				: 'Unable to search the guide on the SiliconDust servers.';
			return newRuleResponse(array('nr_results' => newRuleNote($text, !($found === null || is_array($found))),
				'nr_series' => ''), '');
		}

		// how many times each airs - one api/episodes each, cached for when one is picked
		$urls = array();
		foreach ($series as $id => $s) {
			$urls[$id] = rulesEpisodesUrl($auth, $id);
		}
		$counts = array();
		foreach (getJsonFromApis($urls, 12) as $id => $episodes) {
			$counts[$id] = is_array($episodes) ? count(array_filter($episodes, 'is_array')) : 0;
		}
		$ruled = newRuleSeriesRules(rulesFetch($hdhr));

		// those airing first, in the order SiliconDust gave them
		$order = array_keys($series);
		usort($order, function($a, $b) use ($counts, $order) {
			if (($counts[$a] > 0) != ($counts[$b] > 0)) {
				return ($counts[$a] > 0) ? -1 : 1;
			}
			return array_search($a, $order) - array_search($b, $order);
		});

		$h = "<div class='nr_results_title'>" . count($series) . ' show' . (count($series) == 1 ? '' : 's')
			. ' match “' . htmlspecialchars($term) . '” - pick one to see when it airs</div><div class="nr_list">';
		foreach ($order as $id) {
			$s = $series[$id];
			$title = rulesText($s, 'Title');
			$image = rulesText($s, 'ImageURL');
			$h .= "<button type='button' class='nr_item' data-seriesid='" . htmlspecialchars($id, ENT_QUOTES) . "'"
				. " data-title='" . htmlspecialchars($title, ENT_QUOTES) . "' onclick='pickNewRuleSeries(this)'>"
				. "<img class='nr_thumb' loading='lazy' alt='' src='" . htmlspecialchars($image != '' ? $image : './images/no-preview.png', ENT_QUOTES) . "'"
				. " onerror=\"this.onerror=null;this.src='./images/no-preview.png';\"/>"
				. "<span class='nr_item_title'>" . htmlspecialchars($title != '' ? $title : 'Untitled') . "</span>";
			if (isset($ruled[$id])) {
				$h .= "<span class='rule_opt rule_opt_limit nr_has_rule'>Has a rule</span>";
			}
			$h .= "<span class='nr_item_count" . ($counts[$id] == 0 ? ' re_dim' : '') . "'>"
				. ($counts[$id] == 0 ? 'nothing upcoming' : $counts[$id] . ' airing' . ($counts[$id] == 1 ? '' : 's'))
				. "</span></button>";
		}
		$h .= "</div>";
		return newRuleResponse(array('nr_results' => $h, 'nr_series' => ''), '');
	}

	/* A series was picked - its upcoming airings and the buttons to record it */
	function newRuleSeries($seriesId, $title) {
		ob_start();
		$seriesId = trim((string)$seriesId);
		if (!preg_match('/^[A-Za-z0-9]+$/', $seriesId)) {
			return newRuleResponse(array('nr_series' => newRuleNote('That is not a show in the guide.', true)), '');
		}
		$hdhr = new DVRUI_HDHRjson();
		return newRuleResponse(array('nr_series' => newRuleSeriesHtml($hdhr, $seriesId, (string)$title)), '');
	}

	/*
	 * Save - a new rule for the series: $record 'all' episodes, 'recent' (the current
	 * season) or 'new' (new episodes only - first aired from today), on all channels
	 * ($channel '') or one, for all teams ($teams '') or some, put at the 'lowest' or
	 * 'highest' $priority. SiliconDust replaces the rule the series already has.
	 */
	function newRuleAdd($seriesId, $title, $record, $channel, $teams, $priority, $startPadding, $endPadding) {
		ob_start();
		$seriesId = trim((string)$seriesId);
		$title = trim((string)$title);
		$channel = trim((string)$channel);
		if ($channel !== '' && !preg_match('/^[0-9]+(\.[0-9]+)?$/', $channel)) {
			return newRuleResponse(array(), 'Unable to add the rule - that is not a channel in the guide', true);
		}
		$teamList = rulesTeamList($teams);
		if ($teamList === null) {
			return newRuleResponse(array(), 'Unable to add the rule - the teams are not valid', true);
		}
		$record = (string)$record;
		$priority = (string)$priority;
		if (!preg_match('/^[A-Za-z0-9]+$/', $seriesId) || !in_array($record, array('all', 'recent', 'new'), true)
				|| !in_array($priority, array('lowest', 'highest'), true)) {
			return newRuleResponse(array(), 'Unable to add the rule - the choices are not valid', true);
		}
		if (!newRulePaddingValid($startPadding, $endPadding)) {
			return newRuleResponse(array(), 'Unable to add the rule - the padding must be whole seconds (start up to 3600, end up to 10800)', true);
		}
		$hdhr = new DVRUI_HDHRjson();
		$auth = rulesDeviceAuth($hdhr);
		$params = array('DeviceAuth' => $auth, 'Cmd' => 'add', 'SeriesID' => $seriesId,
			'RecentOnly' => ($record === 'recent' ? '1' : '0'), 'StartPadding' => (int)$startPadding, 'EndPadding' => (int)$endPadding);
		if ($record === 'new') {
			$params['AfterOriginalAirdateOnly'] = rulesNewEpisodesFrom();
		}
		if ($channel !== '') {
			$params['ChannelOnly'] = $channel;
		}
		if (count($teamList)) {
			$params['TeamOnly'] = implode('|', $teamList);
		}
		$what = array('all' => 'all episodes of ', 'recent' => 'the current season of ', 'new' => 'new episodes of ')[$record] . $title;
		if ($record === 'new') {
			$what .= ' (first aired from ' . gmdate(dvruiDateFormat(), $params['AfterOriginalAirdateOnly']) . ')';
		}
		if (count($teamList)) {
			$what .= ' with ' . implode(', ', $teamList);
		}
		if ($channel !== '') {
			$what .= ' on channel ' . $channel;
		}
		return newRuleAdded($hdhr, $params, $seriesId, $title, 'Unable to add a rule to record ' . $what,
			function($fetched) use ($seriesId, $what) {
				foreach (rulesSorted((array)$fetched['Rules']) as $pos => $rule) {
					if (rulesText($rule, 'SeriesID') === $seriesId && rulesText($rule, 'DateTimeOnly') == '') {
						return 'Recording ' . $what . ' - rule added at priority ' . ($pos + 1);
					}
				}
				return 'Recording ' . $what;
			}, $channel, true, $priority === 'highest');
	}

	/* The record button on an airing - a one-time rule for that time and channel */
	function newRuleAddOnce($seriesId, $title, $startTime, $channel, $startPadding, $endPadding, $filter = '') {
		ob_start();
		$seriesId = trim((string)$seriesId);
		$title = trim((string)$title);
		$startTime = trim((string)$startTime);
		$channel = trim((string)$channel);
		if (!preg_match('/^[A-Za-z0-9]+$/', $seriesId) || !preg_match('/^\d+$/', $startTime)
				|| !preg_match('/^[0-9]+(\.[0-9]+)?$/', $channel)) {
			return newRuleResponse(array(), 'Unable to record that airing - it is not in the guide', true);
		}
		if (!newRulePaddingValid($startPadding, $endPadding)) {
			return newRuleResponse(array(), 'Unable to record that airing - the padding must be whole seconds (start up to 3600, end up to 10800)', true);
		}
		$hdhr = new DVRUI_HDHRjson();
		$params = array('DeviceAuth' => rulesDeviceAuth($hdhr), 'Cmd' => 'add', 'SeriesID' => $seriesId,
			'DateTimeOnly' => $startTime, 'ChannelOnly' => $channel,
			'StartPadding' => (int)$startPadding, 'EndPadding' => (int)$endPadding);
		$what = $title . ' on ' . rulesWhen($startTime) . ' (channel ' . $channel . ')';
		return newRuleAdded($hdhr, $params, $seriesId, $title, 'Unable to record ' . $what,
			function($fetched) use ($what) {
				return 'Recording ' . $what . ' once';
			}, preg_match('/^[0-9]+(\.[0-9]+)?$/', trim((string)$filter)) ? trim((string)$filter) : '');
	}

	/*
	 * Send a new rule, then tell the record engines and rebuild the rules behind the
	 * dialog and the series shown (its airings now show what will be recorded),
	 * keeping the channel filter ($filter) - or close the dialog ($close, Save).
	 * $top moves a new series rule to the highest priority - SiliconDust adds it at
	 * the lowest.
	 */
	function newRuleAdded($hdhr, $params, $seriesId, $title, $failed, $describe, $filter, $close = false, $top = false) {
		if ($params['DeviceAuth'] == '' || rulesApiPost($params) === false) {
			return newRuleResponse(array(), $failed . ' on the SiliconDust servers', true);
		}
		if ($top) {
			foreach ((array)rulesFetch($hdhr)['Rules'] as $rule) {
				if (rulesText($rule, 'SeriesID') === $seriesId && rulesText($rule, 'DateTimeOnly') == '') {
					rulesApiPost(array('DeviceAuth' => $params['DeviceAuth'], 'Cmd' => 'change',
						'RecordingRuleID' => rulesText($rule, 'RecordingRuleID'), 'AfterRecordingRuleID' => '0'));
					break;
				}
			}
		}
		$synced = rulesSyncEngines($hdhr);
		$fetched = rulesFetch($hdhr);
		$message = $describe($fetched) . ' - ' . $synced[0] . ' of ' . $synced[1]
			. ' record engine' . ($synced[1] == 1 ? '' : 's') . ' updated';
		if ($close) {
			return newRuleResponse(array('rules_box' => buildRulesPage($fetched, $hdhr), 'rules_dialog' => ''), $message);
		}
		return newRuleResponse(array('rules_box' => buildRulesPage($fetched, $hdhr),
			'nr_series' => newRuleSeriesHtml($hdhr, $seriesId, $title, $filter)), $message);
	}

	function newRuleSeriesHtml($hdhr, $seriesId, $title, $channel = '') {
		$channel = (string)$channel;
		$episodes = rulesUpcoming($hdhr, $seriesId);
		$image = '';
		foreach ($episodes as $episode) {
			if ($title == '') {
				$title = rulesText($episode, 'Title');
			}
			if ($image == '') {
				$image = rulesText($episode, 'ImageURL');
			}
		}
		// the rule the series already has (not a one-time one)
		$fetched = rulesFetch($hdhr);
		$existing = null;
		foreach (rulesSorted((array)$fetched['Rules']) as $pos => $rule) {
			if (rulesText($rule, 'SeriesID') === $seriesId && rulesText($rule, 'DateTimeOnly') == '') {
				$existing = array($rule, $pos + 1);
				break;
			}
		}
		if ($existing !== null) {
			if ($image == '') {
				$image = rulesText($existing[0], 'ImageURL');
			}
			if ($title == '') {
				$title = rulesText($existing[0], 'Title');
			}
		}

		// sports (games with teams) start with the longer Sports end padding
		$sports = false;
		foreach ($episodes as $episode) {
			if (count(rulesEpisodeTeams($episode))) {
				$sports = true;
				break;
			}
		}
		$endPadding = (int)dvruiSetting($sports ? 'DVRUI_sportsEndPadding' : 'DVRUI_endPadding');

		$h = "<div id='nr_selected' data-seriesid='" . htmlspecialchars($seriesId, ENT_QUOTES) . "'"
			. " data-title='" . htmlspecialchars($title, ENT_QUOTES) . "'"
			. " data-end-padding='" . $endPadding . "' data-sports='" . ($sports ? '1' : '0') . "'>"
			. "<div class='re_top'><img class='re_image' alt='' src='" . htmlspecialchars($image != '' ? $image : './images/no-preview.png', ENT_QUOTES) . "'"
			. " onerror=\"this.onerror=null;this.src='./images/no-preview.png';\"/>"
			. "<div class='re_settings'><h4 class='nr_series_title'>" . htmlspecialchars($title != '' ? $title : 'Untitled') . "</h4>";
		if ($existing !== null) {
			$options = array();
			foreach (rulesOptions($existing[0]) as $option) {
				$options[] = $option[0];
			}
			$h .= "<p class='re_note'>Already has a rule at priority " . $existing[1] . ' (' . htmlspecialchars(implode(' · ', $options))
				. ') - recording it again replaces that rule.</p>';
		}
		// the channels it airs on (number => name, airings), for the channel filter
		$channels = array();
		foreach ($episodes as $episode) {
			$number = rulesText($episode, 'ChannelNumber');
			if ($number == '') {
				continue;
			}
			if (!isset($channels[$number])) {
				$channels[$number] = array(rulesText($episode, 'ChannelName'), 0);
			}
			$channels[$number][1]++;
		}
		uksort($channels, 'strnatcmp');
		if (!isset($channels[$channel])) {
			$channel = '';
		}
		$filter = (string)$channel;
		$h .= "<label class='nr_channel'><span class='nr_record_label'>Channel</span><select id='nr_channel' onchange='filterNewRule()'>"
			. "<option value=''>All channels</option>";
		foreach ($channels as $number => $info) {
			$h .= "<option value='" . htmlspecialchars($number, ENT_QUOTES) . "'" . ((string)$number === $filter ? ' selected' : '') . ">"
				. htmlspecialchars(trim($number . ' ' . $info[0]) . ' (' . $info[1] . ')') . "</option>";
		}
		$h .= "</select></label>";
		// a sports series - its games can be limited to some teams
		$teams = rulesTeamsChoices('nr', $episodes, array(), 'filterNewRule()');
		if ($teams != '') {
			$h .= "<div class='nr_teams'><span class='nr_record_label'>Teams</span><div class='nr_teams_choices'>" . $teams . "</div></div>";
		}
		$h .= "</div></div>";

		// Record and Insert Priority (start at the settings) - the width of the airings, just above them
		$h .= "<div class='nr_options'>"
			. newRuleRadios('nr_record', 'Record', array('all' => 'All episodes', 'recent' => 'Current season',
				'new' => 'New episodes only'), dvruiSetting('DVRUI_newRuleRecord'),
				array('new' => 'Episodes first aired from today on - reruns of older episodes are skipped'))
			. newRuleRadios('nr_priority', 'Insert Priority', array('lowest' => 'Lowest', 'highest' => 'Highest'),
				dvruiSetting('DVRUI_newRulePriority'),
				array('lowest' => 'Added at the bottom of the rules - the others record first when they want the same tuner',
					'highest' => 'Added at the top of the rules (under one-time recordings) - it records first when rules want the same tuner'))
			. "</div>";

		$h .= "<div class='re_upcoming'><div class='re_upcoming_title'>Upcoming airings</div>";
		if (count($episodes)) {
			// record icon - a dot in a ring
			$record = "<svg viewBox='0 0 24 24' width='16' height='16' aria-hidden='true'>"
				. "<circle cx='12' cy='12' r='9' fill='none' stroke='currentColor' stroke-width='2'/>"
				. "<circle cx='12' cy='12' r='5' fill='currentColor'/></svg>";
			$h .= "<div class='re_upcoming_list nr_upcoming_list'><table>"
				. "<thead><tr><th>When</th><th>Channel</th><th>Episode</th><th class='re_rec'>Recording</th><th class='nr_once_col'><span class='nr_sr'>Record once</span></th></tr></thead><tbody>";
			foreach ($episodes as $episode) {
				$name = trim(rulesText($episode, 'EpisodeNumber') . ' ' . rulesText($episode, 'EpisodeTitle'));
				$when = rulesWhen(rulesText($episode, 'StartTime'));
				$channel = rulesText($episode, 'ChannelNumber');
				$label = 'Record just this airing - ' . $when . ' on ' . trim($channel . ' ' . rulesText($episode, 'ChannelName'));
				$h .= "<tr data-channel='" . htmlspecialchars($channel, ENT_QUOTES) . "'" . rulesTeamsAttr($episode)
					. ($filter != '' && $channel !== $filter ? " class='nr_hidden'" : '') . ">"
					. "<td class='re_when'>" . htmlspecialchars($when) . "</td>"
					. "<td class='re_ch'>" . htmlspecialchars(trim($channel . ' ' . rulesText($episode, 'ChannelName'))) . "</td>"
					. "<td class='re_ep'>" . htmlspecialchars($name != '' ? $name : rulesText($episode, 'Title')) . "</td>"
					. "<td class='re_rec'>" . rulesRecordCell(rulesText($episode, 'RecordingRuleExt')) . "</td>"
					. "<td class='nr_once_col'><button type='button' class='nr_once' onclick='addNewRuleOnce(this)'"
					. " data-start='" . htmlspecialchars(rulesText($episode, 'StartTime'), ENT_QUOTES) . "'"
					. " data-channel='" . htmlspecialchars($channel, ENT_QUOTES) . "'"
					. " title='" . htmlspecialchars($label, ENT_QUOTES) . "' aria-label='" . htmlspecialchars($label, ENT_QUOTES) . "'>"
					. $record . "</button></td></tr>";
			}
			$h .= "</tbody></table></div>";
		} else {
			$h .= "<p class='re_none'>There are no upcoming airings of this show in the guide - a rule records it when it comes back.</p>";
		}
		return $h . "</div></div>";
	}

	// a row of radio buttons with its label (Record, Priority) - $tips are tooltips by value
	function newRuleRadios($name, $label, $choices, $current, $tips = array()) {
		if (!isset($choices[$current])) {
			$current = array_key_first($choices);
		}
		$h = "<div class='nr_record' role='radiogroup' aria-label='" . htmlspecialchars($label, ENT_QUOTES) . "'>"
			. "<span class='nr_record_label'>" . htmlspecialchars($label) . "</span>";
		foreach ($choices as $value => $text) {
			$h .= "<label class='re_choice'" . (isset($tips[$value]) ? " title='" . htmlspecialchars($tips[$value], ENT_QUOTES) . "'" : '') . ">"
				. "<input type='radio' name='" . $name . "' value='" . htmlspecialchars($value, ENT_QUOTES) . "'"
				. ((string)$value === (string)$current ? ' checked' : '') . "/>" . htmlspecialchars($text) . "</label>";
		}
		return $h . "</div>";
	}

	// The date a "new episodes only" rule records from - today in the UI timezone,
	// as midnight UTC like the guide's OriginalAirdate (SiliconDust records episodes
	// first aired on or after it)
	function rulesNewEpisodesFrom() {
		return strtotime(date('Y-m-d') . ' 00:00:00 UTC');
	}

	// series ID => true for the series with a rule (not one-time ones)
	function newRuleSeriesRules($fetched) {
		$ruled = array();
		foreach ((array)$fetched['Rules'] as $rule) {
			if (is_array($rule) && rulesText($rule, 'DateTimeOnly') == '' && rulesText($rule, 'SeriesID') != '') {
				$ruled[rulesText($rule, 'SeriesID')] = true;
			}
		}
		return $ruled;
	}

	// the same limits as the Edit dialog
	function newRulePaddingValid($start, $end) {
		return preg_match('/^\d{1,5}$/', (string)$start) && (int)$start <= 3600
			&& preg_match('/^\d{1,5}$/', (string)$end) && (int)$end <= 10800;
	}

	function newRuleNote($text, $error = false) {
		return "<p class='" . ($error ? 're_none' : 're_note') . "'>" . htmlspecialchars($text) . "</p>";
	}

	// id => html out, and the message for the dialog and the status bar (ob_start is called by the caller)
	function newRuleResponse($parts, $message, $error = false) {
		$tab = new DVRUI_AjaxResponse();
		$result = ob_get_contents();
		ob_end_clean();
		foreach ($parts as $id => $html) {
			$tab->setHtml($id, $html);
		}
		if ($message != '') {
			$tab->setHtml('nr_message', "<span class='" . ($error ? 're_error' : 'nr_ok') . "'>" . htmlspecialchars($message) . "</span>");
		}
		if ($result != '' && $result != NULL) {
			$tab->setHtml("statusMessage", $result);
		} else if ($message != '') {
			$tab->setHtml("statusMessage", htmlspecialchars($message));
		}
		return $tab->getString();
	}

	// Page and status message out (ob_start is called by the caller)
	function rulesResponse($htmlStr, $message) {
		// the cloud JSON is UTF-8 already - don't re-encode it
		$tab = new DVRUI_AjaxResponse();
		$result = ob_get_contents();
		ob_end_clean();
		$tab->setHtml("rules_box", $htmlStr);
		if ($result != '' && $result != NULL) {
			$tab->setHtml("statusMessage", $result);
		} else if ($message != '') {
			$tab->setHtml("statusMessage", htmlspecialchars($message));
		} else {
			$tab->setHtml("statusMessage", getLatestHDHRStatus());
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
		$rules = getJsonFromApi(DVRUI_Vars::DVRUI_apiurl . 'api/recording_rules?DeviceAuth=' . urlencode($auth));
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
		// the rules may have changed (even if the answer was lost) - fetch them fresh next time
		dvruiApiCacheClear();
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
		$newButton = "<button type='button' id='rules_new' class='modal_button' onclick='newRule()'"
			. " title='Search the guide and add a recording rule'>+ New Rule</button>";
		if (count($rules) == 0) {
			return "<div id='rules_header'><span class='rules_header_text'>There are no recording rules.</span>"
				. $newButton . "</div>";
		}
		$rules = rulesSorted($rules);
		$channelNames = rulesChannelNames($hdhr, $rules);

		$htmlStr = "<div id='rules_header'><span class='rules_header_text'><span id='rules_count'>" . count($rules) . ' recording rule'
			. (count($rules) == 1 ? '' : 's') . "</span> &middot; highest priority first"
			. " <span id='rules_hint'>&middot; drag a rule up or down to change its priority</span></span>"
			. $newButton . "</div>";
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
		// new episodes only - first aired on or after the date (original air dates are midnight UTC)
		$after = rulesText($rule, 'AfterOriginalAirdateOnly');
		$newOnly = preg_match('/^\d+$/', $after);
		if (rulesText($rule, 'RecentOnly') == '1') {
			$options[] = array('Current season', true);
		} else if (!preg_match('/^\d+$/', $dateTime) && !$newOnly) {
			$options[] = array('All seasons', false);
		}
		if ($newOnly) {
			$options[] = array('New episodes from ' . gmdate(dvruiDateFormat(), (int)$after), true);
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
