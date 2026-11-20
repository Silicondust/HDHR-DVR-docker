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
 *   name => array(group, label, description, default)
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
	);
}

/* default for a setting - the vars.php constant of the same name, or on */
function dvruiSettingDefault($name) {
	$const = 'DVRUI_Vars::' . $name;
	return defined($const) ? (bool)constant($const) : true;
}

function dvruiSettingsFile() {
	return defined('DVRUI_Vars::DVRUI_Settings') ? DVRUI_Vars::DVRUI_Settings : '/HDHomeRunDVR/data/dvrui_settings.json';
}

/* the values saved in the settings file (name => bool) - read once per request */
function dvruiSettingsSaved($reload = false) {
	static $saved = null;
	if ($saved === null || $reload) {
		$saved = array();
		$file = dvruiSettingsFile();
		$json = is_readable($file) ? @file_get_contents($file) : false;
		$data = is_string($json) ? json_decode($json, true) : null;
		if (is_array($data)) {
			foreach ($data as $name => $value) {
				if (is_bool($value)) {
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
	$defs = dvruiSettingsDefs();
	if (!isset($defs[$name]) || !is_bool($value)) {
		return 'That is not a setting';
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
