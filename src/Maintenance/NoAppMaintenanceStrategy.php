<?php

namespace ConductorAppOrchestration\Maintenance;

class NoAppMaintenanceStrategy implements MaintenanceStrategyInterface
{
    /**
     * @inheritdoc
     */
    public function enable(string $branch = null): void
    {
        throw new \LogicException('No maintenance strategy set.');
    }

    /**
     * @inheritdoc
     */
    public function disable(string $branch = null): void
    {
        throw new \LogicException('No maintenance strategy set.');
    }

    /**
     * @inheritdoc
     */
    public function isEnabled(string $branch = null): bool
    {
        throw new \LogicException('No maintenance strategy set.');
    }

}

