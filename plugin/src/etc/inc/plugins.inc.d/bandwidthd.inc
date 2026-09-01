<?php

/*
 * Copyright (C) 2026 opnsense-bandwidthd contributors
 * Licensed under the Apache License, Version 2.0.
 *
 * Plugin hooks. OPNsense discovers these by filename convention and calls them
 * from plugins_services() / plugins_cron() — there is no registration step.
 */

use OPNsense\Core\Config;

/**
 * Read one setting out of the plugin's model node.
 */
function bandwidthd_setting($name, $default = '0')
{
    $cnf = Config::getInstance()->object();
    if (isset($cnf->OPNsense->Bandwidthd->general->$name)) {
        return (string)$cnf->OPNsense->Bandwidthd->general->$name;
    }
    return $default;
}

function bandwidthd_enabled()
{
    return bandwidthd_setting('enabled') === '1';
}

/**
 * Register the daemon with Services -> ... so it gets start/stop/restart controls
 * and a status light.
 */
function bandwidthd_services()
{
    $services = [];

    if (bandwidthd_enabled()) {
        $services[] = [
            'description' => gettext('BandwidthD traffic accounting'),
            'configd' => [
                'restart' => ['bandwidthd restart'],
                'start' => ['bandwidthd start'],
                'stop' => ['bandwidthd stop'],
            ],
            'name' => 'bandwidthd',
            'pidfile' => '/var/run/bandwidthd.pid',
        ];
    }

    return $services;
}

/**
 * Scheduled work.
 *
 * OPNsense's crontab writer (system_cron_configure) reads ONLY the 'autocron'
 * key, as the positional list [command, minute, hour, monthday, month, weekday].
 * A job described with any other keys is silently dropped — it appears in
 * plugins_cron() and never reaches the crontab.
 *
 * Every entry is gated on its own setting, so turning a feature off in the GUI
 * removes its cron job on the next configuration write rather than leaving a job
 * that runs and immediately exits.
 */
function bandwidthd_cron()
{
    $jobs = [];

    if (!bandwidthd_enabled()) {
        return $jobs;
    }

    /* Rotate the CDF logs daily so one log file cannot grow without bound. */
    $jobs[]['autocron'] = ['/usr/local/sbin/configctl -d bandwidthd rotate', '1', '0'];

    if (bandwidthd_setting('alerts_enable') === '1') {
        $jobs[]['autocron'] = ['/usr/local/sbin/configctl -d bandwidthd alerts', '*/15'];
    }

    if (bandwidthd_setting('report_enable') === '1') {
        /* Daily at 00:05; weekly runs Monday and reports the previous week. */
        $weekly = bandwidthd_setting('report_freq', 'daily') === 'weekly';
        $jobs[]['autocron'] = array_merge(
            ['/usr/local/sbin/configctl -d bandwidthd report', '5', '0'],
            $weekly ? ['*', '*', '1'] : []
        );
    }

    if (bandwidthd_setting('db_enable') === '1') {
        /* flock so a slow export (or an unreachable database) cannot pile runs up.
           Deliberately NOT 'configctl -d': -d detaches and returns immediately, so
           the lock would be released the moment the job was queued rather than
           held for its duration — an overlap guard that guards nothing. */
        $jobs[]['autocron'] = [
            '/usr/local/bin/flock -n -E 0 -o /tmp/bandwidthd_dbexport.lock ' .
            '/usr/local/sbin/configctl bandwidthd dbexport',
            '*/5'
        ];
        $jobs[]['autocron'] = ['/usr/local/sbin/configctl -d bandwidthd dbmaint', '20', '3'];
    }

    if (bandwidthd_setting('probe_enable') === '1') {
        /* Incremental: only devices whose fingerprint is missing or stale. */
        $jobs[]['autocron'] = [
            '/usr/local/bin/flock -n -E 0 -o /tmp/bandwidthd_probe.lock ' .
            '/usr/local/sbin/configctl bandwidthd probe --cron',
            '*/30'
        ];
    }

    return $jobs;
}

/**
 * Syslog facility registration, so the plugin's messages get their own view under
 * System -> Log Files. bwd_notify() logs to LOG_LOCAL4 with the "bandwidthd" tag.
 */
function bandwidthd_syslog()
{
    return [
        'bandwidthd' => [
            'facility' => ['bandwidthd'],
        ],
    ];
}
