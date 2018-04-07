<?php

namespace ConductorAppOrchestration\Maintenance;

interface MaintenanceStrategyInterface
{
    /**
     * @return void
     */
    public function enable(): void;

    /**
     * @return void
     */
    public function disable(): void;

    /**
     * @return bool
     */
    public function isEnabled(): bool;
}
