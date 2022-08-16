<?php

namespace ConductorAppOrchestration\Maintenance;

interface MaintenanceStrategyInterface
{
    public function enable(string $buildId = null): void;

    public function disable(string $buildId = null): void;

    public function isEnabled(string $buildId = null): bool;
}
