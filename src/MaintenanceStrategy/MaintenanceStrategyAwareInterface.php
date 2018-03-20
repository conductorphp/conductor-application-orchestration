<?php

namespace ConductorAppOrchestration\MaintenanceStrategy;

interface MaintenanceStrategyAwareInterface
{
    /**
     * @param MaintenanceStrategyInterface $maintenanceStrategy
     */
    public function setMaintenanceStrategy(MaintenanceStrategyInterface $maintenanceStrategy): void;
}
