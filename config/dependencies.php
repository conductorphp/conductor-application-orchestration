<?php

namespace ConductorAppOrchestration;

return [
    'factories' => [
        Config\ApplicationConfig::class => Config\ApplicationConfigFactory::class,
        Platform\PlatformManager::class => Platform\PlatformManagerFactory::class,
        // The two contracts a platform-support module provides. They resolve to the configured
        // platform's registration through PlatformManager; a platform module registers its
        // implementations under its own platform key and never under these names (CTAP-1757).
        Maintenance\MaintenanceStrategyInterface::class => Platform\PlatformServiceFactory::class,
        Deploy\CodeDeploymentStateInterface::class      => Platform\PlatformServiceFactory::class,
    ],
];
