<?php

namespace ConductorAppOrchestration\Deploy\Command;

use ConductorAppOrchestration\Exception;
use ConductorCore\Filesystem\MountManager\MountManager;
use ConductorCore\Filesystem\MountManager\MountManagerAwareInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Class EnsureAssetSnapshotExistsCommand
 *
 * @package ConductorAppOrchestration\Deploy\Command
 */
class EnsureAssetSnapshotExistsCommand
    implements DeployCommandInterface, MountManagerAwareInterface,
               LoggerAwareInterface
{
    /**
     * @var MountManager
     */
    private $mountManager;
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
        if (!$includeAssets) {
            $this->logger->notice(
                'Add condition "assets" to this step in your deployment plan. This step can only be run when deploying '
                . 'assets. Skipped.'
            );
            return null;
        }

        if (!isset($this->mountManager)) {
            throw new Exception\RuntimeException('$this->mountManager must be set.');
        }

        if (!$this->mountManager->has("$snapshotPath/$snapshotName/assets")) {
            throw new Exception\RuntimeException(
                "Assets snapshot \"$snapshotName/assets\" does not exist in path \"$snapshotPath\"."
            );
        }

        return null;
    }

    /**
     * @inheritdoc
     */
    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    public function setMountManager(\ConductorCore\Filesystem\MountManager\MountManager $mountManager): void
    {
        $this->mountManager = $mountManager;
    }
}
