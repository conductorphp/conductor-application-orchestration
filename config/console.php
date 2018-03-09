<?php

namespace ConductorAppOrchestration\Command;

return [
    'commands' => [
        AppBuildCommand::class,
        AppConfigShowCommand::class,
        AppDeployCommand::class,
        AppDestroyCommand::class,
        AppMaintenanceCommand::class,
        AppSnapshotCommand::class,
    ],
];
