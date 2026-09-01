<?php

/*
 * Copyright (C) 2026 opnsense-bandwidthd contributors
 * Licensed under the Apache License, Version 2.0.
 */

namespace OPNsense\Bandwidthd;

use OPNsense\Base\IndexController;

/**
 * Reporting -> BandwidthD: the interactive traffic dashboard.
 *
 * The page is a self-contained vanilla-JS app served from /bandwidthd_ui; this
 * controller only renders its shell inside the OPNsense layout.
 */
class DashboardController extends IndexController
{
    public function indexAction()
    {
        $this->view->title = gettext('BandwidthD');
        $this->view->pick('OPNsense/Bandwidthd/dashboard');
    }
}
