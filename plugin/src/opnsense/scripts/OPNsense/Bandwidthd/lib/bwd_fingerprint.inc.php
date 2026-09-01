<?php
/*
 * bwd_fingerprint.inc.php — dedicated device-fingerprinting ENGINE (#11).
 *
 * All the active/passive fingerprinting *machinery* lives here, separate from
 * the rest of the data layer, so it can be maintained and extended on its own.
 * The *signatures* it matches against live in data/fingerprints.php (one file,
 * sectioned per technique) so the rule data can be updated independently of this
 * code — like the OUI table.
 *
 * Techniques (each degrades gracefully when its tool/data is unavailable):
 *   passive : OUI vendor + hostname (in bwd_data.inc.php), DHCP option 55/60
 *             (harvested from live DHCP via tcpdump — cron, needs lease traffic)
 *   active  : HTTP server/title/identity-endpoints, TLS certificate CN/SAN,
 *             TCP service banners, mDNS (5353) service enumeration, SSDP (1900)
 *             M-SEARCH, and nmap -sV (only if the nmap package is installed)
 *
 * Active probing contacts the device, a change from bandwidthd's passive
 * monitoring, so it is OFF by default (probe_enable) and gated everywhere.
 * Results cache to rollups/fingerprints.json (MAC-keyed) and feed bwd_classify()
 * as a high-weight signal. No output here; pure functions.
 *
 * Licensed under the Apache License, Version 2.0.
 */

if (!defined('BWD_BASE')) { define('BWD_BASE', '/usr/local/bandwidthd'); }
define('BWD_FP_CACHE', BWD_BASE . '/rollups/fingerprints.json');
define('BWD_FP_HTTP_PORTS', '80,8080,8081,8443,443');
define('BWD_FP_TIMEOUT', 4);
define('BWD_FP_BODY_CAP', 65536);

/* Active probing opt-in (default off). */
function bwd_fp_enabled() {
	return bwd_cfg('probe_enable') === 'on';
}

/* ---- signature DB (data/fingerprints.php; sectioned http/mdns/ssdp/dhcp/banner/tls) ---- */
function bwd_fp_signatures() {
	static $s = null;
	if ($s === null) {
		$f = __DIR__ . '/data/fingerprints.php';
		$s = is_file($f) ? (array) include $f : array();
	}
	return $s;
}
function bwd_fp_section($k) { $s = bwd_fp_signatures(); return (array) ($s[$k] ?? array()); }

/* ===================== PROBE TARGET SCOPE (#32) =====================
 * Active probing shells out to curl/openssl/nmap/banner-grabs against $ip. The IP
 * must be a LAN device bandwidthd actually monitors — never loopback, link-local
 * (cloud metadata 169.254.169.254), the WAN, or an arbitrary internet host — or
 * the probe endpoint/CLI becomes a blind SSRF + port-scan pivot from the firewall.
 * Scope is exactly what bandwidthd is configured to monitor: the selected
 * interfaces' own subnets plus any operator-declared extra subnets. It is
 * deliberately NOT "all of RFC1918" — that would let anyone holding the probe
 * privilege aim nmap -sV at any private host the firewall can route to, which is
 * a different power from "identify the devices on my monitored LAN". With no
 * subnet resolvable the list is empty and every target is refused: for a scanning
 * primitive, failing closed is the right direction. */
function bwd_fp_allowed_cidrs() {
	$cidrs = array();
	// operator's extra stats subnets (plain config string, parseable off-box)
	$extra = (string) bwd_cfg('subnets_extra', '');
	foreach (preg_split('/[;,\s]+/', $extra) as $sn) {
		$sn = trim($sn);
		if ($sn !== '' && strpos($sn, '/') !== false) { $cidrs[] = $sn; }
	}
	// monitored interface subnets (box only; resolved by the platform layer)
	if (function_exists('bwd_platform_subnets')) {
		foreach (bwd_platform_subnets() as $sn) { $cidrs[] = $sn; }
	}
	return $cidrs;
}
/* Private space. The configured scope is intersected with this, so a careless or
 * hostile subnets_extra entry — 0.0.0.0/0 being the obvious one — cannot aim the
 * scanner at loopback, at link-local (169.254.169.254 cloud metadata), at the WAN
 * or at an arbitrary internet host. Narrowing the scope must not accidentally
 * remove the floor the old blanket-RFC1918 gate happened to provide. */
