<?php

return [
    'defaults' => [
        'platform' => 'custom',
        'default_file_mode' => 0640,
        'default_dir_mode' => 0750,
        'file_layout' => 'default',
        'default_branch' => 'master',
        'default_snapshot_name' => 'production-scrubbed',
        'mysql_host' => 'localhost',
        'mysql_port' => 3306,
        'maintenance_strategy' => 'file',
        'ssh_defaults' => [
            'port' => 22,
            'privateKey' => '/home/webuser/.ssh/id_rsa',
            'username' => 'webuser',
        ],
        'build_plans' => [
            'composer::clean' => [
                'commands' => [
                    'composer-clean' => 'composer show --name-only | sed \':a;N;$!ba;s|\n| |g\' | xargs composer remove --no-plugins --no-interaction -vvv && git checkout composer.json composer.lock',
                ],
            ],
            'composer::install-development' => [
                'commands' => [
                    'composer-install' => 'composer install --no-interaction --no-suggest --optimize-autoloader -vvv',
                ],
            ],
            'composer::install-production' => [
                'commands' => [
                    'composer-install' => 'composer install --no-dev --no-interaction --no-suggest --optimize-autoloader -vvv',
                ],
            ],
        ],
        'platforms' => [],
    ],
];
