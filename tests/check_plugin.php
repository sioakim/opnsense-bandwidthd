<?php
/*
 * tests/check_plugin.php — structural checks on the OPNsense plugin tree.
 *
 * These guard the mistakes that are invisible to php -l and only show up as a
 * broken page (or a silently missing feature) on the box. Run off-box:
 *   php tests/check_plugin.php
 *
 * Licensed under the Apache License, Version 2.0.
 */

$root = dirname(__DIR__);
$src = "$root/plugin/src";
$mvc = "$src/opnsense/mvc/app";
$fail = 0;
$pass = 0;

function ok($cond, $msg) {
	global $fail, $pass;
	if ($cond) { $pass++; return; }
	$fail++;
	fwrite(STDERR, "FAIL: $msg\n");
}

/* 1. Every XML the framework parses must be well-formed — a broken one makes the
 *    page render empty rather than error. */
foreach (glob("$mvc/models/OPNsense/Bandwidthd/{,*/}*.xml", GLOB_BRACE) ?: [] as $f) {
	ok(@simplexml_load_file($f) !== false, 'well-formed XML: ' . basename($f));
}
foreach (glob("$mvc/controllers/OPNsense/Bandwidthd/forms/*.xml") ?: [] as $f) {
	ok(@simplexml_load_file($f) !== false, 'well-formed form: ' . basename($f));
}

/* 2. Every settings-form field id must resolve against the API response the way
 *    OPNsense's setFormData() actually walks it: it splits the id on '.' and
 *    descends the WHOLE response, which is rooted at the controller's
 *    $internalModelName. So a settings field id must be
 *    "<modelName>.<container>.<field>". Omitting that first segment renders a
 *    form where nothing matches: every control is blank, every dropdown is empty,
 *    and nothing errors — it just silently never populates.
 *
 *    A dialog driven by getBase()/setBase() is different: that response is rooted
 *    at the item name, so its ids start there instead. */
$modelName = 'bandwidthd';
$model = simplexml_load_file("$mvc/models/OPNsense/Bandwidthd/Bandwidthd.xml");

/* Walk the model the same way, so "bandwidthd.general.enabled" must resolve. */
$resolve = function (array $parts) use ($model, $modelName) {
    if (array_shift($parts) !== $modelName) {
        return false;
    }
    $node = $model->items;
    foreach ($parts as $part) {
        if (!isset($node->$part)) {
            return false;
        }
        $node = $node->$part;
    }
    return true;
};

$generalForm = simplexml_load_file("$mvc/controllers/OPNsense/Bandwidthd/forms/general.xml");
foreach ($generalForm->field as $field) {
    $id = (string)$field->id;
    if ($id === '') {
        continue;
    }
    ok(strpos($id, $modelName . '.') === 0,
        "settings field id is rooted at the model name: $id");
    ok($resolve(explode('.', $id)), "settings field id resolves in the model: $id");
}

/* The override dialog is fed by getBase('override', ...), so its ids are rooted
 * at 'override' and must match the ArrayField's own fields. */
$overrideFields = [];
foreach ($model->items->overrides->override->children() as $name => $_) {
    $overrideFields[(string)$name] = true;
}
$dlg = simplexml_load_file("$mvc/controllers/OPNsense/Bandwidthd/forms/dialogOverride.xml");
foreach ($dlg->field as $field) {
    $id = (string)$field->id;
    if ($id === '') {
        continue;
    }
    $parts = explode('.', $id);
    ok(count($parts) === 2 && $parts[0] === 'override',
        "override dialog field id is rooted at 'override': $id");
    ok(isset($overrideFields[end($parts)]), "override dialog field exists in the model: $id");
}

/* 3. Every model field should be reachable from a form; an orphan is either dead
 *    config or a control someone forgot to add. */
$formIds = [];
foreach ($generalForm->field as $field) {
    $id = (string)$field->id;
    if (strpos($id, "$modelName.general.") === 0) {
        $formIds[substr($id, strlen("$modelName."))] = true;
    }
}
foreach ($dlg->field as $field) {
    $formIds[(string)$field->id] = true;
}
foreach ($model->items->general->children() as $name => $_) {
    ok(isset($formIds["general.$name"]), "model field is editable somewhere: general.$name");
}
foreach (array_keys($overrideFields) as $name) {
    ok(isset($formIds["override.$name"]), "model field is editable somewhere: override.$name");
}

