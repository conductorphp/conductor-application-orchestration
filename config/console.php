<?php

namespace DevopsToolAppOrchestration\Command;

return [
    'commands' => [
        AppBuildCommand::class,
        AppConfigGetCommand::class,
        AppConfigListCommand::class,
        AppDestroyCommand::class,
        AppInstallCommand::class,
        AppListCommand::class,
//        AppMaintenanceCommand::class,
        AppRefreshAssetsCommand::class,
        AppRefreshDatabasesCommand::class,
        AppSnapshotCommand::class,
    ],
];
