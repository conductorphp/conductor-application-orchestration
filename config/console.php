<?php

namespace ConductorAppOrchestration\Console;

return [
    'commands' => [
        AppBuildCommand::class,
        AppConfigShowCommand::class,
        AppDeployCommand::class,
        AppDestroyCommand::class,
        AppMaintenanceCommand::class,
        AppPlansCommand::class,
        AppSnapshotCommand::class,
    ],
];
