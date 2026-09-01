<?php
/*
 * bwd_platform.inc.php — OPNsense platform bindings for the BandwidthD data layer.
 *
 * This is the ONLY file that knows about OPNsense internals. Everything else
 * (bwd_data / bwd_db / bwd_fingerprint and the cron scripts) talks to the box
 * exclusively through the functions defined here, so a future port to another
 * platform is a rewrite of this file alone.
 *
 * The surface it provides:
 *   settings       bwd_cfg() / bwd_cfg_on() / bwd_cfg_set()
 *   overrides      bwd_overrides_rows() / bwd_overrides_save()
 *   notices        bwd_notify()   (syslog)
 *   shell helpers  mwexec() / safe_mkdir() / unlink_if_exists(), defined if absent
 *   identity       Kea CSV + dnsmasq leases; unboundplus / dnsmasq / Kea config nodes
 *
 * Licensed under the Apache License, Version 2.0.
 */

require_once("config.inc");
require_once("util.inc");

if (!defined('BWD_BASE')) {
	define('BWD_BASE', '/usr/local/bandwidthd');
}
/* Where the plugin's settings live in config.xml (the MVC model root). */
if (!defined('BWD_MODEL_PATH')) {
	define('BWD_MODEL_PATH', 'OPNsense/Bandwidthd');
}

/* ------------------------------------------------------------------ config */

/* Walk a slash-separated path through the legacy $config array. */
function bwd_config_path($path, $default = null) {
	global $config;
	if (!is_array($config)) {
		$config = parse_config();
	}
	$node = $config;
	foreach (explode('/', trim($path, '/')) as $seg) {
		if (!is_array($node) || !array_key_exists($seg, $node)) {
			return $default;
		}
		$node = $node[$seg];
	}
	return ($node === null || $node === '') ? $default : $node;
}

/* Settings the model stores as a BooleanField ("1"/"0"). These — and ONLY these —
 * are translated to the "on"/"off" the ported logic expects. Translating by value
 * instead would corrupt every numeric setting: a 1 GB quota would read as "on",
 * and (float)"on" is 0, silently disabling the quota it was meant to set. */
function bwd_bool_keys() {
	return array(
		'enabled', 'promiscuous', 'drawgraphs', 'outputcdf', 'recovercdf',
		'alerts_enable', 'anomaly_enable', 'exfil_enable', 'newdevice_enable',
		'probe_enable', 'report_enable', 'db_enable',
	);
}

/* Read one plugin setting, e.g. bwd_cfg('alerts_enable'). Settings live under
 * OPNsense/Bandwidthd/general. */
function bwd_cfg($key, $default = null) {
	$v = bwd_config_path(BWD_MODEL_PATH . '/general/' . $key, null);
	if ($v === null) {
		return $default;
	}
	if (in_array($key, bwd_bool_keys(), true)) {
		return ($v === '1') ? 'on' : 'off';
	}
	return $v;
}

/* True when a boolean setting is on. */
function bwd_cfg_on($key) {
	return bwd_cfg($key, 'off') === 'on';
}

/* The MVC model is the only writer that gets config.xml right for this plugin: an
 * ArrayField item is <override uuid="..."> — the uuid is an XML ATTRIBUTE. Writing
 * the legacy $config array instead turns the uuid into an element name, which the
 * model then cannot read (the settings grid shows an empty row). So all writes,
 * and the override reads, go through the model. Returns null off-box. */
function bwd_model($reload = false) {
	static $mdl = null;
	if ($reload) { $mdl = null; }
	if ($mdl !== null) { return $mdl; }
	if (!class_exists('OPNsense\Bandwidthd\Bandwidthd')) {
		$loader = '/usr/local/opnsense/mvc/script/load_phalcon.php';
		if (!is_file($loader)) { return null; }
		require_once($loader);
	}
	if (!class_exists('OPNsense\Bandwidthd\Bandwidthd')) { return null; }
	$mdl = new OPNsense\Bandwidthd\Bandwidthd();
	return $mdl;
}