/* 4. Boolean model fields and the platform layer's boolean list must agree.
 *    A BooleanField missing from that list reads back as a raw "1"/"0"; a numeric
 *    field wrongly in it reads as "on"/"off", and (float)"on" is 0 — which would
 *    silently disable a quota rather than set it. */
$declaredBool = [];
foreach ($model->items->general->children() as $name => $node) {
	if ((string)$node['type'] === 'BooleanField') { $declaredBool[] = (string)$name; }
}
$platform = file_get_contents("$src/opnsense/scripts/OPNsense/Bandwidthd/lib/bwd_platform.inc.php");
preg_match('/function bwd_bool_keys\(\)\s*\{.*?return array\((.*?)\);/s', $platform, $m);
$listed = [];
if (isset($m[1])) {
	preg_match_all("/'([a-z0-9_]+)'/", $m[1], $mm);
	$listed = $mm[1];
}
sort($declaredBool);
sort($listed);
ok($declaredBool === $listed,
	'bwd_bool_keys() matches the model BooleanFields (model: ' . implode(',', array_diff($declaredBool, $listed))
	. ' / list: ' . implode(',', array_diff($listed, $declaredBool)) . ')');

/* 5. The dashboard JS must reach the API through the endpoints the controller
 *    actually defines. */
$js = file_get_contents("$src/www/bandwidthd_ui/js/bandwidthd.js");
$controller = file_get_contents("$mvc/controllers/OPNsense/Bandwidthd/Api/DataController.php");
preg_match_all('/public function (\w+)Action/', $controller, $m);
$actions = array_map('strtolower', $m[1]);
preg_match_all("/action:\s*'(\w+)'/", $js, $m);
foreach (array_unique($m[1]) as $a) {
	ok(in_array(strtolower($a), $actions, true), "JS GET action has a controller method: $a");
}
preg_match('/var POST_EP = \{(.*?)\};/s', $js, $m);
if (isset($m[1])) {
	preg_match_all("/:\s*'(\w+)'/", $m[1], $mm);
	foreach ($mm[1] as $a) {
		ok(in_array(strtolower($a), $actions, true), "JS POST endpoint has a controller method: $a");
	}
}
ok(strpos($js, 'status_bandwidthd.php') === false, 'JS carries no legacy status_bandwidthd.php endpoint');
ok(strpos($js, 'csrfMagic') === false, 'JS carries no csrf-magic reference');

/* 6. Nothing server-side may sit in the web root: the OUI table and the
 *    fingerprint signature DB are data, not assets. */
foreach (glob("$src/www/bandwidthd_ui/*") ?: [] as $d) {
	ok(!is_dir($d) || !in_array(basename($d), ['data'], true),
		'no server-side data under the web root: ' . basename($d));
}
ok(count(glob("$src/www/bandwidthd_ui/*.php")) === 0, 'no PHP under the web root');

/* 7. configd actions referenced by the code must be declared. */
$actionsConf = file_get_contents("$src/opnsense/service/conf/actions.d/actions_bandwidthd.conf");
preg_match_all('/^\[(\w+)\]/m', $actionsConf, $m);
$declared = $m[1];
foreach (['start', 'stop', 'restart', 'status', 'configure', 'alerts', 'report', 'dbexport', 'dbmaint', 'dbtest', 'probe', 'rotate'] as $a) {
	ok(in_array($a, $declared, true), "configd action declared: $a");
}
$hooks = file_get_contents("$src/etc/inc/plugins.inc.d/bandwidthd.inc");
preg_match_all('/configctl -d bandwidthd (\w+)/', $hooks, $m);
foreach (array_unique($m[1]) as $a) {
	ok(in_array($a, $declared, true), "cron hook uses a declared configd action: $a");
}

