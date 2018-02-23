<?php

namespace ConductorAppOrchestration\Command;

return [
    'commands' => [
        AppBuildCommand::class,
        AppConfigShowCommand::class,
        AppDestroyCommand::class,
        AppInstallCommand::class,
        AppInstallAssetsCommand::class,
        AppInstallCodeCommand::class,
        AppInstallDatabasesCommand::class,
        AppInstallSkeletonCommand::class,
        AppMaintenanceCommand::class,
        AppSnapshotCommand::class,
    ],
];
