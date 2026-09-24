#!/usr/local/bin/php
<?php
/*
 * cron.php — rewrite the crontab so it matches the current settings.
 *
 * The plugin's jobs come from bandwidthd_cron(), which is derived from the
 * settings; this makes the crontab catch up after a settings save.
 *
 * Licensed under the Apache License, Version 2.0.
 */

require_once(__DIR__ . '/lib/bwd_platform.inc.php');

exit(bwd_reload_cron() ? 0 : 1);
