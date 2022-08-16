<?php

namespace ConductorAppOrchestration\Maintenance;

interface MaintenanceStrategyAwareInterface
{
    public function setMaintenanceStrategy(MaintenanceStrategyInterface $maintenanceStrategy): void;
}
