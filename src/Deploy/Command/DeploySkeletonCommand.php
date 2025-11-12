<?php

namespace ConductorAppOrchestration\Deploy\Command;

use ConductorAppOrchestration\Deploy\ApplicationSkeletonDeployer;
use ConductorAppOrchestration\Deploy\ApplicationSkeletonDeployerAwareInterface;
use ConductorAppOrchestration\Exception;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class DeploySkeletonCommand
    implements DeployCommandInterface, ApplicationSkeletonDeployerAwareInterface, LoggerAwareInterface
{
    private ApplicationSkeletonDeployer $applicationSkeletonDeployer;
    private LoggerInterface $logger;

    public function __construct()
    {
        $this->logger = new NullLogger();
    }

    public function run(
        string  $codeRoot,
        ?string $buildId = null,
        ?string $buildPath = null,
        ?string $repoReference = null,
        ?string $snapshotName = null,
        ?string $snapshotPath = null,
        bool    $includeAssets = true,
        array   $assetSyncConfig = [],
        bool    $includeDatabases = true,
        bool    $allowFullRollback = false,
        ?array  $options = null
    ): ?string {
        if (!isset($this->applicationSkeletonDeployer)) {
            throw new Exception\RuntimeException('$this->applicationSkeletonDeployer must be set.');
        }

        $this->logger->info('Deploying skeleton.');
        $this->applicationSkeletonDeployer->installAppFiles($buildId);
        return null;
    }

    public function setApplicationSkeletonDeployer(ApplicationSkeletonDeployer $applicationSkeletonDeployer): void
    {
        $this->applicationSkeletonDeployer = $applicationSkeletonDeployer;
    }

    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }
}
