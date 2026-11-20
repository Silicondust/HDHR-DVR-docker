<?php
/*
 * ui/includes/dvrui_hdhrdiscover.php
 *
 * Copyright (c) 2020 Rik Dunphy
 * Copyright (c) 2026 Silicondust USA Inc.
 * Released under the MIT License - see LICENSE
 */
/*
 * Native PHP implementation of the HDHomeRun UDP discovery protocol
 * (the same protocol libhdhomerun / hdhomerun_config use), so no external
 * tool is needed. Requires the PHP sockets extension.
 *
 * IPv4: the request is broadcast on every local subnet (and to localhost).
 * IPv6: the request is multicast, as libhdhomerun does, to
 *   ff02::176 (link-local) on each interface with a link-local address and
 *   ff05::176 (site-local) on each interface with a global or unique local address.
 *
 * Packet format (big endian):
 *   uint16 type | uint16 payload length | payload (TLVs) | uint32 CRC32 (little endian)
 * TLV: uint8 tag | length (1 byte if < 128, else 2 byte varlen) | value
 */
class DVRUI_HDHRdiscover {
	const DISCOVER_UDP_PORT = 65001;
	const TYPE_DISCOVER_REQ = 0x0002;
	const TYPE_DISCOVER_RPY = 0x0003;

	const IPV6_LINKLOCAL_MULTICAST = 'ff02::176';
	const IPV6_SITELOCAL_MULTICAST = 'ff05::176';

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
	 *   array('IP', 'Interface', 'DeviceType' => array(...), 'DeviceID', 'TunerCount',
	 *         'BaseURL', 'LineupURL', 'StorageURL', 'StorageID', 'DeviceAuth')
	 */
	public function discover_ipv4() {
		if (!$this->sockets_available()) {
			return array();
		}
		$sock = $this->create_socket(AF_INET, '0.0.0.0');
		if ($sock === null) {
			return array();
		}
		socket_set_option($sock, SOL_SOCKET, SO_BROADCAST, 1);

		$targets = array();
		foreach ($this->get_broadcast_targets() as $ip) {
			$targets[] = array($sock, $ip);
		}
		$this->log[] = 'IPv4: sending discover to ' . implode(', ', array_column($targets, 1));
		$devices = $this->run(array(spl_object_id($sock) => array($sock, '')), $targets);
		socket_close($sock);

		$this->log[] = 'IPv4: discover found ' . count($devices) . ' device(s)';
		return $devices;
	}

	/*
	 * Multicast a discover request on every IPv6 interface. Each interface gets its
	 * own socket so a reply is known to have come in on that interface - a link-local
	 * address is only usable together with its interface (the scope).
	 */
	public function discover_ipv6() {
		if (!$this->sockets_available()) {
			return array();
		}
		if (!defined('AF_INET6') || !defined('IPV6_MULTICAST_IF')) {
			$this->log[] = 'IPv6: not supported by PHP - skipped';
			return array();
		}
		$ifs = self::ipv6_interfaces();
		if (count($ifs) == 0) {
			$this->log[] = 'IPv6: no IPv6 interfaces - skipped';
			return array();
		}

		$socks = array();
		$targets = array();
		$desc = array();
		foreach ($ifs as $name => $kinds) {
			$sock = $this->create_socket(AF_INET6, '::');
			if ($sock === null) {
				continue;
			}
			if (!@socket_set_option($sock, IPPROTO_IPV6, IPV6_MULTICAST_IF, $name)) {
				$this->log[] = 'IPv6: unable to use ' . $name . ' for multicast';
				socket_close($sock);
				continue;
			}
			$socks[spl_object_id($sock)] = array($sock, $name);
			if ($kinds['linklocal']) {
				$targets[] = array($sock, self::IPV6_LINKLOCAL_MULTICAST);
				$desc[] = self::IPV6_LINKLOCAL_MULTICAST . '%' . $name;
			}
			if ($kinds['general']) {
				$targets[] = array($sock, self::IPV6_SITELOCAL_MULTICAST);
				$desc[] = self::IPV6_SITELOCAL_MULTICAST . '%' . $name;
			}
		}
		if (count($targets) == 0) {
			$this->log[] = 'IPv6: no usable interfaces - skipped';
			return array();
		}
		$this->log[] = 'IPv6: sending discover to ' . implode(', ', $desc);
		$devices = $this->run($socks, $targets);
		foreach ($socks as $s) {
			socket_close($s[0]);
		}

		$this->log[] = 'IPv6: discover found ' . count($devices) . ' device(s)';
		return $devices;
	}

