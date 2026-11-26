<?php
/*
 * ui/includes/dvrui_hdhrjson.php
 *
 * Copyright (c) 2020 Rik Dunphy
 * Copyright (c) 2026 Silicondust USA Inc.
 * Released under the MIT License - see LICENSE
 */
	require_once("vars.php");
	require_once("includes/dvrui_common.php");
	require_once("includes/dvrui_recordengine_config.php");
	require_once("includes/dvrui_hdhrdiscover.php");
	require_once("includes/dvrui_hdhrmdns.php");
	require_once("includes/dvrui_settings.php");

/*
 * HDHomeRun device discovery
 *
 * Follows the SiliconDust discovery model (https://info.hdhomerun.com/info/discovery_api),
 * in this order - a device is only added by the first method that finds it:
 *  1. localhost  - the record engine running in this container, on the Port in dvr.conf
 *  2. UDP IPv4   - broadcast discovery of tuners and record engines (native PHP
 *                  implementation in dvrui_hdhrdiscover.php)
 *  3. mDNS IPv4  - hdhomerun.local looked up over multicast DNS (dvrui_hdhrmdns.php)
 *  4. UDP IPv6   - multicast discovery to ff02::176 / ff05::176
 *  5. mDNS IPv6  - hdhomerun.local looked up over IPv6 multicast DNS
 *  6. cloud      - api.hdhomerun.com, a fallback for devices the network did not reach
 * Each device's discover.json is then read. mDNS, IPv6 and cloud discovery can each be
 * turned off on the Settings page (defaults in vars.php: DVRUI_mdnsDiscover,
 * DVRUI_ipv6Discover, DVRUI_cloudDiscover).
 * Results are de-duplicated by DeviceID (tuners) and StorageID (engines).
 */
class DVRUI_HDHRjson {
	private $hdhrkey_devID = 'DeviceID';
	private $hdhrkey_localIP = 'LocalIP';
	private $hdhrkey_baseURL = 'BaseURL';
	private $hdhrkey_discoverURL = 'DiscoverURL';
	private $hdhrkey_lineupURL = 'LineupURL';
	private $hdhrkey_modelNum = 'ModelNumber';
	private $hdhrkey_modelName = 'FriendlyName';
	private $hdhrkey_auth = 'DeviceAuth';
	private $hdhrkey_fwVer = 'FirmwareVersion';
	private $hdhrkey_ver = 'Version';
	private $hdhrkey_fwName = 'FirmwareName';
	private $hdhrkey_tuners = 'TunerCount';
	private $hdhrkey_legacy = 'Legacy';
	private $hdhrkey_freespace = 'FreeSpace';
	private $hdhrkey_storageID = 'StorageID';
	private $hdhrkey_storageURL = 'StorageURL';
	private $hdhrkey_source = 'Source';
	private $hdhrlist_key_channelcount = 'ChannelCount';

	private $hdhrlist = array();
	private $enginelist = array();
	private $storageURL = '';
	private $discoverLog = array();
	private $offLogged = array();

	public function __construct() {
		$this->discover_local_engine();
		$this->discover_udp(false);
		if ($this->enabled('DVRUI_mdnsDiscover')) {
			$this->discover_mdns(false);
		}
		if ($this->enabled('DVRUI_ipv6Discover')) {
			$this->discover_udp(true);
			if ($this->enabled('DVRUI_mdnsDiscover')) {
				$this->discover_mdns(true);
			}
		}
		if ($this->enabled('DVRUI_cloudDiscover')) {
			$this->discover_cloud();
		}
		error_log('Discovery complete: ' . count($this->hdhrlist) . ' tuner(s), '
			. count($this->enginelist) . ' engine(s)');
	}