/* OPNsense's crontab writer reads ONLY the 'autocron' key, as a positional
 * [command, minute, hour, ...] list. A job described with 'minutes'/'hours' keys
 * shows up in plugins_cron() and is then silently dropped before the crontab —
 * the failure looks exactly like a working schedule that simply never fires. */
preg_match('/function bandwidthd_cron\(\).*?\n\}/s', $hooks, $m);
$cronFn = $m[0] ?? '';
ok($cronFn !== '', 'bandwidthd_cron() is present');
ok(substr_count($cronFn, "['autocron']") >= 1, 'cron jobs use the autocron key');
foreach (['minutes', 'hours', 'weekdays'] as $badKey) {
	ok(strpos($cronFn, "'$badKey'") === false,
		"cron jobs avoid the '$badKey' key the crontab writer ignores");
}

/* 8. Each script a configd action invokes must exist. */
preg_match_all('#/usr/local/opnsense/scripts/OPNsense/Bandwidthd/(\w+\.php)#', $actionsConf, $m);
foreach (array_unique($m[1]) as $s) {
	ok(is_file("$src/opnsense/scripts/OPNsense/Bandwidthd/$s"), "configd action target exists: $s");
}

/* 8b. The read-only Status role must not reach a write endpoint. A wildcard over
 *     api/bandwidthd/data/* silently includes setOverride, renameTag, deleteTag
 *     and probe, so a dashboard-only user could rewrite overrides, wipe every
 *     custom tag, or trigger active LAN scans. */
$aclXml = simplexml_load_file("$mvc/models/OPNsense/Bandwidthd/ACL/ACL.xml");
$writeActions = [];
foreach (['setOverride', 'renameTag', 'deleteTag', 'probe'] as $a) {
    $writeActions[] = "api/bandwidthd/data/$a";
}
$statusPatterns = [];
foreach ($aclXml->{'page-status-bandwidthd'}->patterns->pattern as $pat) {
    $statusPatterns[] = (string)$pat;
}
ok(!empty($statusPatterns), 'the status ACL declares patterns');
/* Port of OPNsense\Core\ACL::urlMatch(): an anchored regex over the FULL request
 * URI, query string included — not a glob over the path. A bare pattern with no
 * trailing * never matches a call that carries parameters, which is how the
 * Status role once got a dashboard whose every fetch was denied while this test
 * passed with fnmatch(). */
function bwd_test_url_match($url, $mask) {
    $match = str_replace(['.', '*', '?'], ['\.', '.*', '\?'], $mask);
    $match = preg_replace('@([/&?])\.\*$@', '($1.*)?', $match);
    $url = preg_replace('@#.*$@', '', $url);
    return preg_match("@^/{$match}$@", $url) === 1;
}
function bwd_test_acl_grants(array $patterns, $uri) {
    foreach ($patterns as $pat) {
        if (bwd_test_url_match($uri, $pat)) {
            return true;
        }
    }
    return false;
}
foreach ($writeActions as $w) {
    foreach (["/$w", "/$w?mac=00:11:22:33:44:55", "/$w/"] as $uri) {
        ok(!bwd_test_acl_grants($statusPatterns, $uri), "read-only status ACL does not grant the write endpoint: $uri");
    }
}
/* And it must still grant everything the dashboard actually reads — as the JS
 * requests it, with the query string the framework matches against. */
foreach (['hosts', 'series', 'percentile', 'daily', 'overview', 'tags', 'status', 'override', 'export'] as $a) {
    foreach (["/api/bandwidthd/data/$a", "/api/bandwidthd/data/$a?period=1&from=0&to=0&mac=aa:bb"] as $uri) {
        ok(bwd_test_acl_grants($statusPatterns, $uri), "status ACL grants the read endpoint the dashboard needs: $uri");
    }
}
ok(bwd_test_acl_grants($statusPatterns, '/api/bandwidthd/service/status'), 'status ACL grants the service status read');
ok(bwd_test_acl_grants($statusPatterns, '/ui/bandwidthd/dashboard'), 'status ACL grants the dashboard page');
ok(!bwd_test_acl_grants($statusPatterns, '/ui/bandwidthd/general'), 'status ACL does not grant the settings page');