	/*
	 * Interfaces (not loopback) with IPv6 addresses:
	 *   name => array('linklocal' => bool, 'general' => bool)
	 * general = a global or unique local (non link-local) address
	 */
	public static function ipv6_interfaces() {
		$result = array();
		if (!function_exists('net_get_interfaces')) {
			return $result;
		}
		$ifs = net_get_interfaces();
		if (!is_array($ifs)) {
			return $result;
		}
		foreach ($ifs as $name => $if) {
			if ((isset($if['up']) && !$if['up']) || empty($if['unicast'])) {
				continue;
			}
			foreach ($if['unicast'] as $addr) {
				if (empty($addr['address'])
						|| !filter_var($addr['address'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)
						|| $addr['address'] == '::1') {
					continue;
				}
				if (!isset($result[$name])) {
					$result[$name] = array('linklocal' => false, 'general' => false);
				}
				if (self::is_ipv6_linklocal($addr['address'])) {
					$result[$name]['linklocal'] = true;
				} else {
					$result[$name]['general'] = true;
				}
			}
		}
		return $result;
	}

	/* fe80::/10 */
	public static function is_ipv6_linklocal($ip) {
		$bin = @inet_pton($ip);
		return $bin !== false && strlen($bin) == 16
			&& ord($bin[0]) == 0xFE && (ord($bin[1]) & 0xC0) == 0x80;
	}

	private function sockets_available() {
		if (!function_exists('socket_create')) {
			$this->log[] = 'PHP sockets extension not loaded - local discovery unavailable';
			return false;
		}
		return true;
	}

	private function create_socket($family, $bindaddr) {
		$sock = @socket_create($family, SOCK_DGRAM, SOL_UDP);
		if ($sock === false) {
			$this->log[] = 'Unable to create UDP socket: ' . socket_strerror(socket_last_error());
			return null;
		}
		if (!@socket_bind($sock, $bindaddr, 0)) {
			$this->log[] = 'Unable to bind UDP socket: ' . socket_strerror(socket_last_error($sock));
			socket_close($sock);
			return null;
		}
		return $sock;
	}

	/*
	 * Send the request to each target (array(socket, address)) and collect the
	 * replies on all the sockets (spl_object_id(socket) => array(socket, interface name))
	 */
	private function run($socks, $targets) {
		$pkt = $this->build_request(array(self::DEVICE_TYPE_TUNER, self::DEVICE_TYPE_STORAGE));
		$devices = array();
		for ($attempt = 0; $attempt < self::DISCOVER_ATTEMPTS; $attempt++) {
			foreach ($targets as $t) {
				@socket_sendto($t[0], $pkt, strlen($pkt), 0, $t[1], self::DISCOVER_UDP_PORT);
			}

			$deadline = microtime(true) + (self::DISCOVER_WAIT_MS / 1000);
			while (($remaining = $deadline - microtime(true)) > 0) {
				$read = array_column($socks, 0);
				$write = null;
				$except = null;
				$usec = (int)($remaining * 1000000);
				if (@socket_select($read, $write, $except, intdiv($usec, 1000000), $usec % 1000000) < 1) {
					break;
				}
				foreach ($read as $sock) {
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
					$dev['Interface'] = $socks[spl_object_id($sock)][1];
					$this->merge_device($devices, $dev);
				}
			}
		}
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
						$bcast = $this->broadcast_address($addr['address'], $addr['netmask']);
						if ($bcast !== null) {
							$targets[] = $bcast;
						}
					}
				}
			}
		}
		$targets[] = '255.255.255.255';
		$targets[] = '127.0.0.1';
		return array_values(array_unique($targets));
	}

	/*
	 * Subnet broadcast address, worked out a byte at a time so it is also correct
	 * on 32-bit PHP (e.g. arm/v7), where 0xFFFFFFFF does not fit in an int
	 */
	private function broadcast_address($address, $netmask) {
		$ip = explode('.', $address);
		$mask = explode('.', $netmask);
		if (count($ip) != 4 || count($mask) != 4) {
			return null;
		}
		$bcast = array();
		for ($i = 0; $i < 4; $i++) {
			$bcast[] = ((int)$ip[$i] & 0xFF) | (~(int)$mask[$i] & 0xFF);
		}
		return implode('.', $bcast);
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
