<?php

namespace DevopsToolAppOrchestration\MaintenanceStrategy;

use DevopsToolAppOrchestration\ApplicationConfig;

interface MaintenanceStrategyInterface
{
    /**
     * @return void
     */
    public function enable(ApplicationConfig $application, string $branch = null): void;

    /**
     * @return void
     */
    public function disable(ApplicationConfig $application, string $branch = null): void;

    /**
     * @return bool
     */
    public function isEnabled(ApplicationConfig $application, string $branch = null): bool;
}
