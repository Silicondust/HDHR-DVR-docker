<?php
/*
 * ui/settings.php
 *
 * Copyright (c) 2020 Rik Dunphy
 * Copyright (c) 2026 Silicondust USA Inc.
 * Released under the MIT License - see LICENSE
 */
	require_once("includes/dvrui_ajax.php");
	require_once("includes/dvrui_settings.php");
	require_once("statusmessage.php");

	/*
	 * Settings page - the UI settings (dvrui_settings.php), grouped, each with a
	 * switch that saves as soon as it is changed
	 */
	function openSettingsPage() {
		ob_start();
		$tab = new DVRUI_AjaxResponse();
		$html = settingsPageHtml('');
		$result = ob_get_contents();
		ob_end_clean();
		$tab->setHtml("settings_box", $html);
		$tab->setHtml("statusMessage", ($result != '' && $result != NULL) ? $result : getLatestHDHRStatus());
		return $tab->getString();
	}

	/* a switch was changed - $value is '1' (on) or '0' (off) */
	function saveSetting($name, $value) {
		ob_start();
		$tab = new DVRUI_AjaxResponse();
		$name = (string)$name;
		$defs = dvruiSettingsDefs();
		if (!isset($defs[$name]) || !in_array((string)$value, array('0', '1'), true)) {
			$error = 'Unable to save - that is not a setting';
		} else {
			$error = dvruiSaveSetting($name, (string)$value === '1');
		}
		$message = ($error != '') ? $error
			: $defs[$name][1] . ' is ' . (dvruiSetting($name) ? 'on' : 'off');
		$html = settingsPageHtml($error);
		$result = ob_get_contents();
		ob_end_clean();
		$tab->setHtml("settings_box", $html);
		$tab->setHtml("statusMessage", ($result != '' && $result != NULL) ? $result : htmlspecialchars($message));
		return $tab->getString();
	}

	function settingsPageHtml($error) {
		$writable = dvruiSettingsWritable();
		$h = '';
		if ($error != '') {
			$h .= "<p class='settings_error'>" . htmlspecialchars($error) . "</p>";
		} elseif (!$writable) {
			$h .= "<p class='settings_error'>The settings can't be changed - " . htmlspecialchars(dvruiSettingsFile())
				. " can't be written by the UI. Restart the container to fix its permissions.</p>";
		}

		$groups = array();
		foreach (dvruiSettingsDefs() as $name => $def) {
			$groups[$def[0]][$name] = $def;
		}
		foreach ($groups as $group => $settings) {
			$h .= "<div class='settings_section'><h4 class='settings_group_title'>" . htmlspecialchars($group) . "</h4>"
				. "<div class='settings_group'>";
			foreach ($settings as $name => $def) {
				$on = dvruiSetting($name);
				$id = 'setting_' . $name;
				$h .= "<div class='setting'>"
					. "<label class='setting_switch' for='" . $id . "'>"
					. "<input type='checkbox' id='" . $id . "' data-setting='" . htmlspecialchars($name, ENT_QUOTES) . "'"
					. ($on ? ' checked' : '') . ($writable ? '' : ' disabled')
					. " onchange='changeSetting(this)'/><span class='setting_slider'></span></label>"
					. "<div class='setting_text'><label class='setting_label' for='" . $id . "'>" . htmlspecialchars($def[1]) . "</label>"
					. "<p class='setting_help'>" . htmlspecialchars($def[2]) . "</p></div></div>";
			}
			$h .= "</div></div>";
		}
		$h .= "<p class='settings_note'>Settings are saved in " . htmlspecialchars(dvruiSettingsFile())
			. " and take effect on the next page or Reload.</p>";
		return $h;
	}
?>
