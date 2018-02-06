<?php

namespace DevopsToolAppOrchestration\Command;

return [
    'commands' => [
        AppBuildCommand::class,
        AppConfigShowCommand::class,
        AppDestroyCommand::class,
        AppInstallCommand::class,
        AppMaintenanceCommand::class,
        AppRefreshAssetsCommand::class,
        AppRefreshDatabasesCommand::class,
        AppSnapshotCommand::class,
    ],
];
