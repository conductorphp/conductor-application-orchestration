<?php

namespace ConductorAppOrchestration\Snapshot\Command;

use ConductorAppOrchestration\Config\ApplicationConfig;
use ConductorAppOrchestration\Config\ApplicationConfigAwareInterface;
use ConductorAppOrchestration\Exception;
use ConductorCore\Filesystem\MountManager\MountManager;
use ConductorCore\Filesystem\MountManager\MountManagerAwareInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Class SyncAssetsCommand
 *
 * @package ConductorAppOrchestration\Snapshot\Command
 */
class SyncAssetsCommand
    implements SnapshotCommandInterface, ApplicationConfigAwareInterface, MountManagerAwareInterface,
               LoggerAwareInterface
{
    /**
     * @var ApplicationConfig
     */
    private $applicationConfig;
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
    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * @inheritdoc
     */
    public function run(
        string $snapshotName,
        string $snapshotPath,
        string $branch = null,
        bool $includeDatabases = true,
        bool $includeAssets = true,
        array $assetSyncConfig = [],
        array $options = null
    ): ?string
    {
        if (!$includeAssets) {
            return null;
        }

        if (!isset($this->applicationConfig)) {
            throw new Exception\RuntimeException('$this->applicationConfig must be set.');
        }

        if (!isset($this->mountManager)) {
            throw new Exception\RuntimeException('$this->mountManager must be set.');
        }

        if (!empty($options['assets'])) {
            $snapshotConfig = $this->applicationConfig->getSnapshotConfig();
            foreach ($options['assets'] as $assetPath => $asset) {
                $path = $this->applicationConfig->getPath($asset['location']);
                $sourcePath = "$path/$assetPath";
                $targetPath = "$snapshotPath/$snapshotName/assets/{$asset['location']}/$assetPath";
                $this->logger->debug("Syncing asset \"$sourcePath\" to \"$targetPath\".");

                if (!empty($asset['excludes'])) {
                    $syncOptions['excludes'] = array_merge(
                        $syncOptions['excludes'] ?? [],
                        $snapshotConfig->expandAssetGroups($asset['excludes'])
                    );
                }

                if (!empty($asset['includes'])) {
                    $syncOptions['includes'] = array_merge(
                        $syncOptions['includes'] ?? [],
                        $snapshotConfig->expandAssetGroups($asset['includes'])
                    );
                }

                $this->mountManager->sync("local://{$sourcePath}", $targetPath, $assetSyncConfig);
            }
        }

        return null;
    }


    /**
     * @inheritdoc
     */
    public function setApplicationConfig(ApplicationConfig $applicationConfig): void
    {
        $this->applicationConfig = $applicationConfig;
    }

    /**
     * @inheritdoc
     */
    public function setMountManager(MountManager $mountManager): void
    {
        $this->mountManager = $mountManager;
    }

}
