<?php

namespace ConductorAppOrchestration\Snapshot\Command;

use ConductorAppOrchestration\Exception;
use ConductorCore\Filesystem\MountManager\MountManager;
use ConductorCore\Filesystem\MountManager\MountManagerAwareInterface;
use League\Flysystem\FilesystemException;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class DeleteExistingSnapshotCommand
    implements SnapshotCommandInterface, MountManagerAwareInterface, LoggerAwareInterface
{
    private MountManager $mountManager;
    private LoggerInterface $logger;

    public function __construct()
    {
        $this->logger = new NullLogger();
    }

    /**
     * @throws FilesystemException
     */
    public function run(
        string $snapshotName,
        string $snapshotPath,
        bool   $includeDatabases = true,
        bool   $includeAssets = true,
        array  $assetSyncConfig = [],
        ?array  $options = null
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
        // A snapshot is a directory. delete() removes one file: on a local path it failed, and on S3
        // it deleted no object, so a replaced snapshot kept files the new one does not have (CTAP-2586)
        $this->mountManager->deleteDirectory($path);
        return null;
    }

    public function setMountManager(MountManager $mountManager): void
    {
        $this->mountManager = $mountManager;
    }

    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }
}
