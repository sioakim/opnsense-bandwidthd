<?php
/*
 * hostname_tags.php — curated device-hostname -> usage-tag heuristics (#11).
 *
 * The DHCP client-hostname / DNS name a device reports is the single best
 * passive disambiguator of device *type* — it survives MAC randomization and
 * tells iPhone from Mac, Chromecast from Pixel, where the OUI vendor cannot.
 * Used by bwd_classify() as a high-weight signal, above the (often ambiguous)
 * vendor heuristic.
 *
 * Ordered list of [case-insensitive regex, tag]; FIRST MATCH WINS, so put the
 * most specific patterns first. Match is against the resolved host/DNS name.
 * Tags the UI knows: pc, phone, tablet, tv, iot, camera, printer, network,
 * voip, gaming, nas, appliance.
 *
 * Licensed under the Apache License, Version 2.0.
 */
return array(
	// --- brand-specific (optional 3rd element = vendor, shown instead of the OUI
	//     silicon maker; placed first so the brand + its tag both win) ---
	array('/(\bshelly|^sh(\d|plug|dim|sw|plus|rgbw|pro|em|ht|dw|uni|btn|bulb|vintage|vin|motion|gas|ix|pg|pl\d|i\d))/i', 'iot', 'Shelly'),
	array('/\btasmota/i', 'iot', 'Tasmota'),
	array('/\besphome/i', 'iot', 'ESPHome'),
	array('/(philips-?hue|\bhue-?bridge)/i', 'iot', 'Philips Hue'),
	array('/\bsonos/i', 'tv', 'Sonos'),
	array('/(chromecast|google-?(nest|home|cast)|nest-?(mini|hub|audio))/i', 'tv', 'Google'),
	array('/(\becho\b|alexa|amazon-?(echo|dot|show))/i', 'iot', 'Amazon'),
	array('/(reolink|amcrest|\bwyze)/i', 'camera', 'Reolink'),
	array('/(hikvision|\bhik-)/i', 'camera', 'Hikvision'),
	array('/\bdahua/i', 'camera', 'Dahua'),
	array('/(synology|diskstation|\bds\d{3})/i', 'nas', 'Synology'),
	array('/(qnap|\bqts\b)/i', 'nas', 'QNAP'),
	array('/(unifi|\budm\b|\buap\b|\busw\b|ubiquiti)/i', 'network', 'Ubiquiti'),
	array('/\bmikrotik/i', 'network', 'MikroTik'),
	array('/\bdreame/i', 'appliance', 'Dreame'),
	array('/\broborock/i', 'appliance', 'Roborock'),
	array('/\broomba/i', 'appliance', 'iRobot'),
	array('/(robovac|\bvacuum)/i', 'appliance', ''),
	array('/\btado\b/i', 'iot', 'tado'),
	array('/\bfibaro|\bhc[23]l?\b/i', 'network', 'Fibaro'),
	array('/readynas/i', 'nas', 'NETGEAR'),

	// --- TV / streaming (before phone/pc: "AppleTV" must beat "apple") ---
	array('/(apple-?tv|\bfiretv|fire-?tv|chromecast|\bshield\b|androidtv|android-?tv|roku|bravia|aquos|\bvizio|hisense|samsung-?tv|lg-?webos|webostv|\bnvidia-?shield)/i', 'tv'),

	// --- gaming consoles (before a bare "switch" could read as network) ---
	array('/(xbox|playstation|\bps[45]\b|nintendo|\bswitch\b|steam-?deck|\bvalve\b)/i', 'gaming'),

	// --- printers ---
	array('/(officejet|laserjet|deskjet|envy\d|pixma|imageclass|workforce|ecotank|\bmfc-|\bdcp-|\bmg\d{3,}|brother|\bcanon\b|kyocera|\bricoh\b|magicolor|phaser)/i', 'printer'),

	// --- IP cameras / doorbells ---
	array('/(\bcam\b|camera|doorbell|reolink|hikvision|dahua|\bipcam|wyzecam|amcrest|\blorex|nestcam|nest-?cam|frigate|blink-)/i', 'camera'),

	// --- NAS / storage ---
	array('/(\bnas\b|synology|diskstation|\bds\d{3,}|\brs\d{3,}|qnap|truenas|freenas|unraid|terramaster)/i', 'nas'),

	// --- VoIP handsets ---
	array('/(\bvoip\b|polycom|yealink|grandstream|\bsnom\b|\bsip-|\bobihai|cisco-?spa|fanvil)/i', 'voip'),

	// --- network gear ---
	array('/(unifi|\budm\b|\buap\b|\busw\b|\busg\b|mikrotik|openwrt|edgerouter|edgeswitch|\bpfsense|\bopnsense|\bgateway\b|access-?point|\bap-?\d|wifi-?ap|omada|\beero\b|\bnest-?wifi)/i', 'network'),

	// --- tablets (before phone) ---
	array('/(ipad|\bsm-t\d|galaxy-?tab|\btab-?[as]\d|kindle|fire-?hd|nexus-?(7|9|10)|surface-?(go|pro))/i', 'tablet'),

	// --- phones ---
	array('/(iphone|\bsm-[gan]\d|galaxy-?[snamz]\d|\bpixel-?\d|\bpixel\b|redmi|\bmi-?\d{1,2}\b|poco-?[fxm]|oneplus|\bop-?\d|realme|moto-?[geztx]|nokia-?\d|huawei-?p|honor-?\d|android-[0-9a-f]{6,}|-?phone\b)/i', 'phone'),

	// --- PCs / laptops ---
	array('/(macbook|imac|mac-?mini|mac-?pro|mac-?studio|\bmbp\b|\bmba\b|desktop|laptop|thinkpad|ideapad|latitude|optiplex|inspiron|\bxps\d|elitebook|probook|\bspectre|surface-?(book|laptop|\d)|zenbook|vivobook|\brog-|legion|\bpc\b|\bwin(dows)?-|\bnb-|notebook|chromebook)/i', 'pc'),

	// --- IoT / smart-home (broad; kept late) ---
	array('/(shelly|\besp-?(32|8266)?\b|tasmota|sonoff|tuya|smartplug|smart-?(plug|switch|bulb|life)|wled|\bhue\b|\blifx|kasa|wemo|\becho\b|alexa|google-?home|googlehome|homepod|nest-|ecobee|thermostat|\bhvac\b|switchbot|\bring-|\bwyze|roomba|\btado|\bairgradient|\bsensor\b|\bplug-?\d|\bbulb\b|meross|govee|sengled|\bzigbee|\bz-?wave)/i', 'iot'),

	// --- printers/appliances misc fallback (smart appliances) ---
	array('/(refrigerator|fridge|\bdishwasher|\bwasher\b|\bdryer\b|\boven\b|\bmicrowave|smart-?tv-?stick)/i', 'appliance'),
);
