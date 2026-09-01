<?php
/*
 * vendor_tags.php — curated vendor -> usage-tag heuristics for bwd_vendor_tag().
 *
 * The IEEE OUI registry only identifies a VENDOR, never a device *type*. This
 * is a best-effort, human-maintained mapping from vendor-name patterns to a
 * coarse usage class. It is intentionally approximate (a single vendor ships
 * many device classes) and is always overridable per device in the UI.
 *
 * Returns an ORDERED list of [case-insensitive regex, tag]; first match wins,
 * so put more-specific patterns before broader ones. Tags are free-form but
 * the UI knows these: pc, phone, tablet, tv, iot, camera, printer, network,
 * voip, gaming, nas, appliance.
 *
 * Licensed under the Apache License, Version 2.0.
 */
return array(
	// --- network gear (routers/APs/switches) ---
	array('/\b(ubiquiti|mikrotik|tp-?link|netgear|d-?link|aruba|ruckus|zyxel|juniper|arista|fortinet|cambium|edgecore)\b/i', 'network'),
	array('/\bcisco\b/i', 'network'),

	// --- IP cameras / NVRs ---
	array('/\b(hikvision|dahua|axis communications|reolink|amcrest|lorex|wyze|uniview|vivotek|hanwha)\b/i', 'camera'),

	// --- printers ---
	array('/\b(brother|lexmark|kyocera|zebra|epson seiko|seiko epson)\b/i', 'printer'),
	array('/\bhp\b.*\b(print|laserjet|officejet)\b/i', 'printer'),

	// --- VoIP phones ---
	array('/\b(polycom|yealink|grandstream|snom|sangoma|avaya|mitel)\b/i', 'voip'),

	// --- gaming consoles ---
	array('/\b(nintendo|sony interactive|playstation|microsoft).*\b(game|xbox)?\b/i', 'gaming'),
	array('/\b(nintendo|valve)\b/i', 'gaming'),

	// --- TVs / streaming / media ---
	array('/\b(roku|vizio|tcl|sceptre|sonos|harman)\b/i', 'tv'),
	array('/\bamazon\b/i', 'iot'),            // Echo/Fire/Ring — mostly IoT/streaming
	array('/\bgoogle\b/i', 'iot'),            // Nest/Chromecast/Home

	// --- IoT / smart-home silicon & brands ---
	array('/\b(espressif|tuya|sonoff|itead|shelly|allterco|tasmota|particle|sensortec|nordic semiconductor|texas instruments)\b/i', 'iot'),
	array('/\b(philips|signify|lifx|wemo|belkin|ecobee|honeywell|rachio|wiz)\b/i', 'iot'),
	array('/\b(raspberry pi|arduino|seeed|adafruit)\b/i', 'iot'),

	// --- NAS / storage ---
	array('/\b(synology|qnap|western digital|wd|seagate|drobo)\b/i', 'nas'),

	// --- phones / tablets / mobile-leaning vendors ---
	array('/\b(xiaomi|oppo|vivo|oneplus|realme|huawei|honor|motorola mobility|nokia mobile)\b/i', 'phone'),

	// --- broad computer/SoC vendors (kept LAST: very generic) ---
	array('/\bapple\b/i', 'pc'),              // Mac/iPhone/iPad — ambiguous; default pc
	array('/\b(dell|lenovo|asus(tek)?|acer|micro-?star|msi|gigabyte|hewlett|hp inc|intel|realtek|microsoft)\b/i', 'pc'),
	array('/\bsamsung\b/i', 'phone'),         // mostly handsets on a LAN
);
