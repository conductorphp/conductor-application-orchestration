<?php

namespace ConductorAppOrchestration\Maintenance;

class DefaultMaintenanceStrategy implements MaintenanceStrategyInterface
{
    public function enable(string $buildId = null): void
    {
        // Do nothing
    }

    public function disable(string $buildId = null): void
    {
        // Do nothing
    }

    public function isEnabled(string $buildId = null): bool
    {
        return false;
    }
}
