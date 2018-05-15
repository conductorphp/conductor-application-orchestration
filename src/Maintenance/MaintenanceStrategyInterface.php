<?php

namespace ConductorAppOrchestration\Maintenance;

interface MaintenanceStrategyInterface
{
    /**
     * @return void
     */
    public function enable(string $buildId = null): void;

    /**
     * @return void
     */
    public function disable(string $buildId = null): void;

    /**
     * @return bool
     */
    public function isEnabled(string $buildId = null): bool;
}