	/* a discovery switch - from the Settings page, or its vars.php default */
	private function enabled($name) {
		$on = dvruiSetting($name);
		if (!$on && !isset($this->offLogged[$name])) {
			$this->offLogged[$name] = true;
			$labels = array('DVRUI_mdnsDiscover' => 'mDNS', 'DVRUI_ipv6Discover' => 'IPv6', 'DVRUI_cloudDiscover' => 'Cloud');
			$this->log((isset($labels[$name]) ? $labels[$name] : $name) . ' discovery is off (Settings) - skipped');
		}
		return $on;
	}

	/*
	 * The record engine in this container listens on the Port set in dvr.conf
	 */
	private function discover_local_engine() {
		$conf = new DVRUI_Engine_Config();
		$port = trim($conf->getServerPort());
		if (!preg_match('/^[0-9]+$/', $port)) {
			$port = DVRUI_Vars::DVR_defaultPort;
		}
		$info = getJsonFromUrl('http://127.0.0.1:' . $port . '/discover.json');
		if (!is_array($info)) {
			$this->log('Local record engine not responding on port ' . $port);
			return;
		}
		$this->add_device($info, 'localhost', null);
	}

	/*
	 * UDP discovery of tuners and record engines - IPv4 broadcast or IPv6 multicast
	 */
	private function discover_udp($ipv6) {
		$source = $ipv6 ? 'UDP IPv6' : 'UDP IPv4';
		$discover = new DVRUI_HDHRdiscover();
		$devices = $ipv6 ? $discover->discover_ipv6() : $discover->discover_ipv4();
		foreach ($discover->get_log() as $msg) {
			$this->log($msg);
		}

		foreach ($devices as $dev) {
			$ip = $this->scoped_ip($dev['IP'], $dev['Interface']);
			$devID = $this->val($dev, $this->hdhrkey_devID, null);
			$storageID = $this->val($dev, $this->hdhrkey_storageID, null);
			$name = ($devID !== null) ? $devID : (($storageID !== null) ? $storageID : 'engine');
			if ($this->already_found($devID, $storageID)) {
				$this->log('Found ' . $name . ' at ' . $ip . ' (' . $source . ') - already found');
				continue;
			}
			$this->log('Found ' . $name . ' at ' . $ip . ' (' . $source . ') types [' . implode(',', $dev['DeviceType']) . ']');

			if (!array_key_exists($this->hdhrkey_baseURL, $dev)) {
				// Legacy devices have no HTTP interface and can't be used by the DVR
				$this->log('Skipping ' . $name . ' at ' . $ip . ' - no BaseURL (legacy device)');
				continue;
			}
			$baseURL = $this->scoped_url($dev[$this->hdhrkey_baseURL], $dev['Interface']);
			$info = getJsonFromUrl(rtrim($baseURL, '/') . '/discover.json');
			if (!is_array($info)) {
				$this->log('Skipping ' . $name . ' at ' . $ip . ' - discover.json not available');
				continue;
			}
			// discover.json wins, the UDP reply fills in anything it is missing
			unset($dev['IP'], $dev['Interface'], $dev['DeviceType']);
			$info = array_merge($dev, $info);
			$info[$this->hdhrkey_localIP] = $ip;
			$this->add_device($this->scope_urls($info, $baseURL), $source, $devID);
		}
	}

	/*
	 * mDNS lookup of hdhomerun.local, then each address's discover.json
	 */
	private function discover_mdns($ipv6) {
		$source = $ipv6 ? 'mDNS IPv6' : 'mDNS IPv4';
		$mdns = new DVRUI_HDHRmdns();
		$addrs = $ipv6 ? $mdns->lookup_ipv6() : $mdns->lookup_ipv4();
		foreach ($mdns->get_log() as $msg) {
			$this->log($msg);
		}

		foreach ($addrs as $addr) {
			$ip = $this->scoped_ip($addr['IP'], $addr['Interface']);
			if ($this->address_known($addr['IP'])) {
				$this->log('Found ' . $ip . ' (' . $source . ') - already found');
				continue;
			}
			$host = $ipv6 ? '[' . str_replace('%', '%25', $ip) . ']' : $ip;
			$baseURL = 'http://' . $host;
			$info = getJsonFromUrl($baseURL . '/discover.json');
			if (!is_array($info)) {
				$this->log('Skipping ' . $ip . ' (' . $source . ') - discover.json not available');
				continue;
			}
			$this->log('Found ' . $this->val($info, $this->hdhrkey_devID, 'device') . ' at ' . $ip . ' (' . $source . ')');
			$info[$this->hdhrkey_localIP] = $ip;
			$this->add_device($this->scope_urls($info, $baseURL), $source, null);
		}
	}

