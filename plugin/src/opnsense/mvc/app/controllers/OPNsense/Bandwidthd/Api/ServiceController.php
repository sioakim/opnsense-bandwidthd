<?php

/*
 * Copyright (C) 2026 opnsense-bandwidthd contributors
 * Licensed under the Apache License, Version 2.0.
 */

namespace OPNsense\Bandwidthd\Api;

use OPNsense\Base\ApiMutableServiceControllerBase;

/**
 * start / stop / restart / reconfigure / status for the bandwidthd daemon.
 */
class ServiceController extends ApiMutableServiceControllerBase
{
    protected static $internalServiceClass = 'OPNsense\Bandwidthd\Bandwidthd';
    protected static $internalServiceTemplate = 'OPNsense/Bandwidthd';
    protected static $internalServiceEnabled = 'general.enabled';
    protected static $internalServiceName = 'bandwidthd';
}