function bwd_fp_private_cidrs() {
	return array('10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16');
}

/* True iff $ip is a valid IPv4 inside the allowed probe scope AND inside private
 * space. Both must hold. */
function bwd_fp_target_allowed($ip) {
	if (!function_exists('is_ipaddrv4') || !is_ipaddrv4($ip)) { return false; }
	$private = false;
	foreach (bwd_fp_private_cidrs() as $cidr) {
		if (bwd_ip_in_cidr($ip, $cidr)) { $private = true; break; }
	}
	if (!$private) { return false; }
	foreach (bwd_fp_allowed_cidrs() as $cidr) {
		if (bwd_ip_in_cidr($ip, $cidr)) { return true; }
	}
	return false;
}

/* ============================ COLLECTORS ============================ */

/* One HTTP(S) GET (curl CLI; -k tolerates IoT self-signed certs). */
function bwd_fp_http_get($url, $timeout = 0) {
	$t = $timeout > 0 ? $timeout : BWD_FP_TIMEOUT;
	/* No -L: a device being fingerprinted has no legitimate reason to redirect the
	 * prober off-host, and following its 3xx Location would be an SSRF vector (it
	 * could point at 127.0.0.1, the GUI, or metadata). The Server header + <title>
	 * we fingerprint on are in the initial response anyway. (#32) */
	/* Bound the read at the pipe. shell_exec() buffers whatever curl writes, so a
	 * device that accepts the connection and streams an unbounded chunked body for
	 * the whole --max-time window would land hundreds of MB in PHP's heap before
	 * the BWD_FP_BODY_CAP substr below ever runs — enough to hit memory_limit and
	 * kill the probe. head -c is what enforces it: curl dies of SIGPIPE once the
	 * cap is reached, and everything we fingerprint on (status, Server header,
	 * <title>) is in the first bytes anyway.
	 *
	 * Deliberately NOT --max-filesize: that makes curl abort the transfer outright
	 * when the declared length is over the limit, so a large page yields nothing at
	 * all — no status line, no Server header — and the device goes unidentified. */
	$readCap = BWD_FP_BODY_CAP + 8192;   /* body cap + room for headers */
	$cmd = sprintf('curl -s -k -i --connect-timeout %d --max-time %d -A %s %s 2>/dev/null | head -c %d',
		$t, $t, escapeshellarg('bandwidthd-fp/1.0'), escapeshellarg($url), $readCap);
	$raw = @shell_exec($cmd);
	$res = array('status' => 0, 'server' => '', 'title' => '', 'www_authenticate' => '', 'body' => '');
	if (!$raw) { return $res; }
	$parts = preg_split("/\r?\n\r?\n/", $raw, -1, PREG_SPLIT_NO_EMPTY);
	$head = ''; $body = $raw;
	for ($i = count($parts) - 1; $i >= 0; $i--) {
		if (preg_match('#^HTTP/#', $parts[$i])) { $head = $parts[$i]; $body = implode("\n\n", array_slice($parts, $i + 1)); break; }
	}
	if (preg_match('#^HTTP/\S+\s+(\d{3})#', $head, $m)) { $res['status'] = (int) $m[1]; }
	if (preg_match('/^server:\s*(.+)$/im', $head, $m)) { $res['server'] = trim($m[1]); }
	if (preg_match('/^www-authenticate:\s*(.+)$/im', $head, $m)) { $res['www_authenticate'] = trim($m[1]); }
	$res['body'] = substr($body, 0, BWD_FP_BODY_CAP);
	if (preg_match('#<title[^>]*>(.*?)</title>#is', $res['body'], $m)) { $res['title'] = trim(preg_replace('/\s+/', ' ', $m[1])); }
	return $res;
}

