<?php
/*
 * fingerprints.php — device fingerprint SIGNATURE DATABASE (#11).
 *
 * The single, separately-updatable rule data the engine (bwd_fingerprint.inc.php)
 * matches against. Update this file independently of the engine code — like the
 * OUI table. Sectioned per technique:
 *
 *   http   : ordered rules, first-match-wins. ONE of endpoint+body / server /
 *            title / nmap. Optional model / model_map / os / confidence.
 *   mdns   : service-type substring  => [vendor,model,os,tag,confidence]
 *   ssdp   : ['match'=>/regex over ST+SERVER+LOCATION+USN/, vendor,model,tag,..]
 *   dhcp   : ['opt60'=>[/vendor-class regex/=>..], 'opt55'=>['PRL,seq'=>..]]
 *   banner : ['match'=>/regex over ssh/telnet/rtsp/tls lines/, vendor,tag,..]
 *
 * Tags the UI knows: pc, phone, tablet, tv, iot, camera, printer, network,
 * voip, gaming, nas, appliance.
 *
 * Licensed under the Apache License, Version 2.0.
 */
return array(

/* ============================ HTTP ============================ */
'http' => array(
	// Shelly — /shelly is unauthenticated identity.
	// Gen2/3 ({"gen":3,"model":"S3DM-..","app":"DimmerG3"}): use the readable
	// "app" product name, not the cryptic model code (model_fmt=shelly formats it).
	array('endpoint' => '/shelly', 'body' => '/"gen"\s*:\s*[2-9][^}]*"app"\s*:\s*"([^"]+)"|"app"\s*:\s*"([^"]+)"[^}]*"gen"\s*:\s*[2-9]/i',
		'vendor' => 'Shelly', 'tag' => 'iot', 'confidence' => 0.95, 'model_fmt' => 'shelly'),
	// Gen2/3 fallback if "app" is missing — fall back to the model code.
	array('endpoint' => '/shelly', 'body' => '/"model"\s*:\s*"([^"]+)"[^}]*"gen"\s*:\s*[2-9]|"gen"\s*:\s*[2-9][^}]*"model"\s*:\s*"([^"]+)"/i',
		'vendor' => 'Shelly', 'tag' => 'iot', 'confidence' => 0.94),
	array('endpoint' => '/shelly', 'body' => '/"type"\s*:\s*"(SH[A-Z0-9-]+)"/', 'vendor' => 'Shelly', 'tag' => 'iot', 'confidence' => 0.95,
		'model_map' => array(
			'SHSW-1' => 'Shelly 1', 'SHSW-PM' => 'Shelly 1PM', 'SHSW-L' => 'Shelly 1L', 'SHSW-21' => 'Shelly 2',
			'SHSW-25' => 'Shelly 2.5', 'SHSW-44' => 'Shelly 4Pro', 'SHPLG-S' => 'Shelly Plug S', 'SHPLG2-1' => 'Shelly Plug',
			'SHPLG-1' => 'Shelly Plug', 'SHDM-1' => 'Shelly Dimmer', 'SHDM-2' => 'Shelly Dimmer 2', 'SHRGBW2' => 'Shelly RGBW2',
			'SHEM' => 'Shelly EM', 'SHEM-3' => 'Shelly 3EM', 'SHBLB-1' => 'Shelly Bulb', 'SHCB-1' => 'Shelly Bulb Duo',
			'SHVIN-1' => 'Shelly Vintage', 'SHHT-1' => 'Shelly H&T', 'SHWT-1' => 'Shelly Flood', 'SHDW-1' => 'Shelly Door/Window',
			'SHDW-2' => 'Shelly Door/Window 2', 'SHIX3-1' => 'Shelly i3', 'SHBTN-1' => 'Shelly Button1', 'SHBTN-2' => 'Shelly Button1',
			'SHUNI-1' => 'Shelly Uni', 'SHGS-1' => 'Shelly Gas', 'SHMOS-01' => 'Shelly Motion', 'SHMOS-02' => 'Shelly Motion 2')),
	array('endpoint' => '/cm?cmnd=Status%200', 'body' => '/"(?:Tasmota|StatusFWR|Module)"|"FriendlyName"/', 'vendor' => 'Tasmota', 'tag' => 'iot', 'confidence' => 0.9),
	array('endpoint' => '/description.xml', 'body' => '/Philips hue bridge/i', 'vendor' => 'Philips Hue', 'model' => 'Hue Bridge', 'tag' => 'iot', 'confidence' => 0.95),
	array('endpoint' => '/api/v1/status', 'body' => '/esphome|"compilation_time"/i', 'vendor' => 'ESPHome', 'tag' => 'iot', 'confidence' => 0.85),
	array('endpoint' => '/DevMgmt/ProductConfigDyn.xml', 'body' => '#<[^>]*MakeAndModel>([^<]+)<#i', 'vendor' => 'HP', 'tag' => 'printer', 'confidence' => 0.92),
	array('endpoint' => '/description.xml', 'body' => '#<deviceType>[^<]*(?:Printer)[^<]*</deviceType>#i', 'tag' => 'printer', 'confidence' => 0.75),
	array('endpoint' => '/description.xml', 'body' => '#<deviceType>[^<]*(?:MediaRenderer|MediaServer|tvdevice)[^<]*</deviceType>#i', 'tag' => 'tv', 'confidence' => 0.7),
	array('endpoint' => '/description.xml', 'body' => '#<modelName>([^<]+)</modelName>#i', 'tag' => '', 'confidence' => 0.55),
	array('endpoint' => '/webman/index.cgi', 'body' => '/Synology|DiskStation/i', 'vendor' => 'Synology', 'tag' => 'nas', 'confidence' => 0.9),
	// server / title fallbacks
	array('server' => '/Hikvision|DAHUA|Dahua|Webs\b/i', 'tag' => 'camera', 'confidence' => 0.6),
	array('server' => '/GoAhead-Webs|Boa\/|uc-httpd|thttpd/i', 'tag' => 'camera', 'confidence' => 0.5),
	array('server' => '/Mongoose/i', 'vendor' => 'Espressif', 'tag' => 'iot', 'confidence' => 0.6),
	array('server' => '/RomPager|micro_httpd|lwIP/i', 'tag' => 'network', 'confidence' => 0.45),
	array('title' => '/QNAP|QTS\b/i', 'vendor' => 'QNAP', 'tag' => 'nas', 'confidence' => 0.8),
	array('title' => '/Synology|DiskStation/i', 'vendor' => 'Synology', 'tag' => 'nas', 'confidence' => 0.8),
	array('title' => '/UniFi|UDM|Dream Machine|EdgeOS|OpenWrt|MikroTik|RouterOS/i', 'tag' => 'network', 'confidence' => 0.7),
	array('title' => '/ESPHome/i', 'vendor' => 'ESPHome', 'tag' => 'iot', 'confidence' => 0.8),
	array('title' => '/LaserJet|OfficeJet|DeskJet|Printer|imageCLASS|WorkForce/i', 'tag' => 'printer', 'confidence' => 0.7),
	array('title' => '/NETGEAR ReadyNAS/i', 'vendor' => 'NETGEAR', 'model' => 'ReadyNAS', 'tag' => 'nas', 'confidence' => 0.85),
	array('nmap' => '#\bipp\b|cups|printer#i', 'tag' => 'printer', 'confidence' => 0.7),
	array('nmap' => '#\brtsp\b|onvif#i', 'tag' => 'camera', 'confidence' => 0.65),
	array('nmap' => '#routeros|mikrotik|edgeos#i', 'tag' => 'network', 'confidence' => 0.7),
),

/* ============================ mDNS (5353) ============================ */
'mdns' => array(
	'_googlecast._tcp'      => array('vendor' => 'Google', 'model' => 'Chromecast', 'tag' => 'tv', 'confidence' => 0.9),
	'_androidtvremote._tcp' => array('vendor' => 'Google', 'tag' => 'tv', 'confidence' => 0.9),
	'_amzn-wplay._tcp'      => array('vendor' => 'Amazon', 'model' => 'Fire TV', 'tag' => 'tv', 'confidence' => 0.9),
	'_airplay._tcp'         => array('vendor' => 'Apple', 'tag' => 'tv', 'confidence' => 0.85),
	'_raop._tcp'            => array('vendor' => 'Apple', 'model' => 'AirPlay audio', 'tag' => 'tv', 'confidence' => 0.8),
	'_appletv-v2._tcp'      => array('vendor' => 'Apple', 'model' => 'Apple TV', 'tag' => 'tv', 'confidence' => 0.92),
	'_sonos._tcp'           => array('vendor' => 'Sonos', 'tag' => 'tv', 'confidence' => 0.9),
	'_spotify-connect._tcp' => array('tag' => 'tv', 'confidence' => 0.6),
	'_viziocast._tcp'       => array('vendor' => 'Vizio', 'tag' => 'tv', 'confidence' => 0.9),
	'_nvstream._tcp'        => array('vendor' => 'NVIDIA', 'model' => 'Shield', 'tag' => 'tv', 'confidence' => 0.9),
	'_hap._tcp'             => array('model' => 'HomeKit accessory', 'tag' => 'iot', 'confidence' => 0.8),
	'_matter._tcp'          => array('model' => 'Matter device', 'tag' => 'iot', 'confidence' => 0.8),
	'_matterc._udp'         => array('model' => 'Matter device', 'tag' => 'iot', 'confidence' => 0.8),
	'_esphomelib._tcp'      => array('vendor' => 'ESPHome', 'tag' => 'iot', 'confidence' => 0.9),
	'_shelly._tcp'          => array('vendor' => 'Shelly', 'tag' => 'iot', 'confidence' => 0.9),
	'_miio._udp'            => array('vendor' => 'Xiaomi', 'tag' => 'iot', 'confidence' => 0.85),
	'_hue._tcp'             => array('vendor' => 'Philips Hue', 'tag' => 'iot', 'confidence' => 0.85),
	'_ipp._tcp'             => array('tag' => 'printer', 'confidence' => 0.85),
	'_ipps._tcp'            => array('tag' => 'printer', 'confidence' => 0.85),
	'_printer._tcp'         => array('tag' => 'printer', 'confidence' => 0.85),
	'_pdl-datastream._tcp'  => array('tag' => 'printer', 'confidence' => 0.8),
	'_uscan._tcp'           => array('tag' => 'printer', 'confidence' => 0.75),
	'_scanner._tcp'         => array('tag' => 'printer', 'confidence' => 0.75),
	'_nvme._tcp'            => array('tag' => 'nas', 'confidence' => 0.6),
	'_adisk._tcp'           => array('tag' => 'nas', 'confidence' => 0.6),
	'_smb._tcp'             => array('tag' => 'nas', 'confidence' => 0.45),
	'_afpovertcp._tcp'      => array('vendor' => 'Apple', 'tag' => 'nas', 'confidence' => 0.5),
	'_rfb._tcp'             => array('tag' => 'pc', 'confidence' => 0.5),
	'_workstation._tcp'     => array('tag' => 'pc', 'confidence' => 0.5),
	'_companion-link._tcp'  => array('vendor' => 'Apple', 'tag' => 'pc', 'confidence' => 0.4),
	'_sftp-ssh._tcp'        => array('tag' => 'pc', 'confidence' => 0.4),
	'_nut._tcp'             => array('model' => 'UPS', 'tag' => 'appliance', 'confidence' => 0.7),
	'_axis-video._tcp'      => array('vendor' => 'Axis', 'tag' => 'camera', 'confidence' => 0.9),
	'_dahua._tcp'           => array('vendor' => 'Dahua', 'tag' => 'camera', 'confidence' => 0.9),
),

/* ============================ SSDP (1900) ============================ */
'ssdp' => array(
	array('match' => '/roku/i', 'vendor' => 'Roku', 'tag' => 'tv', 'confidence' => 0.9),
	array('match' => '/samsung|tizen/i', 'vendor' => 'Samsung', 'tag' => 'tv', 'confidence' => 0.85),
	array('match' => '/webos|lg electronics|lge /i', 'vendor' => 'LG', 'tag' => 'tv', 'confidence' => 0.85),
	array('match' => '/sonos/i', 'vendor' => 'Sonos', 'tag' => 'tv', 'confidence' => 0.9),
	array('match' => '/dial-multiscreen|mediarenderer|dlnadoc|dmr/i', 'tag' => 'tv', 'confidence' => 0.7),
	array('match' => '/xbox/i', 'vendor' => 'Microsoft', 'model' => 'Xbox', 'tag' => 'gaming', 'confidence' => 0.85),
	array('match' => '/mediaserver|dms\b/i', 'tag' => 'nas', 'confidence' => 0.5),
	array('match' => '/printer|ipp/i', 'tag' => 'printer', 'confidence' => 0.7),
	array('match' => '/internetgatewaydevice|wanconnection|wfawlanconfig|wlanaccesspoint/i', 'tag' => 'network', 'confidence' => 0.7),
	array('match' => '/microsoft-windows|windows nt/i', 'os' => 'Windows', 'tag' => 'pc', 'confidence' => 0.55),
),

/* ============================ DHCP (opt 55/60) ============================ */
'dhcp' => array(
	'opt60' => array(
		'/android-dhcp/i'                  => array('os' => 'Android', 'tag' => 'phone', 'confidence' => 0.7),
		'/MSFT\s*5\.0|MSFT/i'              => array('os' => 'Windows', 'tag' => 'pc', 'confidence' => 0.6),
		'/dhcpcd-/i'                       => array('os' => 'Linux', 'tag' => 'pc', 'confidence' => 0.4),
		'/udhcp /i'                        => array('os' => 'Embedded Linux', 'tag' => 'iot', 'confidence' => 0.4),
		'/Hewlett-Packard|HP\b|HP Inc/i'   => array('vendor' => 'HP', 'tag' => 'printer', 'confidence' => 0.7),
		'/Xerox|Lexmark|Brother|Canon|Kyocera/i' => array('tag' => 'printer', 'confidence' => 0.7),
		'/AmazonFireTV|FireOS|Amazon/i'    => array('vendor' => 'Amazon', 'tag' => 'tv', 'confidence' => 0.7),
		'/Roku/i'                          => array('vendor' => 'Roku', 'tag' => 'tv', 'confidence' => 0.8),
		'/PlayStation|PS4|PS5/i'           => array('vendor' => 'Sony', 'tag' => 'gaming', 'confidence' => 0.8),
		'/XBOX/i'                          => array('vendor' => 'Microsoft', 'tag' => 'gaming', 'confidence' => 0.8),
		'/Cisco|ciscopnp|Aironet/i'        => array('vendor' => 'Cisco', 'tag' => 'network', 'confidence' => 0.7),
		'/Sagemcom|Technicolor|AVM|FRITZ|Sky /i' => array('tag' => 'network', 'confidence' => 0.6),
		'/Hikvision|Dahua|Axis/i'          => array('tag' => 'camera', 'confidence' => 0.7),
		'/Ubiquiti|UniFi/i'                => array('vendor' => 'Ubiquiti', 'tag' => 'network', 'confidence' => 0.75),
	),
	'opt55' => array(
		// well-known Parameter-Request-List sequences (Fingerbank-style; modest confidence)
		'1,121,3,6,15,119,252'                          => array('os' => 'Apple iOS/macOS', 'tag' => 'phone', 'confidence' => 0.55),
		'1,3,6,15,119,95,252,44,46'                     => array('os' => 'Apple macOS', 'tag' => 'pc', 'confidence' => 0.5),
		'1,3,6,15,31,33,43,44,46,47,119,121,249,252'    => array('os' => 'Windows', 'tag' => 'pc', 'confidence' => 0.5),
		'1,33,3,6,15,26,28,51,58,59,43'                 => array('os' => 'Android', 'tag' => 'phone', 'confidence' => 0.5),
	),
),

/* ============================ banners (ssh/telnet/rtsp/tls) ============================ */
'banner' => array(
	array('match' => '/SSH-2\.0-dropbear/i', 'os' => 'Embedded Linux', 'tag' => 'network', 'confidence' => 0.6),
	array('match' => '/SSH-2\.0-OpenSSH[_\-]?([\w.]+)/i', 'os' => 'Linux/Unix', 'tag' => 'pc', 'confidence' => 0.4),
	array('match' => '/RouterOS|MikroTik/i', 'vendor' => 'MikroTik', 'tag' => 'network', 'confidence' => 0.85),
	array('match' => '/RTSP\/1\.0|554:.*RTSP/i', 'tag' => 'camera', 'confidence' => 0.7),
	array('match' => '/9100:.*(PJL|JetDirect|@PJL)/i', 'tag' => 'printer', 'confidence' => 0.8),
	array('match' => '/BusyBox/i', 'os' => 'Embedded Linux', 'tag' => 'iot', 'confidence' => 0.5),
	// TLS certificate subject/issuer hints (subject= and issuer= both captured)
	// Cast-cert ISSUER reveals the device maker — more specific than Widevine.
	array('match' => '/Nvidia[^\n]*Cast/i', 'vendor' => 'NVIDIA', 'model' => 'Shield TV', 'tag' => 'tv', 'confidence' => 0.88),
	array('match' => '/Chromecast ICA|Chromecast[^\n]*Audio/i', 'vendor' => 'Google', 'model' => 'Chromecast', 'tag' => 'tv', 'confidence' => 0.8),
	array('match' => '/O\s*=\s*Google Inc[^\n]*OU\s*=\s*Cast/i', 'vendor' => 'Google', 'model' => 'Cast device', 'tag' => 'tv', 'confidence' => 0.85),
	array('match' => '/OU\s*=\s*Widevine/i', 'vendor' => 'Google', 'tag' => 'tv', 'confidence' => 0.55),
	array('match' => '/O\s*=\s*"?Fibar Group|CN\s*=\s*HC[0-9L]/i', 'vendor' => 'Fibaro', 'model' => 'Home Center', 'tag' => 'network', 'confidence' => 0.85),
	array('match' => '/O\s*=\s*HP\b|O\s*=\s*"?Hewlett[- ]?Packard/i', 'vendor' => 'HP', 'tag' => 'printer', 'confidence' => 0.7),
	array('match' => '/O\s*=\s*Roku/i', 'vendor' => 'Roku', 'tag' => 'tv', 'confidence' => 0.8),
	array('match' => '/O\s*=\s*Sonos/i', 'vendor' => 'Sonos', 'tag' => 'tv', 'confidence' => 0.85),
	array('match' => '/O\s*=\s*(Amazon|Ring)[^\n]*/i', 'vendor' => 'Amazon', 'tag' => 'iot', 'confidence' => 0.6),
	array('match' => '/CN\s*=\s*[^,\/\n]*(Shelly)/i', 'vendor' => 'Shelly', 'tag' => 'iot', 'confidence' => 0.7),
	array('match' => '/O\s*=\s*"?QNAP|CN\s*=\s*[^,\/\n]*QNAP/i', 'vendor' => 'QNAP', 'tag' => 'nas', 'confidence' => 0.8),
	array('match' => '/CN\s*=\s*[^,\/\n]*(Synology|DiskStation)|O\s*=\s*"?Synology/i', 'vendor' => 'Synology', 'tag' => 'nas', 'confidence' => 0.8),
	array('match' => '/CN\s*=\s*[^,\/\n]*(UniFi|UbiquitiNetworks)/i', 'vendor' => 'Ubiquiti', 'tag' => 'network', 'confidence' => 0.7),
	array('match' => '/CN\s*=\s*[^,\/\n]*(Roku)/i', 'vendor' => 'Roku', 'tag' => 'tv', 'confidence' => 0.7),
),

);
