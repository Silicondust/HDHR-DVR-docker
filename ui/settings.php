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
	require_once("includes/dvrui_common.php");
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
		$tab->output($result);
		return $tab->getString();
	}

	/* a setting was changed - $value is '1' (on) or '0' (off) for a switch, the number,
	 * or the choice for a drop-down */
	function saveSetting($name, $value) {
		ob_start();
		$tab = new DVRUI_AjaxResponse();
		$name = (string)$name;
		$value = trim((string)$value);
		$defs = dvruiSettingsDefs();
		if (!isset($defs[$name])) {
			$error = 'Unable to save - that is not a setting';
		} elseif (dvruiSettingChoices($name) !== null) {
			$error = dvruiSaveSetting($name, $value);
			if ($error != '') {
				$error = 'Unable to save - that is not one of the choices for ' . $defs[$name][1];
			}
		} elseif (($number = dvruiSettingNumber($name)) !== null) {
			$error = dvruiSaveSetting($name, $value);
			if ($error != '') {
				$error = 'Unable to save - ' . $defs[$name][1] . ' must be a whole number from '
					. $number[0] . ' to ' . $number[1];
			}
		} elseif (!in_array($value, array('0', '1'), true)) {
			$error = 'Unable to save - that is not a setting';
		} else {
			$error = dvruiSaveSetting($name, $value === '1');
		}
		$message = ($error != '') ? $error
			: $defs[$name][1] . ' is ' . dvruiSettingText($name);
		$html = settingsPageHtml($error);
		$result = ob_get_contents();
		ob_end_clean();
		$tab->setHtml("settings_box", $html);
		$tab->output($result);
		$tab->message($message, ($error != '') ? 'error' : 'ok');
		return $tab->getString();
	}

	/*
	 * Reload button - forget the cached SiliconDust server answers (see
	 * getJsonFromApi), so the page it then reloads fetches them fresh and
	 * fills the cache again
	 */
	function clearApiCache() {
		dvruiApiCacheClear();
		$tab = new DVRUI_AjaxResponse();
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
				$id = 'setting_' . $name;
				$choices = dvruiSettingChoices($name);
				if ($choices !== null) {
					// a drop-down under the description - saved when it is changed
					$h .= "<div class='setting'><div class='setting_text'>"
						. "<label class='setting_label' for='" . $id . "'>" . htmlspecialchars($def[1]) . "</label>"
						. "<p class='setting_help'>" . htmlspecialchars($def[2]) . "</p>"
						. "<select class='setting_select' id='" . $id . "' data-setting='" . htmlspecialchars($name, ENT_QUOTES) . "'"
						. ($writable ? '' : ' disabled') . " onchange='changeSetting(this)'>";
					foreach ($choices as $value => $label) {
						$h .= "<option value='" . htmlspecialchars($value, ENT_QUOTES) . "'"
							. ((string)dvruiSetting($name) === (string)$value ? ' selected' : '') . ">" . htmlspecialchars($label) . "</option>";
					}
					$h .= "</select></div></div>";
					continue;
				}
				$number = dvruiSettingNumber($name);
				if ($number !== null) {
					// a number box - saved when it is changed (Enter, or leaving the box)
					$h .= "<div class='setting'>"
						. "<span class='setting_number'><input type='number' id='" . $id . "'"
						. " data-setting='" . htmlspecialchars($name, ENT_QUOTES) . "'"
						. " min='" . (int)$number[0] . "' max='" . (int)$number[1] . "' step='1'"
						. " value='" . (int)dvruiSetting($name) . "'" . ($writable ? '' : ' disabled')
						. " onchange='changeSetting(this)'/><span class='setting_unit'>" . htmlspecialchars($number[2]) . "</span></span>"
						. "<div class='setting_text'><label class='setting_label' for='" . $id . "'>" . htmlspecialchars($def[1]) . "</label>"
						. "<p class='setting_help'>" . htmlspecialchars($def[2]) . "</p></div></div>";
					continue;
				}
				$on = dvruiSetting($name);
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