/* Persist a model after mutating it. */
function bwd_model_save($mdl, $reason) {
	$msgs = $mdl->performValidation();
	if ($msgs->count() > 0) {
		$out = array();
		foreach ($msgs as $m) { $out[] = $m->getField() . ': ' . $m->getMessage(); }
		/* Drop the cached model: it still holds the rejected mutations, and a later
		 * unrelated write in the same process would serialize and persist them. */
		bwd_model(true);
		return implode('; ', $out);
	}
	$mdl->serializeToConfig();
	/* save($revision = null, $backup = true). The description belongs INSIDE the
	 * revision array — passing it as a third argument silently drops it, and
	 * passing false as the second turns off the config backup and audit-log entry
	 * that every other OPNsense settings write produces. */
	OPNsense\Core\Config::getInstance()->save(array('description' => $reason));
	$GLOBALS['config'] = parse_config();   // keep the legacy array readers in sync
	bwd_model(true);
	return true;
}

/* Write one plugin setting back to config.xml. Booleans are stored as "1"/"0" by
 * the model, so "on"/"off" are translated on the way in. Callers that change
 * something the cron schedule depends on should follow up with bwd_reload_cron(),
 * since the crontab is derived from these settings. */
function bwd_cfg_set($key, $value, $reason = 'BandwidthD setting change') {
	$mdl = bwd_model();
	if ($mdl === null) { return false; }
	if ($value === 'on' || $value === true) { $value = '1'; }
	if ($value === 'off' || $value === false) { $value = '0'; }
	$mdl->general->$key = (string) $value;
	return bwd_model_save($mdl, $reason) === true;
}

/* Regenerate the crontab from the plugin hooks (see bandwidthd_cron()).
 *
 * system_cron_configure() is the writer. plugins_configure('cron') reads like the
 * right call and silently does nothing — 'cron' is not a registered plugin hook.
 * The core hooks it iterates call log_msg()/exec_safe(), so the full legacy
 * bootstrap has to be loaded or this fatals inside OPNsense's own files. */
function bwd_reload_cron() {
	require_once("legacy_bindings.inc");
	require_once("system.inc");
	require_once("interfaces.inc");
	require_once("plugins.inc");
	if (function_exists('system_cron_configure')) {
		system_cron_configure();
		return true;
	}
	return false;
}

/* ------------------------------------------------- per-device override rows */

/* The per-device override list (name/vendor/tag/quota/alert toggles) as a plain
 * list of rows, each carrying its model uuid.
 *
 * An ArrayField has no column limit and preserves what it is given. */
function bwd_overrides_rows() {
	$mdl = bwd_model();
	if ($mdl === null) {
		return array();
	}
	$rows = array();
	foreach ($mdl->overrides->override->iterateItems() as $uuid => $node) {
		$row = array('uuid' => $uuid);
		foreach ($node->iterateItems() as $field => $value) {
			$row[$field] = (string) $value;
		}
		$rows[] = $row;
	}
	return $rows;
}

/* Replace the whole override list. Rows keep their uuid where they have one, so
 * an edit updates in place rather than churning ids. Returns true, or a string
 * describing the validation failure.
 *
 * Item lookup is hasChild()/getChild() — ArrayField has no get(). */
function bwd_overrides_save($rows, $reason = 'BandwidthD: per-device override') {
	$mdl = bwd_model();
	if ($mdl === null) { return 'configuration model unavailable'; }
	$list = $mdl->overrides->override;

	$keep = array();
	foreach ($rows as $r) {
		if (!is_array($r)) { continue; }
		$uuid = (string) ($r['uuid'] ?? '');
		unset($r['uuid']);
		if ($uuid !== '' && $list->hasChild($uuid)) {
			$node = $list->getChild($uuid);
		} else {
			if ($uuid === '') { $uuid = bwd_uuid(); }
			$node = $list->add($uuid);
			if ($node === null) { return 'could not add an override row'; }
		}
		foreach ($r as $k => $v) {
			if (isset($node->$k)) { $node->$k = (string) $v; }
		}
		$keep[$uuid] = true;
	}
	/* Drop rows the caller no longer lists. */
	foreach (array_keys(iterator_to_array($list->iterateItems())) as $uuid) {
		if (!isset($keep[$uuid])) { $list->del($uuid); }
	}
	return bwd_model_save($mdl, $reason);
}

/* RFC-4122 v4 uuid — the id format the model grid uses for ArrayField items. */
function bwd_uuid() {
	$d = random_bytes(16);
	$d[6] = chr((ord($d[6]) & 0x0f) | 0x40);
	$d[8] = chr((ord($d[8]) & 0x3f) | 0x80);
	return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
}

