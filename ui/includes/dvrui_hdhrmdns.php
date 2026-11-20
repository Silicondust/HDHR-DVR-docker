<?php
/*
 * ui/includes/dvrui_hdhrmdns.php
 *
 * Copyright (c) 2020 Rik Dunphy
 * Copyright (c) 2026 Silicondust USA Inc.
 * Released under the MIT License - see LICENSE
 */
	require_once("includes/dvrui_hdhrdiscover.php");

/*
 * mDNS (multicast DNS, RFC 6762) lookup of HDHomeRun devices.
 *
 * Every HDHomeRun device answers for the shared name hdhomerun.local (as well as
 * its own hdhr-<deviceid>.local), so one query for hdhomerun.local collects the
 * address of each device on the network. The query is a one-shot ("legacy
 * unicast") query sent from an ephemeral port, so responders reply directly to
 * this socket and nothing has to listen on port 5353.
 *
 *   IPv4: A query to 224.0.0.251:5353 on each IPv4 interface
 *   IPv6: AAAA query to ff02::fb:5353 on each IPv6 interface (one socket each,
 *         so a link-local reply is known to belong to that interface)
 */
class DVRUI_HDHRmdns {
	const MDNS_PORT = 5353;
	const MDNS_IPV4_GROUP = '224.0.0.251';
	const MDNS_IPV6_GROUP = 'ff02::fb';
	const MDNS_NAME = 'hdhomerun.local';

	const TYPE_A = 1;
	const TYPE_AAAA = 28;
	const CLASS_IN = 1;

	// HDHomeRun devices answer within 100ms, even through an mDNS reflector
	const MDNS_ATTEMPTS = 2;
	const MDNS_WAIT_MS = 150;

	private $log = array();

	public function get_log() {
		return $this->log;
	}

	/*
	 * Addresses that answered for hdhomerun.local over IPv4:
	 *   array(array('IP' => '10.0.0.5', 'Interface' => 'eth0'), ...)
	 */
	public function lookup_ipv4() {
		if (!function_exists('socket_create')) {
			$this->log[] = 'mDNS IPv4: PHP sockets extension not loaded - skipped';
			return array();
		}
		$ifs = $this->ipv4_interfaces();
		if (count($ifs) == 0) {
			$this->log[] = 'mDNS IPv4: no IPv4 interfaces - skipped';
			return array();
		}
		$sock = $this->create_socket(AF_INET, '0.0.0.0');
		if ($sock === null) {
			return array();
		}
		@socket_set_option($sock, IPPROTO_IP, IP_MULTICAST_TTL, 255);
		$targets = array();
		foreach ($ifs as $name) {
			$targets[] = array($sock, self::MDNS_IPV4_GROUP, $name, IPPROTO_IP, IP_MULTICAST_IF);
		}
		$this->log[] = 'mDNS IPv4: query ' . self::MDNS_NAME . ' on ' . implode(', ', $ifs);
		$found = $this->run(array(spl_object_id($sock) => array($sock, '')), $targets, self::TYPE_A);
		socket_close($sock);
		$this->log[] = 'mDNS IPv4: ' . count($found) . ' address(es) found';
		return $found;
	}

	/*
	 * Addresses that answered for hdhomerun.local over IPv6:
	 *   array(array('IP' => 'fe80::218:ddff:fe26:afe', 'Interface' => 'eth0'), ...)
	 */
	public function lookup_ipv6() {
		if (!function_exists('socket_create')) {
			$this->log[] = 'mDNS IPv6: PHP sockets extension not loaded - skipped';
			return array();
		}
		if (!defined('AF_INET6') || !defined('IPV6_MULTICAST_IF')) {
			$this->log[] = 'mDNS IPv6: not supported by PHP - skipped';
			return array();
		}
		$ifs = array_keys(DVRUI_HDHRdiscover::ipv6_interfaces());
		if (count($ifs) == 0) {
			$this->log[] = 'mDNS IPv6: no IPv6 interfaces - skipped';
			return array();
		}
		$socks = array();
		$targets = array();
		foreach ($ifs as $name) {
			$sock = $this->create_socket(AF_INET6, '::');
			if ($sock === null) {
				continue;
			}
			if (!@socket_set_option($sock, IPPROTO_IPV6, IPV6_MULTICAST_IF, $name)) {
				$this->log[] = 'mDNS IPv6: unable to use ' . $name . ' for multicast';
				socket_close($sock);
				continue;
			}
			@socket_set_option($sock, IPPROTO_IPV6, IPV6_MULTICAST_HOPS, 255);
			$socks[spl_object_id($sock)] = array($sock, $name);
			$targets[] = array($sock, self::MDNS_IPV6_GROUP, null, null, null);
		}
		if (count($targets) == 0) {
			$this->log[] = 'mDNS IPv6: no usable interfaces - skipped';
			return array();
		}
		$this->log[] = 'mDNS IPv6: query ' . self::MDNS_NAME . ' on ' . implode(', ', array_column($socks, 1));
		$found = $this->run($socks, $targets, self::TYPE_AAAA);
		foreach ($socks as $s) {
			socket_close($s[0]);
		}
		$this->log[] = 'mDNS IPv6: ' . count($found) . ' address(es) found';
		return $found;
	}

