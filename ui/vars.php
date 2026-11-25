<?php
/*
 * ui/vars.php
 *
 * Copyright (c) 2020 Rik Dunphy
 * Copyright (c) 2026 Silicondust USA Inc.
 * Released under the MIT License - see LICENSE
 */
final class DVRUI_Vars 
{
	const DVRUI_version	="3.6.0";
	const DVRUI_name    ="HDHomeRun DVR Manager";
	const DVRUI_author	="Rik Dunphy";
	const DVRUI_email   ="rikd@silicondust.com";
	const DVRUI_git     ="https://github.com/Silicondust/HDHR-DVR-docker";
	const DVR_pkgPath   ="/HDHomeRunDVR/data";
	const DVR_bin       ="hdhomerun_record";
	const DVR_sh        ="";
	const DVR_config    ="dvr.conf";
	const DVR_defaultPort ="59090";
	const DVRUI_apiurl  ="https://api.hdhomerun.com/";
	// discovery defaults - changed on the Settings page, which saves to DVRUI_Settings
	const DVRUI_mdnsDiscover = true;    // look up hdhomerun.local with mDNS (IPv4, and IPv6 if enabled)
	const DVRUI_ipv6Discover = true;    // UDP multicast (and mDNS) discovery over IPv6, when the container has IPv6
	const DVRUI_cloudDiscover = true;
	const DVRUI_Settings = "/HDHomeRunDVR/data/dvrui_settings.json";
	const DVRUI_TZ      ='';
	const DVRUI_ENABLE  ='full';   // full, minimal (Dashboard and Diagnostics) - set from DVRUI_ENABLE at container start
	const DVRUI_Log     ="/HDHomeRunDVR/data/dvrui.log";
	const DVRUI_DEBUG   = true;
}
?>
