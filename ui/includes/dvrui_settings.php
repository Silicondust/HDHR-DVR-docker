<?php
/*
 * ui/includes/dvrui_settings.php
 *
 * Copyright (c) 2020 Rik Dunphy
 * Copyright (c) 2026 Silicondust USA Inc.
 * Released under the MIT License - see LICENSE
 */
	require_once("vars.php");

/*
 * UI settings - changed on the Settings page and kept in a JSON file in the data
 * folder (DVRUI_Vars::DVRUI_Settings, /dvrdata/dvrui_settings.json), so they last
 * through container restarts and updates. A setting that isn't in the file uses
 * its default from vars.php.
 */

/*
 * The settings, in the order the Settings page shows them:
 *   name => array(group, label, description, default[, kind])
 * A setting is an on/off switch, unless it has the fifth item:
 *   array(min, max, unit, text for 0 or '')      a whole number
 *   array('choices' => array(value => label))    one of a list (a drop-down)
 */
function dvruiSettingsDefs() {
	return array(
		'DVRUI_mdnsDiscover' => array('Discovery', 'mDNS discovery',
			'Look up hdhomerun.local with multicast DNS - finds devices on other subnets when the network relays mDNS.',
			dvruiSettingDefault('DVRUI_mdnsDiscover')),
		'DVRUI_ipv6Discover' => array('Discovery', 'IPv6 discovery',
			'Discover devices over IPv6 (multicast to ff02::176 and ff05::176, and mDNS over IPv6) when the container has IPv6.',
			dvruiSettingDefault('DVRUI_ipv6Discover')),
		'DVRUI_cloudDiscover' => array('Discovery', 'Cloud discovery',
			'Ask the SiliconDust cloud (api.hdhomerun.com) for devices on this network that local discovery did not reach.',
			dvruiSettingDefault('DVRUI_cloudDiscover')),
		'DVRUI_newRuleRecord' => array('Recording Rule Defaults', 'Record',
			'What a new rule records - the New Rule dialog starts with this, and it can be changed there for each rule. New episodes only records episodes first aired on or after the day the rule is added.',
			dvruiSettingDefault('DVRUI_newRuleRecord'),
			array('choices' => array('all' => 'All episodes', 'recent' => 'Current season', 'new' => 'New episodes only'))),
		'DVRUI_newRulePriority' => array('Recording Rule Defaults', 'Insert Priority',
			'Where a new rule goes in the priority order - when rules want the same tuner at the same time, the higher one records. The New Rule dialog starts with this, and it can be changed there for each rule.',
			dvruiSettingDefault('DVRUI_newRulePriority'),
			array('choices' => array('lowest' => 'Lowest (bottom of the list)', 'highest' => 'Highest (top of the list)'))),
		'DVRUI_startPadding' => array('Recording Rule Defaults', 'Start padding',
			'How early a recording starts, for a new rule - the New Rule dialog starts with this, and it can be changed there for each rule.',
			dvruiSettingDefault('DVRUI_startPadding'), array(0, 3600, 'seconds', '')),
		'DVRUI_endPadding' => array('Recording Rule Defaults', 'End padding',
			'How late a recording ends, for a new rule - the New Rule dialog starts with this, and it can be changed there for each rule.',
			dvruiSettingDefault('DVRUI_endPadding'), array(0, 10800, 'seconds', '')),
		'DVRUI_sportsEndPadding' => array('Recording Rule Defaults', 'Sports end padding',
			'The end padding the New Rule dialog uses for sports (a show whose games have teams), as games often run over.',
			dvruiSettingDefault('DVRUI_sportsEndPadding'), array(0, 10800, 'seconds', '')),
		'DVRUI_apiCacheSecs' => array('Optimizations', 'SiliconDust server cache',
			'How long answers from the SiliconDust servers (the recording rules, guide searches, upcoming episodes and cloud discovery) are reused before asking again. Reload, the Diagnostics page and changes made here always fetch fresh answers. 0 turns the cache off.',
			dvruiSettingDefault('DVRUI_apiCacheSecs'), array(0, 600, 'seconds', 'off')),
	);
}

/* a number setting's array(min, max, unit, text for 0), or null */
function dvruiSettingNumber($name) {
	$defs = dvruiSettingsDefs();
	return (isset($defs[$name][4]) && is_array($defs[$name][4]) && !isset($defs[$name][4]['choices'])) ? $defs[$name][4] : null;
}