	/* Interfaces (not loopback) with an IPv4 address */
	private function ipv4_interfaces() {
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
				if (!empty($addr['address'])
						&& filter_var($addr['address'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
						&& strpos($addr['address'], '127.') !== 0) {
					$result[] = $name;
					break;
				}
			}
		}
		return $result;
	}

	private function create_socket($family, $bindaddr) {
		$sock = @socket_create($family, SOCK_DGRAM, SOL_UDP);
		if ($sock === false) {
			$this->log[] = 'mDNS: unable to create UDP socket: ' . socket_strerror(socket_last_error());
			return null;
		}
		if (!@socket_bind($sock, $bindaddr, 0)) {
			$this->log[] = 'mDNS: unable to bind UDP socket: ' . socket_strerror(socket_last_error($sock));
			socket_close($sock);
			return null;
		}
		return $sock;
	}

	/*
	 * targets: array(socket, group, interface to select first or null, option level, option)
	 * socks: spl_object_id(socket) => array(socket, interface name)
	 */
	private function run($socks, $targets, $qtype) {
		$id = random_int(1, 0xFFFF);
		$pkt = $this->build_query($id, self::MDNS_NAME, $qtype);
		$found = array();
		for ($attempt = 0; $attempt < self::MDNS_ATTEMPTS; $attempt++) {
			foreach ($targets as $t) {
				if ($t[2] !== null && !@socket_set_option($t[0], $t[3], $t[4], $t[2])) {
					continue;
				}
				@socket_sendto($t[0], $pkt, strlen($pkt), 0, $t[1], self::MDNS_PORT);
			}

			$deadline = microtime(true) + (self::MDNS_WAIT_MS / 1000);
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
					if (@socket_recvfrom($sock, $buf, 9000, 0, $from, $port) === false) {
						continue;
					}
					foreach ($this->parse_response($buf, $id, self::MDNS_NAME, $qtype) as $ip) {
						if (!isset($found[$ip])) {
							$found[$ip] = array('IP' => $ip, 'Interface' => $socks[spl_object_id($sock)][1]);
						}
					}
				}
			}
		}
		return array_values($found);
	}

	private function build_query($id, $name, $qtype) {
		// id, flags (standard query), 1 question, no answer/authority/additional records
		$pkt = pack('nnnnnn', $id, 0, 1, 0, 0, 0);
		foreach (explode('.', $name) as $label) {
			$pkt .= chr(strlen($label)) . $label;
		}
		return $pkt . "\0" . pack('nn', $qtype, self::CLASS_IN);
	}

	/*
	 * Addresses of the given type for the given name, from any section of a response.
	 * Other names are ignored - some responders answer every query with their own name.
	 */
	private function parse_response($buf, $id, $name, $qtype) {
		$ips = array();
		$total = strlen($buf);
		if ($total < 12) {
			return $ips;
		}
		$hdr = unpack('nid/nflags/nqd/nan/nns/nar', substr($buf, 0, 12));
		// must be a response; legacy unicast replies echo the query id (multicast replies use 0)
		if (!($hdr['flags'] & 0x8000) || ($hdr['id'] != 0 && $hdr['id'] != $id)) {
			return $ips;
		}
		$pos = 12;
		for ($i = 0; $i < $hdr['qd']; $i++) {
			if ($this->read_name($buf, $pos) === null || $pos + 4 > $total) {
				return $ips;
			}
			$pos += 4;
		}
		$records = $hdr['an'] + $hdr['ns'] + $hdr['ar'];
		for ($i = 0; $i < $records; $i++) {
			$rname = $this->read_name($buf, $pos);
			if ($rname === null || $pos + 10 > $total) {
				break;
			}
			$rr = unpack('ntype/nclass/Nttl/nlen', substr($buf, $pos, 10));
			$pos += 10;
			if ($pos + $rr['len'] > $total) {
				break;
			}
			$rdata = substr($buf, $pos, $rr['len']);
			$pos += $rr['len'];
			// the top bit of the class is the mDNS cache-flush bit
			if (strcasecmp($rname, $name) != 0 || ($rr['class'] & 0x7FFF) != self::CLASS_IN
					|| $rr['type'] != $qtype || $rr['ttl'] == 0) {
				continue;
			}
			if (($qtype == self::TYPE_A && $rr['len'] == 4) || ($qtype == self::TYPE_AAAA && $rr['len'] == 16)) {
				$ip = inet_ntop($rdata);
				if ($ip !== false) {
					$ips[] = $ip;
				}
			}
		}
		return $ips;
	}

	/* DNS name at $pos (with compression pointers); moves $pos past it. null if malformed */
	private function read_name($buf, &$pos) {
		$labels = array();
		$total = strlen($buf);
		$p = $pos;
		$jumped = false;
		for ($guard = 0; $guard < 128; $guard++) {
			if ($p >= $total) {
				return null;
			}
			$len = ord($buf[$p]);
			if ($len == 0) {
				if (!$jumped) {
					$pos = $p + 1;
				}
				return implode('.', $labels);
			}
			if (($len & 0xC0) == 0xC0) {
				if ($p + 1 >= $total) {
					return null;
				}
				if (!$jumped) {
					$pos = $p + 2;
				}
				$jumped = true;
				$p = (($len & 0x3F) << 8) | ord($buf[$p + 1]);
				continue;
			}
			if ($p + 1 + $len > $total) {
				return null;
			}
			$labels[] = substr($buf, $p + 1, $len);
			$p += 1 + $len;
		}
		return null;
	}
}
?>
