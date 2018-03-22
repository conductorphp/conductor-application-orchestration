<?php

namespace ConductorAppOrchestration\Deploy\Command;

use ConductorAppOrchestration\Config\ApplicationConfig;
use ConductorAppOrchestration\Config\ApplicationConfigAwareInterface;
use ConductorAppOrchestration\Exception;
use ConductorCore\Database\DatabaseAdapterManager;
use ConductorCore\Database\DatabaseAdapterManagerAwareInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Class DeleteAssetsCommand
 *
 * @package ConductorAppOrchestration\Deploy\Command
 */
class DeleteAssetsCommand
    implements DeployCommandInterface, ApplicationConfigAwareInterface,
               LoggerAwareInterface
{
    /**
     * @var ApplicationConfig
     */
    private $applicationConfig;
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
        if (!$includeAssets) {
            $this->logger->notice(
                'Add condition "assets" to this step in your deployment plan. This step can only be run when deploying '
                . 'assets. Skipped.'
            );
            return null;
        }

        if (!isset($this->applicationConfig)) {
            throw new Exception\RuntimeException('$this->applicationConfig must be set.');
        }

        $assetConfig = $this->applicationConfig->getSnapshotConfig()->getAssets();
        $assets = $options['assets'] ?? array_keys($assetConfig);
        if (empty($assets)) {
            throw new Exception\RuntimeException('No assets configured.');
        }

        foreach ($assets as $asset) {
            $assetPath = $this->getAssetPath($asset);
            $this->logger->debug("Deleting asset \"$asset\" from \"$assetPath\".");
            $this->removePath($assetPath);
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
    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * @param string $asset
     *
     * @return string
     */
    protected function getAssetPath(string $asset): string
    {
        $assetConfig = $this->applicationConfig->getSnapshotConfig()->getAssets();
        $location = $assetConfig[$asset]['location'] ?? 'code';
        switch ($location) {
            case 'code':
                $path = $this->applicationConfig->getCodePath();
                break;
            case 'local':
                $path = $this->applicationConfig->getLocalPath();
                break;
            case 'shared':
                $path = $this->applicationConfig->getSharedPath();
                break;

            default:
                throw new Exception\RuntimeException(sprintf(
                    'Invalid location "%s" for asset "". Must be one of "code", "local", or "shared".',
                    $location,
                    $asset
                ));
                break;
        }

        return "$path/$asset";
    }

    /**
     * rmdir() will not remove the dir if it is not empty
     *
     * @param string $path
     *
     * @return void
     */
    private function removePath(string $path): void
    {
        if (false !== strpos($path, '*')) {
            $paths = glob($path);
            foreach ($paths as $path) {
                $this->removePath($path);
            }
        } else {
            if (is_dir($path)) {
                $iterator = new \RecursiveDirectoryIterator($path);
                $iterator = new \RecursiveIteratorIterator($iterator, \RecursiveIteratorIterator::CHILD_FIRST);
                /** @var \SplFileInfo $file */
                foreach ($iterator as $file) {
                    if ('.' === $file->getBasename() || '..' === $file->getBasename()) {
                        continue;
                    }
                    if ($file->isLink() || $file->isFile()) {
                        unlink($file->getPathname());
                    } else {
                        rmdir($file->getPathname());
                    }
                }
                rmdir($path);
            } else {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }
}
