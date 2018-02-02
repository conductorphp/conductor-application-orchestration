<?php

return [
    'defaults' => [
        'platform' => 'custom',
        'default_file_mode' => 0640,
        'default_dir_mode' => 0750,
        'file_layout' => 'default',
        'default_branch' => 'master',
        'default_snapshot_name' => 'production-scrubbed',
        'maintenance_strategy' => 'file',
        'ssh_defaults' => [
            'port' => 22,
            'privateKey' => '/home/webuser/.ssh/id_rsa',
            'username' => 'webuser',
        ],
        'build_plans' => [],
        'platforms' => [],
    ],
];
