<?php

namespace DevopsToolAppOrchestration;

return [
    'initializers' => [
        Command\ApplicationConfigAwareInitializer::class,
    ],
    'factories' => [
        ApplicationMaintenanceStateManager::class => ApplicationMaintenanceStateManagerFactory::class,
    ],
];