	/*
	 * A device found by UDP is skipped if an earlier method already found it
	 */
	private function already_found($devID, $storageID) {
		if ($storageID !== null) {
			return $this->find_engine($storageID) >= 0;
		}
		return $devID !== null && $this->find_tuner($devID) >= 0;
	}

	/*
	 * mDNS only gives an address - skip it if a device already found is at that address
	 */
	private function address_known($ip) {
		$ip = strtolower($ip);
		foreach (array_merge($this->hdhrlist, $this->enginelist) as $dev) {
			$local = strtolower(preg_replace('/%.*$/', '', $this->val($dev, $this->hdhrkey_localIP, '')));
			$host = strtolower(trim((string)parse_url($this->val($dev, $this->hdhrkey_baseURL, ''), PHP_URL_HOST), '[]'));
			$host = preg_replace('/%.*$/', '', $host);
			if ($ip === $local || $ip === $host) {
				return true;
			}
		}
		return false;
	}

	/* link-local IPv6 addresses only work with their interface: fe80::1%eth0 */
	private function scoped_ip($ip, $ifname) {
		if ($ifname != '' && strpos($ip, '%') === false && DVRUI_HDHRdiscover::is_ipv6_linklocal($ip)) {
			return $ip . '%' . $ifname;
		}
		return $ip;
	}

	/* the same for a URL - http://[fe80::1%25eth0]:80/ */
	private function scoped_url($url, $ifname) {
		if ($ifname == '') {
			return $url;
		}
		return preg_replace_callback('#^(\w+://)\[([0-9a-fA-F:.]+)\]#', function ($m) use ($ifname) {
			if (!DVRUI_HDHRdiscover::is_ipv6_linklocal($m[2])) {
				return $m[0];
			}
			return $m[1] . '[' . $m[2] . '%25' . $ifname . ']';
		}, $url);
	}

	/*
	 * Add the interface to any link-local IPv6 URLs in discover.json (BaseURL,
	 * LineupURL, StorageURL) - the interface is taken from the URL used to reach it
	 */
	private function scope_urls($info, $reachedURL) {
		if (!preg_match('#\[[^\]]*%25([^\]]+)\]#', $reachedURL, $m)) {
			return $info;
		}
		$ifname = rawurldecode($m[1]);
		foreach (array($this->hdhrkey_baseURL, $this->hdhrkey_lineupURL, $this->hdhrkey_storageURL) as $key) {
			if (isset($info[$key]) && is_string($info[$key])) {
				$info[$key] = $this->scoped_url($info[$key], $ifname);
			}
		}
		return $info;
	}