/* nmap -sV (only if the nmap package is present). Returns service lines. */
function bwd_fp_nmap($ip) {
	$bin = is_file('/usr/local/bin/nmap') ? '/usr/local/bin/nmap' : (@shell_exec('command -v nmap 2>/dev/null') ? 'nmap' : '');
	if ($bin === '') { return array(); }
	$out = @shell_exec(sprintf('%s -Pn -sV --version-intensity 3 --host-timeout 25s -T4 %s 2>/dev/null', $bin, escapeshellarg($ip)));
	$svc = array();
	foreach (preg_split('/\r?\n/', (string) $out) as $line) {
		if (preg_match('#^(\d+/\w+)\s+(open|filtered)\s+(.+)$#', trim($line), $m)) { $svc[] = trim($m[1] . ' ' . $m[3]); }
	}
	return $svc;
}

/* TLS certificate subject/issuer/SAN via openssl s_client. */
function bwd_fp_tls($ip, $ports = array(443, 8443), $timeout = 0) {
	$t = $timeout > 0 ? $timeout : BWD_FP_TIMEOUT;
	$out = array();
	foreach ($ports as $port) {
		$cmd = sprintf('echo | openssl s_client -connect %s:%d -servername %s 2>/dev/null | openssl x509 -noout -subject -issuer -ext subjectAltName 2>/dev/null',
			escapeshellarg($ip), $port, escapeshellarg($ip));
		$r = @shell_exec('timeout ' . $t . ' sh -c ' . escapeshellarg($cmd));
		if ($r && stripos($r, 'subject') !== false) { $out[] = trim(preg_replace('/\s+/', ' ', $r)); }
	}
	return $out;
}

/* Read a TCP service banner (ssh/telnet/rtsp/smtp/etc.) on a few ports. */
function bwd_fp_banner($ip, $ports = array(22, 23, 21, 554, 9100)) {
	$out = array();
	foreach ($ports as $port) {
		$fp = @stream_socket_client("tcp://$ip:$port", $errno, $errstr, BWD_FP_TIMEOUT);
		if (!$fp) { continue; }
		stream_set_timeout($fp, BWD_FP_TIMEOUT);
		$banner = @fread($fp, 256);
		if (trim((string) $banner) === '' && in_array($port, array(554, 9100))) {
			// nudge protocols that speak first only on request
			@fwrite($fp, $port === 554 ? "OPTIONS * RTSP/1.0\r\nCSeq: 1\r\n\r\n" : "\r\n");
			$banner = @fread($fp, 256);
		}
		fclose($fp);
		$banner = trim(preg_replace('/[^\x20-\x7e]+/', ' ', (string) $banner));
		if ($banner !== '') { $out[] = "$port: $banner"; }
	}
	return $out;
}

/* The firewall's own source IP on the interface that routes to $target — so
 * multicast queries egress the LAN segment the device is on (not the WAN). */
function bwd_fp_src_ip($target) {
	$if = trim((string) @shell_exec('route -n get ' . escapeshellarg($target) . " 2>/dev/null | awk '/interface:/{print \$2}'"));
	if ($if === '') { return ''; }
	$ip = trim((string) @shell_exec('ifconfig ' . escapeshellarg($if) . " inet 2>/dev/null | awk '/inet /{print \$2; exit}'"));
	return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? $ip : '';
}

/* mDNS (5353) service enumeration: multicast a PTR query for the service-list
 * meta-query, collect responses from the target IP, and pull advertised service
 * types (e.g. _googlecast._tcp) by scanning the raw DNS payload. */
