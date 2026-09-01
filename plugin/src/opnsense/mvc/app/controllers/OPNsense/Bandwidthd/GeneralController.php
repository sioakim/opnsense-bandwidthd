<?php

/*
 * Copyright (C) 2026 opnsense-bandwidthd contributors
 * Licensed under the Apache License, Version 2.0.
 */

namespace OPNsense\Bandwidthd;

use OPNsense\Base\IndexController;

/**
 * Services -> BandwidthD -> Settings.
 */
class GeneralController extends IndexController
{
    public function indexAction()
    {
        $this->view->title = gettext('BandwidthD Settings');
        $this->view->generalForm = $this->getForm('general');
        $this->view->overrideForm = $this->getForm('dialogOverride');
        $this->view->pick('OPNsense/Bandwidthd/general');
    }
}
