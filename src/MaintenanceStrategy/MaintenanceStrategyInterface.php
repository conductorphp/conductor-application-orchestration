<?php

namespace ConductorAppOrchestration\MaintenanceStrategy;

interface MaintenanceStrategyInterface
{
    /**
     * @return void
     */
    public function enable(string $branch = null): void;

    /**
     * @return void
     */
    public function disable(string $branch = null): void;

    /**
     * @return bool
     */
    public function isEnabled(string $branch = null): bool;
}