	/*
	 * Cloud discovery - lists devices that share this network's public IP
	 */
	private function discover_cloud() {
		$hdhr_data = getJsonFromApi(DVRUI_Vars::DVRUI_apiurl . 'discover');
		if (!is_array($hdhr_data)) {
			$this->log('Cloud discovery returned no data');
			return;
		}
		foreach ($hdhr_data as $hdhr) {
			if (!is_array($hdhr) || !array_key_exists($this->hdhrkey_discoverURL, $hdhr)) {
				// Not HTTP capable - can't be used by the DVR
				continue;
			}
			$devID = $this->val($hdhr, $this->hdhrkey_devID, null);
			$storageID = $this->val($hdhr, $this->hdhrkey_storageID, null);
			if (($devID !== null && $this->find_tuner($devID) >= 0 && $storageID === null)
					|| ($storageID !== null && $this->find_engine($storageID) >= 0)) {
				continue; // already found locally
			}
			$info = getJsonFromUrl($hdhr[$this->hdhrkey_discoverURL]);
			if (!is_array($info)) {
				continue;
			}
			// After an engine restart the cloud can still list the old engine,
			// so make sure the StorageID still matches what the engine reports
			if ($storageID !== null
					&& strcasecmp($this->val($info, $this->hdhrkey_storageID, ''), $storageID) != 0) {
				$this->log('Skipping stale cloud engine entry ' . $storageID);
				continue;
			}
			if (!array_key_exists($this->hdhrkey_localIP, $info)) {
				$info[$this->hdhrkey_localIP] = $this->val($hdhr, $this->hdhrkey_localIP, '');
			}
			$this->add_device($info, 'cloud', $devID);
		}
	}

	/*
	 * Devices with a hard drive (e.g. SCRIBE/SERVIO) report as both a tuner and storage
	 */
	private function add_device($info, $source, $devID) {
		if ($devID === null) {
			$devID = $this->val($info, $this->hdhrkey_devID, null);
		}

		if (array_key_exists($this->hdhrkey_storageURL, $info)) {
			$this->add_engine($info, $source);
		}

		if ($devID !== null && array_key_exists($this->hdhrkey_lineupURL, $info)) {
			$this->add_tuner($info, $source, strtoupper($devID));
		}
	}

	private function add_tuner($info, $source, $devID) {
		if ($this->find_tuner($devID) >= 0) {
			return;
		}
		$lineup = getJsonFromUrl($info[$this->hdhrkey_lineupURL]);
		$this->log('Adding tuner ' . $devID . ' (' . $source . ')');
		$this->hdhrlist[] = array(
			$this->hdhrkey_devID => $devID,
			$this->hdhrkey_modelNum => $this->val($info, $this->hdhrkey_modelNum, 'unknown'),
			$this->hdhrlist_key_channelcount => is_array($lineup) ? count($lineup) : 0,
			$this->hdhrkey_baseURL => $this->val($info, $this->hdhrkey_baseURL, ''),
			$this->hdhrkey_lineupURL => $info[$this->hdhrkey_lineupURL],
			$this->hdhrkey_modelName => $this->val($info, $this->hdhrkey_modelName, 'HDHomeRun'),
			$this->hdhrkey_auth => $this->val($info, $this->hdhrkey_auth, '??'),
			$this->hdhrkey_fwVer => $this->val($info, $this->hdhrkey_fwVer, ''),
			$this->hdhrkey_tuners => $this->val($info, $this->hdhrkey_tuners, 'unknown'),
			$this->hdhrkey_legacy => $this->val($info, $this->hdhrkey_legacy, 'No'),
			$this->hdhrkey_fwName => $this->val($info, $this->hdhrkey_fwName, ''),
			$this->hdhrkey_localIP => $this->val($info, $this->hdhrkey_localIP, ''),
			$this->hdhrkey_source => $source);
	}

