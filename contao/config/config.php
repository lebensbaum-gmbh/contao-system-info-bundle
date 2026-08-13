<?php

declare(strict_types=1);

use Lebensbaum\ContaoSystemInfoBundle\Backend\SystemInfoBackendModule;

$GLOBALS['BE_MOD']['system']['system_info_credentials'] = [
    'callback' => SystemInfoBackendModule::class,
];
