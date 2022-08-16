<?php

namespace ConductorAppOrchestration\Maintenance;

class ApplicationMaintenanceManager
{
    private MaintenanceStrategyInterface $maintenanceStrategy;

    public function __construct(MaintenanceStrategyInterface $maintenanceStrategy)
    {
        $this->maintenanceStrategy = $maintenanceStrategy;
    }


    public function enable(): void
    {
        $this->maintenanceStrategy->enable();
    }

    public function disable(): void
    {
        $this->maintenanceStrategy->disable();
    }

    public function isEnabled(): bool
    {
        return $this->maintenanceStrategy->isEnabled();
    }
}
