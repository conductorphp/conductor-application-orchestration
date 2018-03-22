<?php

namespace ConductorAppOrchestration;

use ConductorAppOrchestration\MaintenanceStrategy\MaintenanceStrategyInterface;

class AppMaintenance
{
    /**
     * @var MaintenanceStrategyInterface
     */
    private $maintenanceStrategy;

    /**
     * AppMaintenance constructor.
     *
     * @param MaintenanceStrategyInterface $maintenanceStrategy
     */
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

    /**
     * @return bool
     */
    public function isEnabled(): bool
    {
        return $this->maintenanceStrategy->isEnabled();
    }
}
