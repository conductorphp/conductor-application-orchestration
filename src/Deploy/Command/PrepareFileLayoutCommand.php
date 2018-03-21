<?php

namespace ConductorAppOrchestration\Deploy\Command;

use ConductorAppOrchestration\ApplicationSkeletonDeployer;
use ConductorAppOrchestration\ApplicationSkeletonDeployerAwareInterface;
use ConductorAppOrchestration\Exception;
use ConductorAppOrchestration\MaintenanceStrategy\MaintenanceStrategyAwareInterface;
use ConductorAppOrchestration\MaintenanceStrategy\MaintenanceStrategyInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Class PrepareFileLayoutCommand
 *
 * @package ConductorAppOrchestration\Snapshot\Command
 */
class PrepareFileLayoutCommand
    implements DeployCommandInterface, ApplicationSkeletonDeployerAwareInterface, LoggerAwareInterface
{
    /**
     * @var ApplicationSkeletonDeployer
     */
    private $applicationSkeletonDeployer;
    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct()
    {
        $this->logger = new NullLogger();
    }

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
    ): ?string {
        if (!isset($this->applicationSkeletonDeployer)) {
            throw new Exception\RuntimeException('$this->applicationSkeletonDeployer must be set.');
        }

        $this->logger->info('Preparing file layout.');
        $this->applicationSkeletonDeployer->prepareFileLayout();
        return null;
    }

    public function setApplicationSkeletonDeployer(ApplicationSkeletonDeployer $applicationSkeletonDeployer): void
    {
        $this->applicationSkeletonDeployer = $applicationSkeletonDeployer;
    }

    /**
     * @inheritdoc
     */
    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }
}