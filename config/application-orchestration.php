<?php

return [
    'defaults' => [
        'asset_groups' => [],
        'assets' => [],
        'build_plans' => [],
        'build_exclude_paths' => [],
        'databases' => [],
        'database_table_groups' => [],
        'default_branch' => 'master',
        'default_database_adapter' => 'default',
        'default_database_importexport_adapter' => 'default',
        'default_dir_mode' => 0750,
        'default_file_mode' => 0640,
        'default_filesystem_adapter' => 'default',
        'default_snapshot_name' => 'production-scrubbed',
        'file_layout' => 'default',
        'files' => [],
        'platform' => 'custom',
        'platforms' => [],
        'relative_document_root' => '.',
        'ssh_defaults' => [
            'port' => 22,
            'privateKey' => '/home/webuser/.ssh/id_rsa',
            'username' => 'webuser',
        ],
        'servers' => [],
        'source_file_paths' => [],
        'template_vars' => [],
    ],
];