function bwd_fp_mdns($ip) {
	if (!function_exists('socket_create')) { return array(); }
	$svcs = array();
	$qname = "\x09_services\x07_dns-sd\x04_udp\x05local\x00";
	// PTR question with the QU (unicast-response) bit set in qclass (0x8001) so
	// the device replies unicast to our port instead of only to the mDNS group.
	$pkt = "\x00\x00\x00\x00\x00\x01\x00\x00\x00\x00\x00\x00" . $qname . "\x00\x0c\x80\x01";
	$s = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
	if (!$s) { return array(); }
	@socket_set_option($s, SOL_SOCKET, SO_REUSEADDR, 1);
	$src = bwd_fp_src_ip($ip);
	@socket_bind($s, $src ?: '0.0.0.0', 0);
	if ($src) { @socket_set_option($s, IPPROTO_IP, IP_MULTICAST_IF, $src); }
	@socket_set_option($s, IPPROTO_IP, IP_MULTICAST_TTL, 2);
	@socket_sendto($s, $pkt, strlen($pkt), 0, '224.0.0.251', 5353);
	// also unicast-nudge the target directly
	@socket_sendto($s, $pkt, strlen($pkt), 0, $ip, 5353);
	$deadline = microtime(true) + 2.5;
	while (microtime(true) < $deadline) {
		$rd = array($s); $w = null; $e = null;
		$r = @socket_select($rd, $w, $e, 0, 300000);
		if ($r === false || $r === 0) { continue; }
		$buf = ''; $from = ''; $port = 0;
		if (@socket_recvfrom($s, $buf, 2048, 0, $from, $port) === false) { continue; }
		if ($from !== $ip) { continue; }   // only the device we're probing
		if (preg_match_all('/_[a-z0-9-]{2,}\._(?:tcp|udp)/i', $buf, $m)) {
			foreach ($m[0] as $svc) { $svcs[strtolower($svc)] = true; }
		}
	}
	@socket_close($s);
	return array_keys($svcs);
}

/* SSDP (1900) M-SEARCH, unicast to the target (and multicast), collecting the
 * response ST / SERVER / LOCATION headers. */
function bwd_fp_ssdp($ip) {
	if (!function_exists('socket_create')) { return array(); }
	$req = "M-SEARCH * HTTP/1.1\r\nHOST: 239.255.255.250:1900\r\nMAN: \"ssdp:discover\"\r\nMX: 2\r\nST: ssdp:all\r\n\r\n";
	$s = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
	if (!$s) { return array(); }
	@socket_set_option($s, SOL_SOCKET, SO_REUSEADDR, 1);
	$src = bwd_fp_src_ip($ip);
	@socket_bind($s, $src ?: '0.0.0.0', 0);
	if ($src) { @socket_set_option($s, IPPROTO_IP, IP_MULTICAST_IF, $src); }
	@socket_sendto($s, $req, strlen($req), 0, $ip, 1900);
	@socket_sendto($s, $req, strlen($req), 0, '239.255.255.250', 1900);
	$hdrs = array(); $deadline = microtime(true) + 2.5;
	while (microtime(true) < $deadline) {
		$rd = array($s); $w = null; $e = null;
		$r = @socket_select($rd, $w, $e, 0, 300000);
		if (!$r) { continue; }
		$buf = ''; $from = ''; $port = 0;
		if (@socket_recvfrom($s, $buf, 2048, 0, $from, $port) === false) { continue; }
		if ($from !== $ip) { continue; }
		foreach (array('st', 'server', 'location', 'usn') as $h) {
			if (preg_match('/^' . $h . ':\s*(.+)$/im', $buf, $m)) { $hdrs[$h] = trim($m[1]); }
		}
	}
	@socket_close($s);
	return $hdrs;
}

/* Harvest DHCP option 55 (PRL) / 60 (vendor-class) / 12 (hostname) from live
 * DHCP traffic via tcpdump for $secs seconds. Returns mac => [opt55,opt60,host].
 * Passive but needs lease traffic during the window — for the cron pass, not
 * on-demand. Best-effort (skips silently without tcpdump / on no traffic). */
