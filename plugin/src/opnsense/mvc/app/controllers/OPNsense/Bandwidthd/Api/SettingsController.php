<?php

/*
 * Copyright (C) 2026 opnsense-bandwidthd contributors
 * Licensed under the Apache License, Version 2.0.
 */

namespace OPNsense\Bandwidthd\Api;

use OPNsense\Base\ApiMutableModelControllerBase;
use OPNsense\Core\Backend;

/**
 * Settings + per-device override CRUD.
 *
 * get/set are inherited; the *Override* actions drive the overrides grid.
 */
class SettingsController extends ApiMutableModelControllerBase
{
    protected static $internalModelName = 'bandwidthd';
    protected static $internalModelClass = 'OPNsense\Bandwidthd\Bandwidthd';

    public function searchOverrideAction()
    {
        return $this->searchBase('overrides.override', ['match', 'name', 'vendor', 'tag', 'quota_host_gb']);
    }

    public function getOverrideAction($uuid = null)
    {
        return $this->getBase('override', 'overrides.override', $uuid);
    }

    public function addOverrideAction()
    {
        return $this->addBase('override', 'overrides.override');
    }

    public function setOverrideAction($uuid)
    {
        return $this->setBase('override', 'overrides.override', $uuid);
    }

    public function delOverrideAction($uuid)
    {
        return $this->delBase('overrides.override', $uuid);
    }

    /**
     * Test the configured PostgreSQL connection and create the schema.
     * Returns a plain status so the settings page can show it inline.
     */
    public function testDbAction()
    {
        if ($this->request->isPost()) {
            $response = (new Backend())->configdRun('bandwidthd dbtest');
            return ['status' => trim((string)$response)];
        }
        return ['status' => 'failed'];
    }
}
