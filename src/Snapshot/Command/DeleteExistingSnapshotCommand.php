<?php

namespace ConductorAppOrchestration\Snapshot\Command;

use ConductorAppOrchestration\Exception;
use ConductorCore\Filesystem\MountManager\MountManager;
use ConductorCore\Filesystem\MountManager\MountManagerAwareInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Class DeleteExistingSnapshotCommand
 *
 * @package ConductorAppOrchestration\Snapshot\Command
 */
class DeleteExistingSnapshotCommand
    implements SnapshotCommandInterface, MountManagerAwareInterface, LoggerAwareInterface
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
    public function setMountManager(MountManager $mountManager): void
    {
        $this->mountManager = $mountManager;
    }

    /**
     * @inheritdoc
     */
    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    public function run(
        string $snapshotName,
        string $snapshotPath,
        string $branch = null,
        bool $includeDatabases = true,
        bool $includeAssets = true,
        array $assetSyncConfig = [],
        array $options = null
    ): ?string {

        if (!isset($this->mountManager)) {
            throw new Exception\RuntimeException('$this->mountManager must be set.');
        }

        $this->logger->info('Deleting existing snapshot if exists.');
        $path = "$snapshotPath/$snapshotName";
        if ($includeDatabases && !$includeAssets) {
            $path .= '/databases';
        } elseif ($includeAssets && !$includeDatabases) {
            $path .= '/assets';
        }
        $this->mountManager->deleteDir($path);
        return null;
    }
}