function bwd_fp_dhcp_harvest($iface = '', $secs = 60) {
	$tcpdump = is_file('/usr/sbin/tcpdump') ? '/usr/sbin/tcpdump' : 'tcpdump';
	$if = $iface !== '' ? ('-i ' . escapeshellarg($iface)) : '';
	// -vv decodes DHCP options (Parameter-Request List with (code) per option,
	// Vendor-Class string, Hostname string). One packet per timestamped block.
	$cmd = sprintf('timeout %d %s %s -nevvl -s 0 "(udp port 67 or udp port 68)" 2>/dev/null',
		(int) $secs + 2, $tcpdump, $if);
	$out = @shell_exec($cmd);
	$devs = array();
	if (!$out) { return $devs; }
	$blocks = preg_split('/\n(?=\d{2}:\d{2}:\d{2}\.\d+ )/', $out);
	foreach ($blocks as $b) {
		// client MAC: the Client-Ethernet-Address, else the source MAC
		$mac = '';
		if (preg_match('/Client-Ethernet-Address\s+([0-9a-f]{2}(?::[0-9a-f]{2}){5})/i', $b, $m)) { $mac = strtolower($m[1]); }
		elseif (preg_match('/([0-9a-f]{2}(?::[0-9a-f]{2}){5})\s*>/i', $b, $m)) { $mac = strtolower($m[1]); }
		if ($mac === '') { continue; }
		if (!isset($devs[$mac])) { $devs[$mac] = array('opt55' => '', 'opt60' => '', 'host' => ''); }
		// option 60 vendor-class (quoted string)
		if (preg_match('/Vendor-Class[^:\n]*:\s*"([^"]*)"/i', $b, $m) && $m[1] !== '') { $devs[$mac]['opt60'] = trim($m[1]); }
		// option 12 hostname
		if (preg_match('/Hostname[^:\n]*:\s*"([^"]*)"/i', $b, $m) && $m[1] !== '') { $devs[$mac]['host'] = trim($m[1]); }
		// option 55 PRL: take the parenthesized option codes after the header
		if (preg_match('/Parameter-Request List[^:]*:(.*?)(?:\n\S|\n\s+[A-Z][\w-]+ \(5[2-9]\)|$)/is', $b, $m)
			&& preg_match_all('/\((\d+)\)/', $m[1], $codes) && $codes[1]) {
			$devs[$mac]['opt55'] = implode(',', $codes[1]);
		}
	}
	return $devs;
}

/* ============================ MATCHERS ============================ */

/* Run an ordered HTTP rule list (endpoint-body / server / title / nmap). */
function bwd_fp_match_http($obs) {
	foreach (bwd_fp_section('http') as $rule) {
		$model = ''; $via = ''; $conf = 0;
		if (!empty($rule['endpoint']) && isset($obs['endpoints'][$rule['endpoint']])) {
			$body = $obs['endpoints'][$rule['endpoint']];
			if (!empty($rule['body'])) { if (!preg_match($rule['body'], $body, $m)) { continue; } if (isset($m[1])) { $model = trim($m[1]); } elseif (isset($m[2])) { $model = trim($m[2]); } }
			$via = 'http ' . $rule['endpoint']; $conf = $rule['confidence'] ?? 0.92;
		} elseif (!empty($rule['server'])) {
			$hit = false; foreach ($obs['server'] as $s) { if (preg_match($rule['server'], $s)) { $hit = true; break; } }
			if (!$hit) { continue; } $via = 'http server'; $conf = $rule['confidence'] ?? 0.6;
		} elseif (!empty($rule['title'])) {
			$hit = false; foreach ($obs['titles'] as $t) { if (preg_match($rule['title'], $t)) { $hit = true; break; } }
			if (!$hit) { continue; } $via = 'http title'; $conf = $rule['confidence'] ?? 0.7;
		} elseif (!empty($rule['nmap'])) {
			$hit = false; foreach ($obs['nmap'] as $s) { if (preg_match($rule['nmap'], $s)) { $hit = true; break; } }
			if (!$hit) { continue; } $via = 'nmap'; $conf = $rule['confidence'] ?? 0.65;
		} else { continue; }
		if (!empty($rule['model'])) { $model = $rule['model']; }
		if (!empty($rule['model_map']) && $model !== '' && isset($rule['model_map'][$model])) { $model = $rule['model_map'][$model]; }
		if (!empty($rule['model_fmt']) && $rule['model_fmt'] === 'shelly' && $model !== '') { $model = bwd_fp_shelly_app_name($model); }
		return array('vendor' => $rule['vendor'] ?? '', 'model' => $model, 'os' => $rule['os'] ?? '', 'tag' => $rule['tag'] ?? '', 'confidence' => $conf, 'via' => $via);
	}
	return null;
}
/* Format a Shelly Gen2/3 "app" product code into a readable model name, e.g.
 * "DimmerG3" -> "Shelly Dimmer Gen 3", "Plus1PM" -> "Shelly Plus 1PM",
 * "Pro4PM" -> "Shelly Pro 4PM". */
