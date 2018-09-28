<?php

namespace ConductorAppOrchestration;

return [
    'aliases' => [
        Deploy\CodeDeploymentStateInterface::class => Deploy\NoCodeDeploymentState::class,
        Maintenance\MaintenanceStrategyInterface::class => Maintenance\NoAppMaintenanceStrategy::class,
    ],
    'factories' => [
        Config\ApplicationConfig::class => Config\ApplicationConfigFactory::class,
    ],
];
