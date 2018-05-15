<?php

// @todo Determine what of these defaults are unused
return [
    'defaults' => [
        'build'                                 => [
            'default_plan' => 'default',
            'plans'        => [],
        ],
        'default_branch'                        => 'master',
        'default_database_adapter'              => 'default',
        'default_database_importexport_adapter' => 'default',
        'default_dir_mode'                      => 0750,
        'default_file_mode'                     => 0640,
        'default_filesystem'                    => 'local',
        'file_layout'                           => 'default',
        'platform'                              => 'custom',
        'platforms'                             => [],
        'relative_document_root'                => '.',
        'ssh_defaults'                          => [
            'port'       => 22,
            'privateKey' => '/home/webuser/.ssh/id_rsa',
            'username'   => 'webuser',
        ],
        'servers'                               => [],
        'snapshot'                              => [
            'asset_groups'          => [],
            'database_table_groups' => [],
            'default_plan'          => 'default',
            'plans'                 => [],
        ],
        'source_file_paths'                     => [],
        'template_vars'                         => [],
    ],
];
