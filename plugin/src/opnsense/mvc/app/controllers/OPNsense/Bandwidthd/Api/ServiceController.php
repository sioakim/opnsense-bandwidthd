<?php

/*
 * Copyright (C) 2026 opnsense-bandwidthd contributors
 * Licensed under the Apache License, Version 2.0.
 */

namespace OPNsense\Bandwidthd\Api;

use OPNsense\Base\ApiMutableServiceControllerBase;
use OPNsense\Core\Backend;

/**
 * start / stop / restart / reconfigure / status for the bandwidthd daemon.
 */
class ServiceController extends ApiMutableServiceControllerBase
{
    protected static $internalServiceClass = 'OPNsense\Bandwidthd\Bandwidthd';
    protected static $internalServiceTemplate = 'OPNsense/Bandwidthd';
    protected static $internalServiceEnabled = 'general.enabled';
    protected static $internalServiceName = 'bandwidthd';

    /**
     * The settings page's Save calls this. Cron jobs are derived from the
     * settings (bandwidthd_cron()), but nothing in the stock reconfigure path
     * rewrites the crontab, so a feature switched on or off kept its old schedule
     * until the next package install.
     */
    public function reconfigureAction()
    {
        $result = parent::reconfigureAction();
        if ($this->request->isPost() && ($result['status'] ?? '') === 'ok') {
            $cron = trim((string) (new Backend())->configdRun('bandwidthd cron'));
            if ($cron !== 'OK') {
                $result['status'] = 'failed';
                $result['message'] = 'Cron regeneration failed: ' . $cron;
            }
        }
        return $result;
    }
}
