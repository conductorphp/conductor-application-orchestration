<?php

namespace ConductorAppOrchestration\Snapshot\Command;

use ConductorAppOrchestration\Exception;
use ConductorAppOrchestration\Maintenance\MaintenanceStrategyAwareInterface;
use ConductorAppOrchestration\Maintenance\MaintenanceStrategyInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Class DisableMaintenanceCommand
 *
 * @package ConductorAppOrchestration\Snapshot\Command
 */
class DisableMaintenanceCommand
    implements SnapshotCommandInterface, MaintenanceStrategyAwareInterface, LoggerAwareInterface
{
    /**
     * @var MaintenanceStrategyInterface
     */
    private $maintenanceStrategy;
    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct()
    {
        $this->logger = new NullLogger();
    }

    /**
     * @inheritdoc
     */
    public function run(
        string $snapshotName,
        string $snapshotPath,
        bool $includeDatabases = true,
        bool $includeAssets = true,
        array $assetSyncConfig = [],
        array $options = null
    ): ?string
    {
        if (!isset($this->maintenanceStrategy)) {
            throw new Exception\RuntimeException('$this->maintenanceStrategy must be set.');
        }

        $this->logger->info('Disabling maintenance mode.');
        $this->maintenanceStrategy->disable();
        return null;
    }

    /**
     * @inheritdoc
     */
    public function setMaintenanceStrategy(MaintenanceStrategyInterface $maintenanceStrategy): void
    {
        $this->maintenanceStrategy = $maintenanceStrategy;
    }

    /**
     * @inheritdoc
     */
    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }
}
