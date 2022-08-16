<?php

namespace ConductorAppOrchestration\Deploy\Command;

use ConductorAppOrchestration\Deploy\ApplicationSkeletonDeployer;
use ConductorAppOrchestration\Deploy\ApplicationSkeletonDeployerAwareInterface;
use ConductorAppOrchestration\Exception;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Class MakeBuildCurrentCommand
 *
 * @package ConductorAppOrchestration\Snapshot\Command
 */
class MakeBuildCurrentCommand
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

    /**
     * @inheritdoc
     */
    public function run(
        string $codeRoot,
        string $buildId = null,
        string $buildPath = null,
        string $repoReference = null,
        string $snapshotName = null,
        string $snapshotPath = null,
        bool $includeAssets = true,
        array $assetSyncConfig = [],
        bool $includeDatabases = true,
        bool $allowFullRollback = false,
        array $options = null
    ): ?string
    {
        if (!isset($this->applicationSkeletonDeployer)) {
            throw new Exception\RuntimeException('$this->applicationSkeletonDeployer must be set.');
        }

        $this->logger->info("Setting build \"$buildId\" as current build.");
        $this->applicationSkeletonDeployer->makeBuildCurrent($buildId);
        return null;
    }

    /**
     * @inheritdoc
     */
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