function bwd_fp_shelly_app_name($app) {
	$s = preg_replace('/G(\d)\b/', ' Gen $1', $app);         // trailing G3 -> Gen 3 (any position)
	$s = preg_replace('/(?<=[a-z])(?=[A-Z])/', ' ', $s);     // camelCase -> spaced (PlugSG3 -> Plug S...)
	$s = preg_replace('/\bI(?=\d)/', 'i', $s);               // Shelly i-series: I4 -> i4
	$s = preg_replace('/(?<=[A-Za-z])(?=\d)/', ' ', $s);     // letters|digits -> spaced (Plus1PM -> Plus 1PM)
	$s = preg_replace('/\bi (\d)/', 'i$1', $s);              // re-join the i-series: "i 4" -> "i4"
	$s = trim(preg_replace('/\s+/', ' ', $s));
	$s = preg_replace('/^S (?=\d)/', '', $s);                // drop the lone Gen3 base-line "S " prefix
	return 'Shelly ' . $s;
}

/* Map mDNS service types to a device class (most specific service wins). */
function bwd_fp_match_mdns($services) {
	$sig = bwd_fp_section('mdns'); $best = null;
	foreach ($services as $svc) {
		foreach ($sig as $pat => $r) {
			if (@preg_match($pat, $svc) || strcasecmp($pat, $svc) === 0 || strpos($svc, trim($pat, '/')) !== false) {
				$cand = array('vendor' => $r['vendor'] ?? '', 'model' => $r['model'] ?? '', 'os' => $r['os'] ?? '', 'tag' => $r['tag'] ?? '', 'confidence' => $r['confidence'] ?? 0.85, 'via' => 'mdns ' . $svc);
				if ($best === null || $cand['confidence'] > $best['confidence']) { $best = $cand; }
			}
		}
	}
	return $best;
}
/* Match SSDP headers (ST/SERVER/deviceType). */
function bwd_fp_match_ssdp($hdrs) {
	if (!$hdrs) { return null; }
	$hay = strtolower(implode(' ', $hdrs));
	foreach (bwd_fp_section('ssdp') as $rule) {
		if (!empty($rule['match']) && preg_match($rule['match'], $hay)) {
			return array('vendor' => $rule['vendor'] ?? '', 'model' => $rule['model'] ?? '', 'os' => $rule['os'] ?? '', 'tag' => $rule['tag'] ?? '', 'confidence' => $rule['confidence'] ?? 0.8, 'via' => 'ssdp');
		}
	}
	return null;
}
/* Match TCP/TLS banners — highest-confidence rule wins (lines are unordered, so
 * a specific vendor cert must beat a generic SSH banner regardless of position). */
function bwd_fp_match_banner($banners) {
	if (!$banners) { return null; }
	$hay = implode("\n", $banners);
	$best = null;
	foreach (bwd_fp_section('banner') as $rule) {
		if (empty($rule['match']) || !preg_match($rule['match'], $hay)) { continue; }
		$conf = $rule['confidence'] ?? 0.65;
		if ($best !== null && $conf <= $best['confidence']) { continue; }
		// banner/TLS capture groups are versions/CNs, not device models — only an
		// explicit 'model' on the rule is used as the model.
		$model = $rule['model'] ?? '';
		$best = array('vendor' => $rule['vendor'] ?? '', 'model' => $model,
			'os' => $rule['os'] ?? '', 'tag' => $rule['tag'] ?? '', 'confidence' => $conf, 'via' => 'banner');
	}
	return $best;
}
/* Match DHCP option 60 (vendor-class) then option 55 (PRL sequence). */
function bwd_fp_match_dhcp($d) {
	$sig = bwd_fp_section('dhcp');
	foreach ((array) ($sig['opt60'] ?? array()) as $pat => $r) {
		if (!empty($d['opt60']) && @preg_match($pat, $d['opt60'])) {
			return array('vendor' => $r['vendor'] ?? '', 'model' => $r['model'] ?? '', 'os' => $r['os'] ?? '', 'tag' => $r['tag'] ?? '', 'confidence' => $r['confidence'] ?? 0.8, 'via' => 'dhcp opt60');
		}
	}
	foreach ((array) ($sig['opt55'] ?? array()) as $seq => $r) {
		if (!empty($d['opt55']) && $d['opt55'] === $seq) {
			return array('vendor' => $r['vendor'] ?? '', 'model' => $r['model'] ?? '', 'os' => $r['os'] ?? '', 'tag' => $r['tag'] ?? '', 'confidence' => $r['confidence'] ?? 0.75, 'via' => 'dhcp opt55');
		}
	}
	return null;
}

