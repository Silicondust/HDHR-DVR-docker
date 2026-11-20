<?php
final class DVRUI_Vars 
{
	const DVRUI_version	="3.4.0";
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
	const DVRUI_cloudDiscover = true;
	const DVRUI_TZ      ='';
	const DVRUI_ENABLE  ='full';   // full, minimal (Dashboard and Diagnostics) - set from DVRUI_ENABLE at container start
	const DVRUI_Log     ="/HDHomeRunDVR/data/dvrui.log";
	const DVRUI_DEBUG   = true;
}
?>
