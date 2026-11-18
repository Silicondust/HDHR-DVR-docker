<?php
	require_once("vars.php");
	require_once("includes/dvrui_common.php");
	require_once("includes/dvrui_recordengine_config.php");
	require_once("includes/dvrui_hdhrdiscover.php");

/*
 * HDHomeRun device discovery
 *
 * Follows the SiliconDust discovery model (https://info.hdhomerun.com/info/discovery_api):
 *  1. Local UDP broadcast discovery of tuners and record engines (native PHP
 *     implementation in dvrui_hdhrdiscover.php), then query each device's discover.json
 *  2. The record engine running in this container is queried directly on localhost
 *  3. Optional cloud discovery (api.hdhomerun.com) as a fallback to find
 *     devices that local broadcast did not reach
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

	public function __construct() {
		$this->discover_local();
		$this->discover_local_engine();
		if (DVRUI_Vars::DVRUI_cloudDiscover) {
			$this->discover_cloud();
		}
		error_log('Discovery complete: ' . count($this->hdhrlist) . ' tuner(s), '
			. count($this->enginelist) . ' engine(s)');
	}

	/*
	 * Local UDP broadcast discovery of tuners and record engines
	 */
	private function discover_local() {
		$discover = new DVRUI_HDHRdiscover();
		$devices = $discover->discover();
		foreach ($discover->get_log() as $msg) {
			$this->log($msg);
		}

		foreach ($devices as $dev) {
			$ip = $dev['IP'];
			$devID = $this->val($dev, $this->hdhrkey_devID, null);
			$name = ($devID !== null) ? $devID : $this->val($dev, $this->hdhrkey_storageID, 'engine');
			$this->log('Found ' . $name . ' at ' . $ip . ' types [' . implode(',', $dev['DeviceType']) . ']');

			if (!array_key_exists($this->hdhrkey_baseURL, $dev)) {
				// Legacy devices have no HTTP interface and can't be used by the DVR
				$this->log('Skipping ' . $name . ' at ' . $ip . ' - no BaseURL (legacy device)');
				continue;
			}
			$info = getJsonFromUrl(rtrim($dev[$this->hdhrkey_baseURL], '/') . '/discover.json');
			if (!is_array($info)) {
				$this->log('Skipping ' . $name . ' at ' . $ip . ' - discover.json not available');
				continue;
			}
			// discover.json wins, the UDP reply fills in anything it is missing
			unset($dev['IP'], $dev['DeviceType']);
			$info = array_merge($dev, $info);
			$info[$this->hdhrkey_localIP] = $ip;
			$this->add_device($info, 'local', $devID);
		}
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
		$this->add_device($info, 'local', null);
	}

	/*
	 * Cloud discovery - lists devices that share this network's public IP
	 */
	private function discover_cloud() {
		$hdhr_data = getJsonFromUrl(DVRUI_Vars::DVRUI_apiurl . 'discover');
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