/* ====================== ON-DEMAND IDENTIFY ====================== */

/* Fingerprint a device with the on-demand techniques (HTTP/TLS/banner/mDNS/
 * SSDP/nmap), match each against the signature DB, and return the highest-
 * confidence identification + the raw observations. Read-only on the target.
 * $opts: 'nmap' (default true), 'fast' (HTTP+TLS only — skip the slow mDNS/SSDP/
 * banner wait windows; for bulk/cron), 'timeout' (per-request seconds). */
function bwd_fp_identify_device($ip, $opts = array()) {
	$fast    = !empty($opts['fast']);
	$useNmap = ($opts['nmap'] ?? true) && !$fast;          // nmap is slow → off in fast mode
	$to      = (int) ($opts['timeout'] ?? ($fast ? 2 : BWD_FP_TIMEOUT));
	$obs = array('server' => array(), 'titles' => array(), 'endpoints' => array(), 'open' => array(),
		'nmap' => array(), 'tls' => array(), 'banners' => array(), 'mdns' => array(), 'ssdp' => array());

	// HTTP root on each candidate port
	$base = '';
	foreach (explode(',', BWD_FP_HTTP_PORTS) as $port) {
		$scheme = in_array((int) $port, array(443, 8443)) ? 'https' : 'http';
		$r = bwd_fp_http_get("$scheme://$ip:$port/", $to);
		if ($r['status'] === 0) { continue; }
		$obs['open'][] = "$scheme:$port";
		if ($r['server'] !== '') { $obs['server'][] = $r['server']; }
		if ($r['title'] !== '')  { $obs['titles'][] = $r['title']; }
		if ($r['www_authenticate'] !== '') { $obs['server'][] = 'WWW-Authenticate: ' . $r['www_authenticate']; }
		if ($base === '') { $base = "$scheme://$ip:$port"; }
	}
	// HTTP identity endpoints (deduped, bounded)
	if ($base !== '') {
		$fetched = array();
		foreach (bwd_fp_section('http') as $rule) {
			$ep = $rule['endpoint'] ?? '';
			if ($ep === '' || isset($fetched[$ep])) { continue; }
			$fetched[$ep] = true;
			$r = bwd_fp_http_get($base . $ep, $to);
			if ($r['status'] === 200 && $r['body'] !== '') { $obs['endpoints'][$ep] = $r['body']; }
			if (count($fetched) >= 14) { break; }
		}
	}
	$obs['tls'] = bwd_fp_tls($ip, array(443, 8443), $to);   // high-yield (cert issuer); kept in fast mode
	if (!$fast) {
		$obs['mdns']    = bwd_fp_mdns($ip);
		$obs['ssdp']    = bwd_fp_ssdp($ip);
		$obs['banners'] = bwd_fp_banner($ip);
	}
	if ($useNmap) { $obs['nmap'] = bwd_fp_nmap($ip); }

	// gather candidate identifications, keep the most confident
	$cands = array_filter(array(
		bwd_fp_match_http($obs),
		bwd_fp_match_mdns($obs['mdns']),
		bwd_fp_match_ssdp($obs['ssdp']),
		bwd_fp_match_banner(array_merge($obs['banners'], $obs['tls'])),
	));
	$best = array('vendor' => '', 'model' => '', 'os' => '', 'tag' => '', 'confidence' => 0.0, 'via' => '');
	foreach ($cands as $c) { if ($c && $c['confidence'] > $best['confidence']) { $best = $c; } }
	// merge: fill empty vendor/model/os from other candidates of the same tag
	foreach ($cands as $c) {
		foreach (array('vendor', 'model', 'os') as $k) { if ($best[$k] === '' && !empty($c[$k])) { $best[$k] = $c[$k]; } }
	}
	$best['ip'] = $ip;
	$best['observations'] = array('server' => $obs['server'], 'titles' => $obs['titles'], 'open' => $obs['open'],
		'endpoints' => array_keys($obs['endpoints']), 'mdns' => $obs['mdns'], 'ssdp' => $obs['ssdp'],
		'banners' => $obs['banners'], 'tls' => $obs['tls'], 'nmap' => $obs['nmap']);
	return bwd_fp_clean_deep($best);
}

