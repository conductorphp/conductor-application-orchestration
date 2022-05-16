<?php

namespace ConductorAppOrchestration;

return [
    'aliases' => [
        Maintenance\MaintenanceStrategyInterface::class => Maintenance\DefaultMaintenanceStrategy::class,
        Deploy\CodeDeploymentStateInterface::class => Deploy\DefaultCodeDeploymentStateStrategy::class,
    ],
    'factories' => [
        Config\ApplicationConfig::class => Config\ApplicationConfigFactory::class,
    ],
];
