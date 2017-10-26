<?php

namespace DevopsToolAppOrchestration;

use DevopsToolAppOrchestration\MaintenanceStrategy\MaintenanceStrategyInterface;

class AppMaintenance
{
    /**
     * @var MaintenanceStrategyInterface
     */
    private $maintenanceStrategy;

    public function __construct(MaintenanceStrategyInterface $maintenanceStrategy)
    {
        $this->maintenanceStrategy = $maintenanceStrategy;
    }

    public function enable()
    {
        $this->maintenanceStrategy->enable();
    }

    public function disable()
    {
        $this->maintenanceStrategy->disable();
    }

    public function isEnabled()
    {
        return $this->maintenanceStrategy->isEnabled();
    }
}