/* a drop-down setting's choices (value => label), or null */
function dvruiSettingChoices($name) {
	$defs = dvruiSettingsDefs();
	return isset($defs[$name][4]['choices']) ? $defs[$name][4]['choices'] : null;
}

/* default for a setting - the vars.php constant of the same name, or on */
function dvruiSettingDefault($name) {
	$const = 'DVRUI_Vars::' . $name;
	if (!defined($const)) {
		return true;
	}
	$value = constant($const);
	return (is_int($value) || is_string($value)) ? $value : (bool)$value;
}

/* a valid value for a setting (bool for a switch, int in range for a number, one
 * of the choices for a drop-down), or null */
function dvruiSettingValid($name, $value) {
	$defs = dvruiSettingsDefs();
	if (!isset($defs[$name])) {
		return null;
	}
	$choices = dvruiSettingChoices($name);
	if ($choices !== null) {
		return (is_string($value) && isset($choices[$value])) ? $value : null;
	}
	$number = dvruiSettingNumber($name);
	if ($number === null) {
		return is_bool($value) ? $value : null;
	}
	if (is_int($value) || (is_string($value) && preg_match('/^\d{1,6}$/', $value))) {
		$value = (int)$value;
		return ($value >= $number[0] && $value <= $number[1]) ? $value : null;
	}
	return null;
}

/* a setting's value as the page shows it, e.g. "on", "30 seconds", "All episodes" */
function dvruiSettingText($name) {
	$value = dvruiSetting($name);
	$choices = dvruiSettingChoices($name);
	if ($choices !== null) {
		return isset($choices[$value]) ? $choices[$value] : (string)$value;
	}
	$number = dvruiSettingNumber($name);
	if ($number === null) {
		return $value ? 'on' : 'off';
	}
	if ($value == 0 && $number[3] != '') {
		return $number[3];
	}
	return $value . ' ' . $number[2];
}

function dvruiSettingsFile() {
	return defined('DVRUI_Vars::DVRUI_Settings') ? DVRUI_Vars::DVRUI_Settings : '/HDHomeRunDVR/data/dvrui_settings.json';
}

/* the values saved in the settings file (name => bool, int or string) - read once per request */
function dvruiSettingsSaved($reload = false) {
	static $saved = null;
	if ($saved === null || $reload) {
		$saved = array();
		$file = dvruiSettingsFile();
		$json = is_readable($file) ? @file_get_contents($file) : false;
		$data = is_string($json) ? json_decode($json, true) : null;
		if (is_array($data)) {
			foreach ($data as $name => $value) {
				// a value that isn't valid (edited by hand, or an old setting) uses the default
				$value = dvruiSettingValid($name, $value);
				if ($value !== null) {
					$saved[$name] = $value;
				}
			}
		}
	}
	return $saved;
}

/* a setting's value - saved, or its default */
function dvruiSetting($name) {
	$saved = dvruiSettingsSaved();
	if (array_key_exists($name, $saved)) {
		return $saved[$name];
	}
	return dvruiSettingDefault($name);
}

/* can the settings be saved? (the file, or its folder if it isn't there yet) */
function dvruiSettingsWritable() {
	$file = dvruiSettingsFile();
	return file_exists($file) ? is_writable($file) : is_writable(dirname($file));
}

/*
 * Save one setting. Returns '' when saved, otherwise why it wasn't.
 * The file is rewritten in place (with a lock) as the PHP user can't create
 * files in the data folder when it is owned by root.
 */
function dvruiSaveSetting($name, $value) {
	$value = dvruiSettingValid($name, $value);
	if ($value === null) {
		return 'That is not a valid value for the setting';
	}
	$file = dvruiSettingsFile();
	$fp = @fopen($file, 'c+');
	if ($fp === false) {
		return 'Unable to save - ' . $file . ' can not be written';
	}
	if (!flock($fp, LOCK_EX)) {
		fclose($fp);
		return 'Unable to save - ' . $file . ' is in use';
	}
	$data = json_decode(stream_get_contents($fp), true);
	if (!is_array($data)) {
		$data = array();
	}
	$data[$name] = $value;
	ksort($data);
	$json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
	$ok = ftruncate($fp, 0) && rewind($fp) && fwrite($fp, $json) === strlen($json);
	fflush($fp);
	flock($fp, LOCK_UN);
	fclose($fp);
	dvruiSettingsSaved(true);
	return $ok ? '' : 'Unable to save - writing ' . $file . ' failed';
}
?>
