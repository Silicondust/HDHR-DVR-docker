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
		return $entry . "<button type='button' class='rule_delete' draggable='false' title='Delete this rule'"
			. " aria-label='Delete this rule' onclick='deleteRule(this)'>" . $trash . "</button></div>";
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
	 * them unless the rule lists some), then the other limits it has (one time, team,
	 * recent, aired after) or 'All episodes', then the padding
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
		$episodeOptions = count($options);
		$dateTime = rulesText($rule, 'DateTimeOnly');
		if (preg_match('/^\d+$/', $dateTime)) {
			$options[] = array('One time: ' . date(dvruiDateFormat() . ' H:i', (int)$dateTime), true);
		}
		$team = rulesText($rule, 'TeamOnly');
		if ($team != '') {
			$options[] = array('Team: ' . $team, true);
		}
		if (rulesText($rule, 'RecentOnly') == '1') {
			$options[] = array('Recent only', true);
		}
		// original air dates are midnight UTC
		$after = rulesText($rule, 'AfterOriginalAirdateOnly');
		if (preg_match('/^\d+$/', $after)) {
			$options[] = array('Aired after ' . gmdate(dvruiDateFormat(), (int)$after), true);
		}
		if (count($options) == $episodeOptions) {
			$options[] = array('All episodes', false);
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