/* Everything in a fingerprint result except the numbers came off the wire from
 * the device itself. Before it is persisted and re-read on every hosts request:
 * drop control characters, replace invalid UTF-8, and cap each string, so a
 * hostile or merely broken device can neither corrupt the cache (json_encode of
 * invalid UTF-8 is false) nor inflate it with a 64 KB "model". */
function bwd_fp_clean($s, $cap = 200) {
	if (!is_string($s)) { return $s; }
	$s = @iconv('UTF-8', 'UTF-8//IGNORE', $s);
	if ($s === false) { $s = ''; }
	$s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $s);
	if (strlen($s) > $cap) { $s = substr($s, 0, $cap); $s = @iconv('UTF-8', 'UTF-8//IGNORE', $s) ?: ''; }
	return $s;
}
function bwd_fp_clean_deep($v, $cap = 200) {
	if (is_array($v)) {
		$out = array();
		foreach ($v as $k => $x) { $out[bwd_fp_clean((string) $k, 64)] = bwd_fp_clean_deep($x, $cap); }
		return $out;
	}
	return bwd_fp_clean($v, $cap);
}

/* ============================ CACHE ============================ */

function bwd_fp_cache() {
	static $c = null;
	if ($c === null) { $c = is_file(BWD_FP_CACHE) ? (json_decode(@file_get_contents(BWD_FP_CACHE), true) ?: array()) : array(); }
	return $c;
}
function bwd_fp_store($mac, $ip, $result) {
	/* Serialize the whole load-modify-save: the scheduled cron probe pass and a GUI
	 * "Probe device" click can run concurrently, and without holding a lock across
	 * the read+write window they would clobber each other's cache entries. */
	$lock = @fopen(BWD_FP_CACHE . '.lock', 'c');
	if ($lock) { @flock($lock, LOCK_EX); }

	$c = is_file(BWD_FP_CACHE) ? (json_decode(@file_get_contents(BWD_FP_CACHE), true) ?: array()) : array();
	$key = $mac ?: $ip;
	// Don't downgrade a prior good identification with an empty result — the
	// device is likely just offline (e.g. a smart bulb switched off). Keep the
	// old data but bump ts so the cron pass doesn't hammer it every run.
	$empty = empty($result['vendor']) && empty($result['model']) && empty($result['tag']);
	$had = isset($c[$key]) && (!empty($c[$key]['vendor']) || !empty($c[$key]['model']) || !empty($c[$key]['tag']));
	if ($empty && $had) {
		$c[$key]['ts'] = time();
		$ret = $c[$key];
	} else {
		$result['ts'] = time(); $result['ip'] = $ip;
		$c[$key] = $result;
		if (count($c) > 1000) { $c = array_slice($c, -800, null, true); }
		$ret = $result;
	}
	bwd_atomic_write(BWD_FP_CACHE, bwd_json($c));
	if ($lock) { @flock($lock, LOCK_UN); @fclose($lock); }
	return $ret;
}
function bwd_fp_for($mac, $ip = '') {
	$c = bwd_fp_cache();
	foreach (array((string) $mac, (string) $ip) as $k) { if ($k !== '' && isset($c[$k])) { return $c[$k]; } }
	return null;
}
/* True if a device has a cached fingerprint younger than $maxAgeSec — the cron
 * enrichment pass skips fresh devices so each run is cheap and incremental. */
function bwd_fp_fresh($mac, $ip, $maxAgeSec) {
	$pf = bwd_fp_for($mac, $ip);
	return $pf && isset($pf['ts']) && (time() - (int) $pf['ts']) < $maxAgeSec;
}
