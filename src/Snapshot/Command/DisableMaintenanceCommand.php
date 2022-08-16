<?php

namespace ConductorAppOrchestration\Snapshot\Command;

use ConductorAppOrchestration\Exception;
use ConductorAppOrchestration\Maintenance\MaintenanceStrategyAwareInterface;
use ConductorAppOrchestration\Maintenance\MaintenanceStrategyInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class DisableMaintenanceCommand
    implements SnapshotCommandInterface, MaintenanceStrategyAwareInterface, LoggerAwareInterface
{
    private MaintenanceStrategyInterface $maintenanceStrategy;
    private LoggerInterface $logger;

    public function __construct()
    {
        $this->logger = new NullLogger();
    }

    public function run(
        string $snapshotName,
        string $snapshotPath,
        bool   $includeDatabases = true,
        bool   $includeAssets = true,
        array  $assetSyncConfig = [],
        ?array  $options = null
    ): ?string {
        if (!isset($this->maintenanceStrategy)) {
            throw new Exception\RuntimeException('$this->maintenanceStrategy must be set.');
        }

        $this->logger->info('Disabling maintenance mode.');
        $this->maintenanceStrategy->disable();
        return null;
    }

    public function setMaintenanceStrategy(MaintenanceStrategyInterface $maintenanceStrategy): void
    {
        $this->maintenanceStrategy = $maintenanceStrategy;
    }

    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }
}