/* 8c. A download action must RETURN its body. Calling $this->response->send()
 *     sends it, the framework sends again, throws "Response Already Sent", and
 *     its error handler appends {"errorMessage":...} to the file the browser is
 *     saving — a corrupt download that still reports HTTP 200. */
foreach (glob("$mvc/controllers/OPNsense/Bandwidthd/Api/*.php") ?: [] as $f) {
	/* Strip comments first — the fix's own explanation names the call. */
	$code = '';
	foreach (token_get_all(file_get_contents($f)) as $tok) {
		if (is_array($tok) && in_array($tok[0], [T_COMMENT, T_DOC_COMMENT], true)) {
			continue;
		}
		$code .= is_array($tok) ? $tok[1] : $tok;
	}
	ok(strpos($code, '$this->response->send()') === false,
		'no API action calls response->send() (double-send corrupts downloads): ' . basename($f));
}

/* 8d. Functions that do not exist on OPNsense must never be called. @ does NOT suppress an
 *     undefined-function Error, so one of these on a rarely-taken branch is a hard
 *     fatal that normal testing misses entirely — get_interface_subnet() sat on the
 *     dynamic-interface fallback and only fired for a dhcp/pppoe interface.
 *     Note find_interface_subnet/find_interface_ip do not exist either, despite
 *     sounding like the OPNsense equivalents. */
