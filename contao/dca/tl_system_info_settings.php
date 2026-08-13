<?php

declare(strict_types=1);

use Contao\DC_Table;

$GLOBALS['TL_DCA']['tl_system_info_settings'] = [
    'config' => [
        'dataContainer' => DC_Table::class,
        'sql' => [
            'keys' => [
                'id' => 'primary',
                'system_id' => 'unique',
            ],
        ],
    ],
    'fields' => [
        'id' => [
            'sql' => 'int(10) unsigned NOT NULL auto_increment',
        ],
        'tstamp' => [
            'sql' => "int(10) unsigned NOT NULL default 0",
        ],
        'system_id' => [
            'sql' => "varchar(32) NOT NULL default ''",
        ],
        'encrypted_secret' => [
            'sql' => "varchar(255) NOT NULL default ''",
        ],
        'secret_changed_at' => [
            'sql' => "int(10) unsigned NOT NULL default 0",
        ],
        'secret_reveal_pending' => [
            'sql' => "char(1) NOT NULL default '1'",
        ],
    ],
];