/* ------------------------------------------------------------ notifications */

/* Raise an operator-visible notice. OPNsense surfaces plugin messages through
 * syslog (System -> Log Files -> General, filtered by the 'bandwidthd' tag), so
 * that is where alerts land. */
function bwd_notify($subject, $message = '', $priority = LOG_NOTICE) {
	$line = trim($subject . ($message !== '' ? ': ' . $message : ''));
	openlog('bandwidthd', LOG_PID, LOG_LOCAL4);
	syslog($priority, $line);
	closelog();
	return true;
}

/* ---------------------------------------------------- shell helper fallbacks */

if (!function_exists('mwexec')) {
	function mwexec($cmd, $mute = true) {
		$out = array(); $rc = 0;
		exec($cmd . ($mute ? ' 2>/dev/null' : ''), $out, $rc);
		return $rc;
	}
}
if (!function_exists('safe_mkdir')) {
	function safe_mkdir($path, $mode = 0755) {
		return is_dir($path) ? true : @mkdir($path, $mode, true);
	}
}
if (!function_exists('unlink_if_exists')) {
	function unlink_if_exists($path) {
		foreach (glob($path) ?: array() as $f) { @unlink($f); }
		return true;
	}
}
if (!function_exists('is_ipaddrv4')) {
	function is_ipaddrv4($v) {
		return (bool) filter_var($v, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);
	}
}
if (!function_exists('is_ipaddr')) {
	function is_ipaddr($v) {
		return (bool) filter_var($v, FILTER_VALIDATE_IP);
	}
}

/* ------------------------------------------------------- identity resolution */

/* Kea DHCP lease file. The CSV is append-only — later rows supersede earlier ones
 * for the same address — so we keep the LAST row per address and drop leases that
 * are not in state 0 (0=default/active, 1=declined, 2=expired-reclaimed).
 * Returns [ip => ['mac' => .., 'hostname' => ..]]. */
function bwd_kea_leases() {
	static $cache = null;
	if ($cache !== null) { return $cache; }
	$cache = array();
	/* Oldest first: rows overwrite unconditionally, so the CURRENT file must be read
	 * last or a stale lease from the rotated LFC output wins. Getting this backwards
	 * attributes a reused IP to the previous device, and an old state!=0 row there
	 * would unset a perfectly valid active lease. */
	foreach (array('/var/db/kea/kea-leases4.csv.2', '/var/db/kea/kea-leases4.csv') as $lf) {
		if (!is_file($lf) || !is_readable($lf)) { continue; }
		$fh = @fopen($lf, 'r');
		if ($fh === false) { continue; }
		/* Explicit $escape: PHP 8.5 deprecates the default, and Kea writes RFC-4180
		 * CSV where a backslash is a literal, not an escape — so '' is both the
		 * forward-compatible and the correct value. Left implicit, this logged a
		 * deprecation for every lease file on every parse. */
		$hdr = fgetcsv($fh, 0, ',', '"', '');
		if (!is_array($hdr)) { fclose($fh); continue; }
		$col = array_flip($hdr);
		if (!isset($col['address'], $col['hwaddr'])) { fclose($fh); continue; }
		while (($row = fgetcsv($fh, 0, ',', '"', '')) !== false) {
			if (!isset($row[$col['address']])) { continue; }
			$ip = trim($row[$col['address']]);
			if (!is_ipaddrv4($ip)) { continue; }
			$state = isset($col['state']) ? trim((string) ($row[$col['state']] ?? '0')) : '0';
			if ($state !== '0') { unset($cache[$ip]); continue; }
			$mac = strtolower(trim((string) ($row[$col['hwaddr']] ?? '')));
			$host = isset($col['hostname']) ? trim((string) ($row[$col['hostname']] ?? '')) : '';
			$cache[$ip] = array(
				'mac' => preg_match('/^([0-9a-f]{2}:){5}[0-9a-f]{2}$/', $mac) ? $mac : '',
				'hostname' => $host,
			);
		}
		fclose($fh);
	}
	return $cache;
}

/* dnsmasq's DHCP lease file: "<expiry> <mac> <ip> <hostname> <clientid>" per line.
 * (dnsmasq may run DNS-only, in which case there are no lease lines to read.) */
