<?php
/*
 * Native PHP implementation of the HDHomeRun UDP discovery protocol
 * (the same protocol libhdhomerun / hdhomerun_config use), so no external
 * tool is needed. Requires the PHP sockets extension.
 *
 * Packet format (big endian):
 *   uint16 type | uint16 payload length | payload (TLVs) | uint32 CRC32 (little endian)
 * TLV: uint8 tag | length (1 byte if < 128, else 2 byte varlen) | value
 */
class DVRUI_HDHRdiscover {
	const DISCOVER_UDP_PORT = 65001;
	const TYPE_DISCOVER_REQ = 0x0002;
	const TYPE_DISCOVER_RPY = 0x0003;

	const TAG_DEVICE_TYPE = 0x01;
	const TAG_DEVICE_ID   = 0x02;
	const TAG_TUNER_COUNT = 0x10;
	const TAG_LINEUP_URL  = 0x27;
	const TAG_STORAGE_URL = 0x28;
	const TAG_BASE_URL    = 0x2A;
	const TAG_DEVICE_AUTH = 0x2B;
	const TAG_STORAGE_ID  = 0x2C;
	const TAG_MULTI_TYPE  = 0x2D;

	const DEVICE_TYPE_TUNER   = 0x00000001;
	const DEVICE_TYPE_STORAGE = 0x00000005;

	// libhdhomerun sends the request twice, waiting 200ms after each
	const DISCOVER_ATTEMPTS = 2;
	const DISCOVER_WAIT_MS  = 200;

	private $log = array();

	public function get_log() {
		return $this->log;
	}

	/*
	 * Broadcast a discover request for tuners and record engines on every local
	 * IPv4 interface (plus localhost for an engine on this host) and collect replies.
	 * Returns an array of devices:
	 *   array('IP', 'DeviceType' => array(...), 'DeviceID', 'TunerCount', 'BaseURL',
	 *         'LineupURL', 'StorageURL', 'StorageID', 'DeviceAuth')
	 */
	public function discover() {
		if (!function_exists('socket_create')) {
			$this->log[] = 'PHP sockets extension not loaded - local discovery unavailable';
			return array();
		}

		$sock = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
		if ($sock === false) {
			$this->log[] = 'Unable to create UDP socket: ' . socket_strerror(socket_last_error());
			return array();
		}
		socket_set_option($sock, SOL_SOCKET, SO_BROADCAST, 1);
		if (!@socket_bind($sock, '0.0.0.0', 0)) {
			$this->log[] = 'Unable to bind UDP socket: ' . socket_strerror(socket_last_error($sock));
			socket_close($sock);
			return array();
		}

		$targets = $this->get_broadcast_targets();
		$this->log[] = 'Sending discover to ' . implode(', ', $targets);
		$pkt = $this->build_request(array(self::DEVICE_TYPE_TUNER, self::DEVICE_TYPE_STORAGE));

		$devices = array();
		for ($attempt = 0; $attempt < self::DISCOVER_ATTEMPTS; $attempt++) {
			foreach ($targets as $ip) {
				@socket_sendto($sock, $pkt, strlen($pkt), 0, $ip, self::DISCOVER_UDP_PORT);
			}

			$deadline = microtime(true) + (self::DISCOVER_WAIT_MS / 1000);
			while (($remaining = $deadline - microtime(true)) > 0) {
				$read = array($sock);
				$write = null;
				$except = null;
				$usec = (int)($remaining * 1000000);
				if (@socket_select($read, $write, $except, intdiv($usec, 1000000), $usec % 1000000) < 1) {
					break;
				}
				$buf = '';
				$from = '';
				$port = 0;
				if (@socket_recvfrom($sock, $buf, 2048, 0, $from, $port) === false) {
					continue;
				}
				$dev = $this->parse_reply($buf);
				if ($dev === null) {
					continue;
				}
				$dev['IP'] = $from;
				$this->merge_device($devices, $dev);
			}
		}
		socket_close($sock);

		$this->log[] = 'Discover found ' . count($devices) . ' device(s)';
		return array_values($devices);
	}

