<?php

namespace ConductorAppOrchestration\Deploy\Command;

use ConductorAppOrchestration\Exception;
use ConductorAppOrchestration\MaintenanceStrategy\MaintenanceStrategyAwareInterface;
use ConductorAppOrchestration\MaintenanceStrategy\MaintenanceStrategyInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Class EnableMaintenanceCommand
 *
 * @package ConductorAppOrchestration\Snapshot\Command
 */
class EnableMaintenanceCommand
    implements DeployCommandInterface, MaintenanceStrategyAwareInterface, LoggerAwareInterface
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
        string $codeRoot,
        string $buildId = null,
        string $buildPath = null,
        string $branch = null,
        string $snapshotName = null,
        string $snapshotPath = null,
        bool $includeAssets = true,
        array $assetSyncConfig = [],
        bool $includeDatabases = true,
        bool $allowFullRollback = false,
        array $options = null
    ): ?string
    {
        if (!isset($this->maintenanceStrategy)) {
            throw new Exception\RuntimeException('$this->maintenanceStrategy must be set.');
        }

        $this->logger->info('Enabling maintenance mode.');
        $this->maintenanceStrategy->enable($branch);
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