function bwd_dnsmasq_leases() {
	static $cache = null;
	if ($cache !== null) { return $cache; }
	$cache = array();
	$lf = '/var/db/dnsmasq.leases';
	if (!is_file($lf)) { return $cache; }
	foreach (@file($lf, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: array() as $line) {
		$p = preg_split('/\s+/', trim($line));
		if (count($p) < 4 || !is_ipaddrv4($p[2] ?? '')) { continue; }
		$mac = strtolower($p[1]);
		$host = ($p[3] === '*') ? '' : $p[3];
		$cache[$p[2]] = array(
			'mac' => preg_match('/^([0-9a-f]{2}:){5}[0-9a-f]{2}$/', $mac) ? $mac : '',
			'hostname' => $host,
		);
	}
	return $cache;
}

/* Build IP -> friendly-name from OPNsense sources, most authoritative first
 * (no slow reverse DNS): Unbound host overrides, dnsmasq host overrides, Kea
 * static reservations, then active DHCP leases. */
function bwd_platform_hostmap() {
	$map = array();

	/* Unbound DNS host overrides. Field naming has varied across releases, so
	 * accept both the modern and legacy spellings. */
	$uh = bwd_config_path(BWD_MODEL_PATH === '' ? '' : 'OPNsense/unboundplus/hosts/host', array());
	if (is_array($uh)) {
		if (isset($uh['hostname']) || isset($uh['host'])) { $uh = array($uh); }
		foreach ($uh as $h) {
			if (!is_array($h)) { continue; }
			$ip = $h['server'] ?? ($h['ip'] ?? '');
			$name = $h['hostname'] ?? ($h['host'] ?? '');
			if ($ip !== '' && $name !== '') {
				$map[$ip] = $name . (!empty($h['domain']) ? '.' . $h['domain'] : '');
			}
		}
	}

	/* dnsmasq host overrides (repeated <hosts> elements, not a container). */
	$dh = bwd_config_path('dnsmasq/hosts', array());
	if (is_array($dh)) {
		if (isset($dh['host']) || isset($dh['ip'])) { $dh = array($dh); }
		foreach ($dh as $h) {
			if (!is_array($h) || empty($h['ip']) || empty($h['host'])) { continue; }
			if (!empty($h['ignore'])) { continue; }
			if (isset($map[$h['ip']])) { continue; }
			$map[$h['ip']] = $h['host'] . (!empty($h['domain']) ? '.' . $h['domain'] : '');
		}
	}

	/* Kea static reservations. */
	foreach (bwd_kea_reservations() as $ip => $r) {
		if (!isset($map[$ip]) && $r['hostname'] !== '') { $map[$ip] = $r['hostname']; }
	}

	/* Active leases fill the rest. */
	foreach (bwd_kea_leases() + bwd_dnsmasq_leases() as $ip => $l) {
		if (!isset($map[$ip]) && !empty($l['hostname'])) { $map[$ip] = $l['hostname']; }
	}

	return $map;
}

/* Kea static reservations from config.xml -> [ip => ['mac'=>..,'hostname'=>..]]. */
function bwd_kea_reservations() {
	static $cache = null;
	if ($cache !== null) { return $cache; }
	$cache = array();
	$res = bwd_config_path('OPNsense/Kea/dhcp4/reservations/reservation', array());
	if (!is_array($res)) { return $cache; }
	if (isset($res['ip_address'])) { $res = array($res); }
	foreach ($res as $r) {
		if (!is_array($r) || empty($r['ip_address'])) { continue; }
		$cache[$r['ip_address']] = array(
			'mac' => strtolower(trim((string) ($r['hw_address'] ?? ''))),
			'hostname' => trim((string) ($r['hostname'] ?? '')),
		);
	}
	return $cache;
}

/* Build IP -> MAC. The live ARP table is authoritative; Kea reservations and
 * DHCP leases fill in devices that are currently quiet. */
function bwd_platform_macmap() {
	$map = array();
	$out = array();
	@exec('/usr/sbin/arp -an 2>/dev/null', $out);
	foreach ($out as $line) {
		if (preg_match('/\((\d+\.\d+\.\d+\.\d+)\) at ([0-9a-fA-F:]{17})/', $line, $m)) {
			$map[$m[1]] = strtolower($m[2]);
		}
	}
	foreach (bwd_kea_reservations() as $ip => $r) {
		if (!isset($map[$ip]) && $r['mac'] !== '') { $map[$ip] = $r['mac']; }
	}
	foreach (bwd_kea_leases() + bwd_dnsmasq_leases() as $ip => $l) {
		if (!isset($map[$ip]) && !empty($l['mac'])) { $map[$ip] = $l['mac']; }
	}
	return $map;
}

/* Monitored subnets: the CIDRs bandwidthd is told to watch, mirroring what the
 * config template writes into bandwidthd.conf. Returns ['10.0.0.0/24', ...].
 *
 * interfaces_primary_address() is the OPNsense way to ask this: it returns
 * [ip, network/cidr, bits, device] and answers correctly for dynamic interfaces
 * (pppoe, dhcp) as well as static ones, so there is no config-vs-runtime split to
 * get wrong. get_interface_subnet() does NOT exist on OPNsense — calling it
 * fataled outright for any interface without a static IPv4 in config.xml. */
function bwd_platform_subnets() {
	require_once("interfaces.inc");
	$out = array();
	$ifs = trim((string) bwd_cfg('interfaces', 'lan'));
	foreach (preg_split('/[,\s]+/', $ifs, -1, PREG_SPLIT_NO_EMPTY) as $if) {
		if (!function_exists('interfaces_primary_address')) {
			continue;
		}
		list($ip, $network, $bits, $device) = interfaces_primary_address($if);
		if ($network !== null && $ip !== null && is_ipaddrv4($ip)) {
			$out[] = $network;
		}
	}
	return array_values(array_unique($out));
}

/* The real (kernel) device for a configured interface, e.g. lan -> ix0. */
function bwd_platform_realif($if) {
	if (function_exists('get_real_interface')) {
		$real = get_real_interface($if);
		if (is_string($real) && $real !== '') {
			return $real;
		}
	}
	$real = bwd_config_path("interfaces/$if/if", '');
	return $real !== '' ? $real : $if;
}

/* ---------------------------------------------------------------- outbound mail */

/* OPNsense has no system-wide notification SMTP settings. The one SMTP server an
 * OPNsense box normally already has configured belongs to Monit, so we borrow it
 * rather than asking the operator to type the same server in twice. Returns null when nothing is configured. */
function bwd_smtp_config() {
	$m = bwd_config_path('OPNsense/monit/general', array());
	if (!is_array($m) || empty($m['mailserver'])) {
		return null;
	}
	$ssl = !empty($m['ssl']);
	return array(
		'host' => (string) $m['mailserver'],
		'port' => (int) ($m['port'] ?? ($ssl ? 465 : 25)),
		'ssl' => $ssl,
		'verify' => !empty($m['sslverify']),
		'user' => (string) ($m['username'] ?? ''),
		'pass' => (string) ($m['password'] ?? ''),
	);
}

/* Fully-qualified name of this box, used for EHLO and the default From. */
function bwd_fqdn() {
	$h = (string) bwd_config_path('system/hostname', 'opnsense');
	$d = (string) bwd_config_path('system/domain', 'localdomain');
	return $d !== '' ? "$h.$d" : $h;
}

/* Minimal multipart SMTP send. PEAR's Mail is not available on OPNsense, and the
 * base system ships no sendmail binary, so this speaks SMTP directly.
 * Returns true, or a string describing why it could not send. */
function bwd_send_mail($subject, $text, $html, $recipients) {
	$cfg = bwd_smtp_config();
	if ($cfg === null) {
		return 'no SMTP server configured (set one under Services -> Monit -> Settings)';
	}
	$to = preg_split('/[\s,;]+/', (string) $recipients, -1, PREG_SPLIT_NO_EMPTY);
	if (!$to) {
		return 'no recipients configured';
	}

	$fqdn = bwd_fqdn();
	$from = 'bandwidthd@' . $fqdn;
	$transport = $cfg['ssl'] ? 'ssl://' : 'tcp://';
	$ctx = stream_context_create($cfg['verify'] ? array() : array(
		'ssl' => array('verify_peer' => false, 'verify_peer_name' => false),
	));
	$fh = @stream_socket_client($transport . $cfg['host'] . ':' . $cfg['port'],
		$errno, $errstr, 20, STREAM_CLIENT_CONNECT, $ctx);
	if ($fh === false) {
		return "connect to {$cfg['host']}:{$cfg['port']} failed: $errstr";
	}
	stream_set_timeout($fh, 20);

	/* Read one SMTP reply (handles multi-line "250-" continuations). */
	$read = function () use ($fh) {
		$out = '';
		while (($line = fgets($fh, 1024)) !== false) {
			$out .= $line;
			if (strlen($line) < 4 || $line[3] !== '-') { break; }
		}
		return $out;
	};
	$cmd = function ($line, $expect) use ($fh, $read) {
		if ($line !== null) { fwrite($fh, $line . "\r\n"); }
		$resp = $read();
		return (strncmp($resp, (string) $expect, strlen((string) $expect)) === 0) ? true : trim($resp);
	};

	$fail = null;
	do {
		if (($r = $cmd(null, '220')) !== true) { $fail = "greeting: $r"; break; }
		if (($r = $cmd("EHLO $fqdn", '250')) !== true) { $fail = "EHLO: $r"; break; }

		/* STARTTLS on a plaintext port. Attempted whether or not we authenticate, so
		 * the report body is not sent in clear when the server supports TLS. When
		 * credentials ARE configured it is mandatory: silently falling back to
		 * cleartext AUTH hands the mailbox password to anyone able to strip the
		 * capability from the server's EHLO. */
		if (!$cfg['ssl']) {
			$starttls = $cmd('STARTTLS', '220');
			if ($starttls === true) {
				if (!@stream_socket_enable_crypto($fh, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
					$fail = 'STARTTLS negotiation failed'; break;
				}
				if (($r = $cmd("EHLO $fqdn", '250')) !== true) { $fail = "EHLO after TLS: $r"; break; }
			} elseif ($cfg['user'] !== '') {
				$fail = 'server does not offer STARTTLS and credentials are configured; '
					. 'refusing to send the password in clear';
				break;
			}
		}
		if ($cfg['user'] !== '') {
			if (($r = $cmd('AUTH LOGIN', '334')) !== true) { $fail = "AUTH: $r"; break; }
			if (($r = $cmd(base64_encode($cfg['user']), '334')) !== true) { $fail = "AUTH user: $r"; break; }
			if (($r = $cmd(base64_encode($cfg['pass']), '235')) !== true) { $fail = 'AUTH rejected'; break; }
		}
		if (($r = $cmd("MAIL FROM:<$from>", '250')) !== true) { $fail = "MAIL FROM: $r"; break; }
		foreach ($to as $rcpt) {
			if (($r = $cmd("RCPT TO:<$rcpt>", '250')) !== true) { $fail = "RCPT $rcpt: $r"; break 2; }
		}
		if (($r = $cmd('DATA', '354')) !== true) { $fail = "DATA: $r"; break; }

		/* Strip CR/LF before anything reaches a header. No current caller passes an
		 * attacker-influenced subject, but this is the seam every future one uses,
		 * and device names (which alert subjects want) come from DHCP hostnames. */
		$subject = str_replace(array("\r", "\n"), ' ', (string) $subject);
		$boundary = '=_bwd_' . md5(uniqid('', true));
		$msg = "From: BandwidthD <$from>\r\n"
			. 'To: ' . implode(', ', $to) . "\r\n"
			. 'Subject: ' . $subject . "\r\n"
			. 'Date: ' . date('r') . "\r\n"
			. "MIME-Version: 1.0\r\n"
			. "Content-Type: multipart/alternative; boundary=\"$boundary\"\r\n\r\n"
			. "--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n" . $text . "\r\n"
			. "--$boundary\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n" . $html . "\r\n"
			. "--$boundary--\r\n";
		/* Dot-stuff so a line of "." in the body cannot end the message early. */
		$msg = preg_replace('/^\./m', '..', str_replace("\n", "\r\n", str_replace("\r\n", "\n", $msg)));
		fwrite($fh, $msg . "\r\n.\r\n");
		if (($r = $cmd(null, '250')) !== true) { $fail = "send: $r"; break; }
	} while (false);

	@fwrite($fh, "QUIT\r\n");
	@fclose($fh);
	return $fail === null ? true : $fail;
}
