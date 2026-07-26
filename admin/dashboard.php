<?php

use NexaPressMail\Controllers\DashboardController;

if (!defined('NEXAPRESS_MAIL_PATH')) {
    return;
}

(new DashboardController())->content();