	/*
	 * Subnet broadcast address of each IPv4 interface, the limited broadcast
	 * address, and localhost (record engine running on this host)
	 */
	private function get_broadcast_targets() {
		$targets = array();
		if (function_exists('net_get_interfaces')) {
			$ifs = net_get_interfaces();
			if (is_array($ifs)) {
				foreach ($ifs as $name => $if) {
					if (empty($if['unicast'])) {
						continue;
					}
					foreach ($if['unicast'] as $addr) {
						if (empty($addr['address']) || empty($addr['netmask'])
								|| !filter_var($addr['address'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
								|| strpos($addr['address'], '127.') === 0) {
							continue;
						}
						$ip = ip2long($addr['address']);
						$mask = ip2long($addr['netmask']);
						$targets[] = long2ip(($ip & $mask) | (~$mask & 0xFFFFFFFF));
					}
				}
			}
		}
		$targets[] = '255.255.255.255';
		$targets[] = '127.0.0.1';
		return array_values(array_unique($targets));
	}

	/*
	 * Same layout as libhdhomerun: a single DEVICE_TYPE tag, or MULTI_TYPE for
	 * several types. DEVICE_ID is omitted to match any device.
	 */
	private function build_request($device_types) {
		if (count($device_types) == 1) {
			$payload = $this->tlv(self::TAG_DEVICE_TYPE, pack('N', $device_types[0]));
		} else {
			$payload = $this->tlv(self::TAG_MULTI_TYPE, pack('N*', ...$device_types));
		}
		$frame = pack('nn', self::TYPE_DISCOVER_REQ, strlen($payload)) . $payload;
		return $frame . pack('V', crc32($frame));
	}

	private function tlv($tag, $value) {
		$len = strlen($value);
		if ($len < 128) {
			$lenbytes = chr($len);
		} else {
			$lenbytes = chr(($len & 0x7F) | 0x80) . chr($len >> 7);
		}
		return chr($tag) . $lenbytes . $value;
	}

	private function parse_reply($buf) {
		$total = strlen($buf);
		if ($total < 8) {
			return null;
		}
		$hdr = unpack('ntype/nlen', substr($buf, 0, 4));
		if ($hdr['type'] != self::TYPE_DISCOVER_RPY || $hdr['len'] + 8 > $total) {
			return null;
		}
		$frame = substr($buf, 0, 4 + $hdr['len']);
		$crc = unpack('V', substr($buf, 4 + $hdr['len'], 4));
		if ($crc[1] != crc32($frame)) {
			return null;
		}

		$dev = array('DeviceType' => array());
		$pos = 4;
		$end = 4 + $hdr['len'];
		while ($pos + 2 <= $end) {
			$tag = ord($buf[$pos++]);
			$len = ord($buf[$pos++]);
			if ($len & 0x80) {
				if ($pos >= $end) {
					break;
				}
				$len = ($len & 0x7F) | (ord($buf[$pos++]) << 7);
			}
			if ($pos + $len > $end) {
				break;
			}
			$val = substr($buf, $pos, $len);
			$pos += $len;

			switch ($tag) {
				case self::TAG_DEVICE_TYPE:
					if ($len == 4) {
						$t = unpack('N', $val);
						$dev['DeviceType'][] = $t[1];
					}
					break;
				case self::TAG_MULTI_TYPE:
					for ($i = 0; $i + 4 <= $len; $i += 4) {
						$t = unpack('N', substr($val, $i, 4));
						$dev['DeviceType'][] = $t[1];
					}
					break;
				case self::TAG_DEVICE_ID:
					if ($len == 4) {
						$id = unpack('N', $val);
						if ($id[1] != 0) {
							$dev['DeviceID'] = sprintf('%08X', $id[1]);
						}
					}
					break;
				case self::TAG_TUNER_COUNT:
					if ($len == 1) {
						$dev['TunerCount'] = ord($val);
					}
					break;
				case self::TAG_BASE_URL:
					$dev['BaseURL'] = rtrim($val, "\0");
					break;
				case self::TAG_LINEUP_URL:
					$dev['LineupURL'] = rtrim($val, "\0");
					break;
				case self::TAG_STORAGE_URL:
					$dev['StorageURL'] = rtrim($val, "\0");
					break;
				case self::TAG_STORAGE_ID:
					$dev['StorageID'] = rtrim($val, "\0");
					break;
				case self::TAG_DEVICE_AUTH:
					$dev['DeviceAuth'] = rtrim($val, "\0");
					break;
			}
		}
		// libhdhomerun only keeps replies that report a device type
		return count($dev['DeviceType']) > 0 ? $dev : null;
	}

	/*
	 * The same device replies once per request type and per interface - merge them
	 */
	private function merge_device(&$devices, $dev) {
		if (isset($dev['DeviceID'])) {
			$key = 'id:' . $dev['DeviceID'];
		} elseif (isset($dev['StorageID'])) {
			$key = 'storage:' . $dev['StorageID'];
		} else {
			$key = 'ip:' . $dev['IP'] . (isset($dev['BaseURL']) ? $dev['BaseURL'] : '');
		}
		if (!isset($devices[$key])) {
			$devices[$key] = $dev;
			return;
		}
		$types = array_unique(array_merge($devices[$key]['DeviceType'], $dev['DeviceType']));
		$devices[$key] = array_merge($dev, $devices[$key]);
		$devices[$key]['DeviceType'] = array_values($types);
	}
}
?>
