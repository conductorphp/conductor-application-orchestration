<?php

namespace ConductorAppOrchestration\Maintenance;

class DefaultMaintenanceStrategy implements MaintenanceStrategyInterface
{
    /**
     * @return void
     */
    public function enable(string $buildId = null): void
    {
        // Do nothing
    }

    /**
     * @return void
     */
    public function disable(string $buildId = null): void
    {
        // Do nothing
    }

    /**
     * @return bool
     */
    public function isEnabled(string $buildId = null): bool
    {
        return false;
    }
}