$notOnOpnsense = [
    'get_interface_subnet', 'find_interface_subnet', 'find_interface_ip',
    'config_get_path', 'config_set_path', 'config_del_path',
    'install_cron_job', 'write_rcfile', 'file_notice',
    'convert_friendly_interface_to_real_interface_name',
];
$phpTree = array_merge(
    glob("$src/opnsense/scripts/OPNsense/Bandwidthd/lib/*.php") ?: [],
    glob("$src/opnsense/scripts/OPNsense/Bandwidthd/*.php") ?: [],
    glob("$mvc/controllers/OPNsense/Bandwidthd/Api/*.php") ?: [],
    glob("$mvc/controllers/OPNsense/Bandwidthd/*.php") ?: [],
    glob("$src/etc/inc/plugins.inc.d/*.inc") ?: []
);
foreach ($phpTree as $f) {
    /* Comments legitimately name these while explaining why they are gone. */
    $code = '';
    foreach (token_get_all(file_get_contents($f)) as $tok) {
        if (is_array($tok) && in_array($tok[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        $code .= is_array($tok) ? $tok[1] : $tok;
    }
    foreach ($notOnOpnsense as $fn) {
        ok(!preg_match('/(?<![\w$>])' . preg_quote($fn, '/') . '\s*\(/', $code),
            "no call to the non-existent $fn() in " . basename($f));
    }
}

/* 8e. Packaging/integration contracts that only fail on a reboot, an uninstall, or
 *     someone else's plugin install — none of which normal testing exercises. */
$installSh = file_get_contents("$root/scripts/install.sh");
$deploySh = file_get_contents("$root/scripts/deploy-dev.sh");
$manifest = json_decode(file_get_contents("$root/plugin/manifest/MANIFEST.in.json"), true);
$targets = file_get_contents("$src/opnsense/service/templates/OPNsense/Bandwidthd/+TARGETS");

/* rc.conf.d must land in /etc/rc.conf.d: the boot path (/usr/local/etc/rc.freebsd)
   reads only /etc/rc.conf, /etc/rc.conf.local and /etc/rc.conf.d, so a file under
   /usr/local/etc/rc.conf.d leaves the service disabled at boot while every manual
   start still works. */
ok(strpos($targets, ':/etc/rc.conf.d/') !== false,
    '+TARGETS renders rc.conf.d into /etc/rc.conf.d (the path the boot reads)');
ok(strpos($targets, ':/usr/local/etc/rc.conf.d/') === false,
    '+TARGETS does not use /usr/local/etc/rc.conf.d (never read at boot)');

/* The service template must be rendered at install time. It is otherwise only
   written on a settings save, so a box installed and rebooted before the first
   Save comes up with no /etc/rc.conf.d/bandwidthd and the daemon disabled. */
ok(strpos($installSh, 'template reload OPNsense/Bandwidthd') !== false,
    'install.sh renders the service template (boot enable exists immediately)');
ok(strpos($manifest['scripts']['post-install'] ?? '', 'template reload OPNsense/Bandwidthd') !== false,
    'post-install renders the service template');
/* pre-deinstall stops the daemon; on an upgrade only post-install can start it
 * again, otherwise capture silently stays down until a reboot. */
ok(strpos($manifest['scripts']['post-install'] ?? '', '/usr/local/etc/rc.d/bandwidthd start') !== false,
    'post-install starts the daemon again after an upgrade');

/* pkg add -A marks the package automatic, and OPNsense's firmware tooling runs
   pkg autoremove; nothing depends on this plugin, so it would be deleted the next
   time any other plugin is installed or removed. */
/* Catch it wherever the flag is written — inline on the pkg add line, or (as it
   was) assembled into a variable first. */
ok(!preg_match('/pkg add\s[^\n]*-[A-Za-z]*A\b/', $installSh),
    'install.sh does not pass -A to pkg add directly');
ok(!preg_match('/ADD_FLAGS=("|\')?-[A-Za-z]*A\b/', $installSh),
    'install.sh does not build an -A flag for pkg add (autoremove would delete the plugin)');
ok(strpos($installSh, 'pkg set -y -A 0') !== false,
    'install.sh clears the automatic flag explicitly (covers upgrades over an old -A install)');

/* php-fpm does not exist on OPNsense (lighttpd + php-cgi); the lever is webgui. */
foreach (['scripts/install.sh' => $installSh, 'scripts/deploy-dev.sh' => $deploySh] as $name => $body) {
    ok(strpos($body, 'rc.d/php-fpm') === false, "$name does not restart a nonexistent php-fpm");
}
foreach (($manifest['scripts'] ?? []) as $hook => $body) {
    ok(strpos($body, 'rc.d/php-fpm') === false, "manifest $hook does not restart a nonexistent php-fpm");
}

/* pkg removes the package's files BEFORE post-deinstall, so stopping a daemon via
   a rc script this package owns has to happen in pre-deinstall. */
ok(isset($manifest['scripts']['pre-deinstall']), 'manifest has a pre-deinstall hook');
ok(strpos($manifest['scripts']['pre-deinstall'] ?? '', 'bandwidthd onestop') !== false,
    'the daemon is stopped in pre-deinstall, while its rc script still exists');
ok(strpos($manifest['scripts']['post-deinstall'] ?? '', 'onestop') === false,
    'post-deinstall does not try to stop via an already-removed rc script');
ok(strpos($manifest['scripts']['post-deinstall'] ?? '', 'system_cron_configure') !== false,
    'post-deinstall regenerates cron so stale configctl entries are dropped');

/* plugins_configure() reaches core hooks that call log_msg()/exec_safe(), so the
   short "require plugins.inc" form fatals — and every caller swallows it with
   `|| true`, leaving the crontab silently unwritten. Any caller must load the
   full legacy bootstrap. */
$cronCallers = ['scripts/install.sh' => $installSh];
foreach (($manifest['scripts'] ?? []) as $hook => $body) {
    $cronCallers["manifest $hook"] = $body;
}
$cronCallers['bwd_platform.inc.php'] = $platform;

/* Comments legitimately name the wrong call while explaining why it is wrong, so
   compare against code only. */
$stripComments = function ($body, $isPhp) {
    if ($isPhp) {
        $out = '';
        foreach (token_get_all($body) as $tok) {
            if (is_array($tok) && in_array($tok[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $out .= is_array($tok) ? $tok[1] : $tok;
        }
        return $out;
    }
    return preg_replace('/^\s*#.*$/m', '', $body);
};

foreach ($cronCallers as $name => $body) {
    $code = $stripComments($body, substr($name, -4) === '.php');
    ok(strpos($code, "plugins_configure('cron')") === false
        && strpos($code, 'plugins_configure("cron")') === false,
        "$name does not call plugins_configure('cron') — not a plugin hook, so it is a silent no-op");
    $body = $code;
    if (strpos($body, 'system_cron_configure') === false) {
        continue;
    }
    foreach (['config.inc', 'util.inc', 'legacy_bindings.inc', 'system.inc', 'interfaces.inc'] as $inc) {
        ok(strpos($body, $inc) !== false,
            "$name loads $inc before system_cron_configure (it fatals inside core otherwise)");
    }
}

/* flock around `configctl -d` guards nothing: -d detaches and returns at once. */
ok(!preg_match('/flock[^\n\']*configctl\s+-d/', $hooks),
    'no cron job wraps flock around a detached (configctl -d) call');
ok(strpos(file_get_contents("$src/opnsense/scripts/OPNsense/Bandwidthd/probe.php"), 'flock(') !== false,
    'probe.php holds its own run lock rather than relying on the cron wrapper');

/* 8f. The dashboard is meant to look native. These hold that in place, because a
 *     regression here is invisible to every other check — the page still works,
 *     it just stops belonging to the interface around it. */
$dashVolt = file_get_contents("$mvc/views/OPNsense/Bandwidthd/dashboard.volt");
$dashCss = file_get_contents("$src/www/bandwidthd_ui/css/bandwidthd.css");
$dashJs = file_get_contents("$src/www/bandwidthd_ui/js/bandwidthd.js");

ok(substr_count($dashVolt, 'content-box') >= 4,
    'dashboard panels use OPNsense content-box chrome');
ok(strpos($dashVolt, 'col-xs-12') !== false,
    'dashboard lays out on the Bootstrap grid OPNsense uses');

/* Inheriting the theme's face is the whole point; a bundled font undoes it. */
ok(strpos($dashCss, '@font-face') === false,
    'dashboard CSS ships no font of its own (it inherits the theme face)');
ok(!is_dir("$src/www/bandwidthd_ui/fonts"),
    'no vendored font directory remains in the web root');

/* Themes are whole-stylesheet swaps with no marker, so a media query cannot see
   them. A prefers-color-scheme rule here would be a silent no-op that looks like
   dark-mode support. */
ok(strpos($dashCss, 'prefers-color-scheme') === false,
    'dashboard CSS does not branch on prefers-color-scheme (themes are server-swapped)');

/* The UA's [hidden]{display:none} has zero specificity, so ANY author display
 * declaration silently defeats it. Several panels here set display:flex and are
 * toggled purely via el.hidden, so without this rule they render permanently —
 * which is exactly what happened to the Custom date-range row. */
ok(preg_match('/\.bwd-app\s*\[hidden\]\s*\{[^}]*display:\s*none\s*!important/', $dashCss) === 1,
    'the stylesheet neutralises [hidden] against its own display rules');

/* Muted text must derive from the theme's ink. No fixed grey clears AA against
 * both themes: #7f7f7f is 4.00:1 on white and 4.68:1 on #101218, and the best
 * midpoint still only reaches 4.30:1. */
ok(strpos($dashCss, 'color-mix(in srgb, currentColor') !== false,
    'muted text is derived from the theme ink, not a fixed grey');

/* The chart palette is core's own. */
foreach (['#1f77b4', '#ff7f0e', '#2ca02c'] as $c) {
    ok(stripos($dashJs, $c) !== false, "chart palette keeps the Classic10 entry $c");
}
ok(strpos($dashCss, '#C03E14') !== false || stripos($dashCss, '#c03e14') !== false,
    'the OPNsense brand colour is the accent');

/* 8g. Probe-engine invariants. */
$fp = file_get_contents("$src/opnsense/scripts/OPNsense/Bandwidthd/lib/bwd_fingerprint.inc.php");
$setup = file_get_contents("$src/opnsense/scripts/OPNsense/Bandwidthd/setup.php");

/* The probe scope must be what is monitored, not all of RFC1918: the wider gate
   turns the probe privilege into an nmap primitive against any private host the
   firewall can route to. */
preg_match('/function bwd_fp_allowed_cidrs\(\).*?\n\}/s', $fp, $m);
$gate = $m[0] ?? '';
ok($gate !== '', 'bwd_fp_allowed_cidrs() is present');
foreach (['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16'] as $blanket) {
    ok(strpos($gate, "'$blanket'") === false,
        "probe scope does not blanket-allow $blanket (monitored subnets only)");
}

/* An unbounded response must be cut off at the pipe: shell_exec buffers the lot,
   so a substr() afterwards is far too late to protect memory. */
ok(strpos($fp, 'head -c') !== false,
    'the HTTP collector bounds its read at the pipe, not after buffering');

/* Clearing the auto-off clock when probing is disabled; without it, re-enabling
   probing immediately re-disables it. */
ok(strpos($setup, 'probe_state.json') !== false,
    'the probe auto-off clock is reset when probing is disabled');

/* 9. A plugin that registers a Menu.xml or ACL.xml must flush the GUI cache on
 *    install, or it lands correctly on disk and is simply absent from the
 *    navigation — nothing errors, and the pages work if you type the URL.
 *    'pluginctl -c <name>' silently succeeds for ANY name, so a wrong hook name
 *    is a no-op that looks like it ran. The hook is 'cache_flush'. */
$installers = [
    'scripts/install.sh' => file_get_contents("$root/scripts/install.sh"),
    'scripts/deploy-dev.sh' => file_get_contents("$root/scripts/deploy-dev.sh"),
    'package post-install' => file_get_contents("$root/plugin/manifest/MANIFEST.in.json"),
];
$hasMenu = is_file("$mvc/models/OPNsense/Bandwidthd/Menu/Menu.xml");
foreach ($installers as $name => $body) {
    ok(!$hasMenu || strpos($body, 'pluginctl -c cache_flush') !== false,
        "$name flushes the GUI menu/ACL cache");
    preg_match_all('/pluginctl -c (\w+)/', $body, $m);
    foreach (array_unique($m[1]) as $hook) {
        ok($hook === 'cache_flush', "$name uses a real pluginctl hook, not '$hook'");
    }
}

/* Menu entries must point at routes a controller actually serves. */
$menu = simplexml_load_file("$mvc/models/OPNsense/Bandwidthd/Menu/Menu.xml");
foreach (['//@url'] as $xp) {
    foreach ($menu->xpath($xp) ?: [] as $u) {
        $url = (string)$u;
        if (strpos($url, '/ui/bandwidthd/') !== 0) {
            continue;
        }
        $ctrl = ucfirst(explode('/', substr($url, strlen('/ui/bandwidthd/')))[0]);
        ok(is_file("$mvc/controllers/OPNsense/Bandwidthd/{$ctrl}Controller.php"),
            "menu url has a controller: $url -> {$ctrl}Controller");
    }
}

/* 9. No function may be defined twice across the shared libs and the scripts that
 *    include them: PHP fatals on the redeclare, and the port hit exactly this when
 *    a script's own helper collided with the platform layer's (worse, its body had
 *    become an infinite self-call). Only a run of the script catches it otherwise. */
$defs = [];
$phpFiles = array_merge(
    glob("$src/opnsense/scripts/OPNsense/Bandwidthd/lib/*.php") ?: [],
    glob("$src/opnsense/scripts/OPNsense/Bandwidthd/*.php") ?: []
);
foreach ($phpFiles as $f) {
    preg_match_all('/^\s*function\s+(\w+)\s*\(/m', file_get_contents($f), $m);
    foreach ($m[1] as $fn) { $defs[$fn][] = basename($f); }
}
foreach ($defs as $fn => $files) {
    ok(count($files) === 1, "function defined once: $fn (" . implode(', ', $files) . ')');
}

/* 10. A function must not call itself as its entire body — the shape the port's
 *     mechanical rewrite produced when a local helper shared the platform's name. */
foreach ($phpFiles as $f) {
    $body = file_get_contents($f);
    preg_match_all('/function\s+(\w+)\s*\([^)]*\)\s*\{\s*\$\w+\s*=\s*(\w+)\s*\(/m', $body, $m, PREG_SET_ORDER);
    foreach ($m as $hit) {
        ok($hit[1] !== $hit[2], "no self-recursive one-liner: {$hit[1]}() in " . basename($f));
    }
}

/* 9. The package stage roots must contain only usr/, since gen_plist walks the
 *    whole tree and would otherwise install a stray file at /. */
foreach (glob("$root/daemon/prebuilt/*") ?: [] as $e) {
	ok(basename($e) === 'usr', 'daemon stage root holds only usr/: ' . basename($e));
}

/* 11. This repository is public. The harness fixtures are captures from a real
 *     network and must stay anonymised: every address in TEST-NET-1
 *     (192.0.2.0/24), never an RFC 1918 one — a series key was once missed while
 *     the summary beside it was scrubbed. CLAUDE.local.md holds the deployment
 *     details and must stay ignored. */
foreach (glob("$root/tests/harness/*.json") ?: [] as $f) {
	$hits = [];
	preg_match_all('/\b(?:10\.\d{1,3}|192\.168|172\.(?:1[6-9]|2\d|3[01]))\.\d{1,3}\.\d{1,3}\b/', file_get_contents($f), $hits);
	ok(count($hits[0]) === 0,
		'harness fixture carries no private (RFC 1918) address: ' . basename($f)
		. ($hits[0] ? ' (' . implode(', ', array_unique($hits[0])) . ')' : ''));
}
ok(in_array('CLAUDE.local.md', array_map('trim', file("$root/.gitignore")), true),
    '.gitignore excludes CLAUDE.local.md');

/* 11b. The alerts job is the only writer of the rollup the report reads, and the
 *      per-device "alerts: on" override needs it while the global switch is off.
 *      Gating the job on alerts_enable alone shipped an empty daily report. */
$hookSrc = file_get_contents("$src/etc/inc/plugins.inc.d/bandwidthd.inc");
ok(preg_match('/if \(bandwidthd_rollup_wanted\(\)\)\s*\{[^}]*bandwidthd alerts/s', $hookSrc) === 1,
    'the alerts cron job is gated on bandwidthd_rollup_wanted(), not alerts_enable alone');
ok(preg_match('/function bandwidthd_rollup_wanted\(\).*?report_enable.*?alerts_enable.*?\n\}/s', $hookSrc) === 1,
    'bandwidthd_rollup_wanted() considers report_enable and the per-device alerts override');
/* 11c. The prebuilt daemon binary is a shipped artefact: a changed checksum is a
 *      deliberate, reviewed rebuild, never a silent one. */
$binSha = hash_file('sha256', "$root/daemon/prebuilt/usr/local/bandwidthd/bandwidthd");
ok(strpos(file_get_contents("$root/daemon/README.md"), $binSha) !== false,
    'daemon/README.md records the sha256 of the committed bandwidthd binary');

/* 12. The package repository under repo/: the fingerprint clients install must be the
 *     sha256 of the committed public key (that is what pkg(8) checks a signed
 *     catalogue against), and the repo config must name that fingerprint directory. */
$fpFile = file_get_contents("$root/repo/fingerprints/trusted/bandwidthd");
ok(preg_match('/^function: sha256\nfingerprint: ([0-9a-f]{64})\n$/', $fpFile, $fpm) === 1,
    'repo fingerprint file has the pkg(8) shape');
ok(isset($fpm[1]) && $fpm[1] === hash_file('sha256', "$root/repo/bandwidthd.pub"),
    'repo fingerprint is the sha256 of repo/bandwidthd.pub');
$repoConf = file_get_contents("$root/repo/bandwidthd.conf");
ok(strpos($repoConf, 'fingerprints: "/usr/local/etc/pkg/fingerprints/bandwidthd"') !== false,
    'repo config points at the bandwidthd fingerprint directory');
ok(strpos($repoConf, 'signature_type: "fingerprints"') !== false, 'repo config requires signatures');
ok(strpos(file_get_contents("$root/repo/install-repo.sh"), '/usr/local/etc/pkg/fingerprints/bandwidthd/trusted') !== false,
    'installer writes the fingerprint where the repo config looks for it');

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
