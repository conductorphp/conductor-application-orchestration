<?php

namespace ConductorAppOrchestration\Maintenance;

interface MaintenanceStrategyAwareInterface
{
    /**
     * @param MaintenanceStrategyInterface $maintenanceStrategy
     */
    public function setMaintenanceStrategy(MaintenanceStrategyInterface $maintenanceStrategy): void;
}
