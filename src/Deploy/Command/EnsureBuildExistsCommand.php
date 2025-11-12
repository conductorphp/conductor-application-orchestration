<?php

namespace ConductorAppOrchestration\Deploy\Command;

use ConductorAppOrchestration\Exception;
use ConductorCore\Filesystem\MountManager\MountManager;
use ConductorCore\Filesystem\MountManager\MountManagerAwareInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class EnsureBuildExistsCommand
    implements DeployCommandInterface, MountManagerAwareInterface,
    LoggerAwareInterface
{
    private MountManager $mountManager;
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
        if (!$buildId) {
            $this->logger->notice(
                'Add condition "code-build" to this step in your deployment plan. This step can only be run when deploying '
                . 'a build. Skipped.'
            );
            return null;
        }

        if (!isset($this->mountManager)) {
            throw new Exception\RuntimeException('$this->mountManager must be set.');
        }

        if (!$this->mountManager->has("$buildPath/$buildId.tgz")) {
            throw new Exception\RuntimeException("Build file \"$buildId.tgz\" does not exist in path \"$buildPath\".");
        }

        return null;
    }

    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    public function setMountManager(MountManager $mountManager): void
    {
        $this->mountManager = $mountManager;
    }
}