	private function add_engine($info, $source) {
		$storageID = $this->val($info, $this->hdhrkey_storageID, '');
		$key = ($storageID != '') ? $storageID : $this->val($info, $this->hdhrkey_baseURL, '');
		if ($this->find_engine($key) >= 0) {
			return;
		}
		$this->log('Adding engine ' . $key . ' (' . $source . ')');
		$this->enginelist[] = array(
			$this->hdhrkey_storageID => $key,
			$this->hdhrkey_baseURL => $this->val($info, $this->hdhrkey_baseURL, ''),
			$this->hdhrkey_devID => $this->val($info, $this->hdhrkey_devID, 'Record Engine'),
			$this->hdhrkey_tuners => $this->val($info, $this->hdhrkey_tuners, 'IGNORE'),
			$this->hdhrkey_auth => $this->val($info, $this->hdhrkey_auth, 'IGNORE'),
			$this->hdhrkey_modelNum => $this->val($info, $this->hdhrkey_modelNum, 'RecordEngine'),
			$this->hdhrkey_modelName => $this->val($info, $this->hdhrkey_modelName, 'HDHomeRun DVR'),
			$this->hdhrkey_ver => $this->val($info, $this->hdhrkey_ver,
				$this->val($info, $this->hdhrkey_fwVer, '')),
			$this->hdhrkey_storageURL => $info[$this->hdhrkey_storageURL],
			// freespace is not available if maxstreams = 0
			$this->hdhrkey_freespace => $this->val($info, $this->hdhrkey_freespace, '0'),
			$this->hdhrkey_localIP => $this->val($info, $this->hdhrkey_localIP, ''),
			$this->hdhrkey_source => $source);
	}

	private function find_tuner($devID) {
		for ($i = 0; $i < count($this->hdhrlist); $i++) {
			if (strcasecmp($this->hdhrlist[$i][$this->hdhrkey_devID], $devID) == 0) {
				return $i;
			}
		}
		return -1;
	}

	private function find_engine($storageID) {
		for ($i = 0; $i < count($this->enginelist); $i++) {
			if (strcasecmp($this->enginelist[$i][$this->hdhrkey_storageID], $storageID) == 0) {
				return $i;
			}
		}
		return -1;
	}

	private function val($arr, $key, $default) {
		return array_key_exists($key, $arr) ? $arr[$key] : $default;
	}

	private function log($msg) {
		$this->discoverLog[] = $msg;
		error_log($msg);
	}

	public function get_discover_log() {
		return $this->discoverLog;
	}

	public function device_count() {
		return count($this->hdhrlist);
	}

	public function get_device_info($pos) {
		$device = $this->hdhrlist[$pos];
		return ' DeviceID: ' . $device[$this->hdhrkey_devID]
		          . ' Model Number: ' . $device[$this->hdhrkey_modelNum]
		          . ' Channels: ' . $device[$this->hdhrlist_key_channelcount] . ' ';
	}

	public function set_my_engine($storageID){
		error_log('Setting storageURL to match StorageID: ' . $storageID);
		$this->storageURL = '';
		$pos = $this->find_engine($storageID);
		if ($pos >= 0) {
			$this->storageURL = $this->enginelist[$pos][$this->hdhrkey_storageURL];
			error_log('Setting StorageURL to ' . $this->storageURL);
		}
		return $this->storageURL;
	}

	public function get_storage_url(){
		return $this->storageURL;
	}

	public function get_device_id($pos) {
		return $this->hdhrlist[$pos][$this->hdhrkey_devID];
	}

	public function get_device_model($pos) {
		return $this->hdhrlist[$pos][$this->hdhrkey_modelNum];
	}

	public function get_device_modelname($pos) {
		return $this->hdhrlist[$pos][$this->hdhrkey_modelName];
	}

	public function get_device_channels($pos) {
		return $this->hdhrlist[$pos][$this->hdhrlist_key_channelcount];
	}

	public function get_device_lineup($pos) {
		return $this->hdhrlist[$pos][$this->hdhrkey_lineupURL];
	}

	public function get_device_baseurl($pos) {
		return $this->hdhrlist[$pos][$this->hdhrkey_baseURL];
	}

	public function get_device_firmware($pos) {
		return $this->hdhrlist[$pos][$this->hdhrkey_fwVer];
	}

	public function get_device_fwname($pos) {
		return $this->hdhrlist[$pos][$this->hdhrkey_fwName];
	}

	public function get_device_tuners($pos) {
		return $this->hdhrlist[$pos][$this->hdhrkey_tuners];
	}

