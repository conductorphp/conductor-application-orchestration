<?php

namespace ConductorAppOrchestration\Deploy;

use ConductorAppOrchestration\Config\ApplicationConfig;
use ConductorAppOrchestration\Exception;
use ConductorCore\Filesystem\MountManager\MountManager;
use ConductorCore\Shell\Adapter\LocalShellAdapter;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class ApplicationAssetDeployer
{
    private ApplicationConfig $applicationConfig;
    private LocalShellAdapter $shellAdapter;
    protected MountManager $mountManager;
    protected LoggerInterface $logger;

    public function __construct(
        ApplicationConfig $applicationConfig,
        LocalShellAdapter $localShellAdapter,
        MountManager      $mountManager,
        LoggerInterface   $logger = null
    ) {
        $this->applicationConfig = $applicationConfig;
        $this->shellAdapter = $localShellAdapter;
        $this->mountManager = $mountManager;
        if (is_null($logger)) {
            $logger = new NullLogger();
        }
        $this->logger = $logger;
    }

    public function deployAssets(
        string $snapshotPath,
        string $snapshotName,
        array  $assets,
        array  $syncOptions = []
    ): void {
        if (!$assets) {
            throw new Exception\RuntimeException('No assets given for deployment.');
        }

        $this->logger->info('Installing assets');
        $snapshotConfig = $this->applicationConfig->getSnapshotConfig();
        foreach ($assets as $sourcePath => $asset) {
            if (empty($asset['ensure']) || empty($asset['location'])) {
                throw new Exception\RuntimeException(
                    "Asset \"$sourcePath\" must have \"ensure\" and \"location\" properties set."
                );
            }

            if (!empty($asset['local_path'])) {
                $destinationPath = $asset['local_path'];
            } else {
                $destinationPath = $sourcePath;
            }
            $path = $this->applicationConfig->getPath($asset['location']);
            $destinationPath = "$path/$destinationPath";

            $sourcePath = "$snapshotPath/$snapshotName/assets/{$asset['location']}/$sourcePath";

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

            $this->mountManager->sync(
                $sourcePath,
                "local://$destinationPath",
                $syncOptions
            );
        }
    }

    public function setLogger(LoggerInterface $logger): void
    {
        if ($this->shellAdapter instanceof LoggerAwareInterface) {
            $this->shellAdapter->setLogger($logger);
        }
        $this->mountManager->setLogger($logger);
        $this->logger = $logger;
    }

}