	public function get_device_localip($pos) {
		return $this->hdhrlist[$pos][$this->hdhrkey_localIP];
	}

	public function get_device_source($pos) {
		return $this->hdhrlist[$pos][$this->hdhrkey_source];
	}

	public function get_device_auth($pos) {
		return $this->hdhrlist[$pos][$this->hdhrkey_auth];
	}

	public function engine_count() {
		return count($this->enginelist);
	}

	public function get_engine_baseurl($pos) {
		return $this->enginelist[$pos][$this->hdhrkey_baseURL];
	}

	public function get_engine_id($pos) {
		return $this->enginelist[$pos][$this->hdhrkey_devID];
	}

	public function get_engine_modelname($pos) {
		return $this->enginelist[$pos][$this->hdhrkey_modelName];
	}

	public function get_engine_firmware($pos) {
		return $this->enginelist[$pos][$this->hdhrkey_ver];
	}

	public function get_engine_freespace($pos) {
		return $this->convert_size($this->enginelist[$pos][$this->hdhrkey_freespace]);
	}

	public function get_engine_storageId($pos) {
		return $this->enginelist[$pos][$this->hdhrkey_storageID];
	}

	public function get_engine_storageUrl($pos) {
		return $this->enginelist[$pos][$this->hdhrkey_storageURL];
	}

	public function get_engine_localip($pos) {
		return $this->enginelist[$pos][$this->hdhrkey_localIP];
	}

	public function get_engine_source($pos) {
		return $this->enginelist[$pos][$this->hdhrkey_source];
	}

	public function get_device_image($pos) {
		return $this->get_image_url($this->hdhrlist[$pos][$this->hdhrkey_modelNum]);
	}

	public function get_engine_image($pos) {
		return $this->get_image_url($this->enginelist[$pos][$this->hdhrkey_modelNum]);
	}

	private function get_image_url($model) {
		switch ($model) {
			case 'RecordEngine':
				return './images/HDHR-DVR.png';
			case 'HDTC-2US':
				return './images/HDTC-2US.png';
			case 'HDHR3-CC':
				return './images/HDHR3-CC.png';
			case 'HDHR3-4DC':
				return './images/HDHR3-4DC.png';
			case 'HDHR3-EU':
			case 'HDHR3-US':
			case 'HDHR3-DT':
				return './images/HDHR3-US.png';
			case 'HDHR4-2US':
			case 'HDHR4-2DT':
				return './images/HDHR4-2US.png';
			case 'HHDD-2TB':
			case 'HDVR-2US-1TB':
			case 'HDVR-4US-1TB':
				return './images/HDVR-US.png';
			case 'HDHR5-DT':
			case 'HDHR5-2US':
			case 'HDHR5-4DC':
			case 'HDHR5-4DT':
			case 'HDHR5-4US':
				return './images/HDHR5-US.png';
			case 'HDHR5-6CC':
				return './images/HDHR5-6CC.png';
			case 'TECH4-2DT':
			case 'TECH4-2US':
				return './images/TECH4-2US.png';
			case 'TECH4-8US':
				return './images/TECH4-8US.png';
			case 'TECH5-36CC':
				return './images/TECH5-36CC.png';
			case 'TECH5-16DC':
			case 'TECH5-16DT':
				return './images/TECH5-16DT.png';
			default:
				return './images/HDHR5-US.png';
		}
	}

	private function convert_size($bytes)
	{
		$bytes = floatval($bytes);
		if ($bytes == 0) {
			return 'FULL';
		}
		$units = array('TB' => pow(1024, 4), 'GB' => pow(1024, 3), 'MB' => pow(1024, 2), 'KB' => 1024, 'B' => 1);
		foreach ($units as $unit => $value) {
			if ($bytes >= $value) {
				return strval(round($bytes / $value, 2)) . ' ' . $unit;
			}
		}
		return strval($bytes) . ' B';
	}
}
?>
