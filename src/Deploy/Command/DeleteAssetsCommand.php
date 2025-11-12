<?php

namespace ConductorAppOrchestration\Deploy\Command;

use ConductorAppOrchestration\Config\ApplicationConfig;
use ConductorAppOrchestration\Config\ApplicationConfigAwareInterface;
use ConductorAppOrchestration\Exception;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

class DeleteAssetsCommand
    implements DeployCommandInterface, ApplicationConfigAwareInterface,
    LoggerAwareInterface
{
    private ApplicationConfig $applicationConfig;
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

    public function setApplicationConfig(ApplicationConfig $applicationConfig): void
    {
        $this->applicationConfig = $applicationConfig;
    }

    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    protected function getAssetPath(string $asset): string
    {
        $assetConfig = $this->applicationConfig->getSnapshotConfig()->getAssets();
        $location = $assetConfig[$asset]['location'] ?? 'code';
        $path = $this->applicationConfig->getPath($location);
        return "$path/$asset";
    }

    /**
     * rmdir() will not remove the dir if it is not empty
     */
    private function removePath(string $path): void
    {
        if (false !== strpos($path, '*')) {
            $paths = glob($path);
            /** @noinspection SuspiciousLoopInspection */
            foreach ($paths as $path) {
                $this->removePath($path);
            }
        } elseif (is_dir($path)) {
            $iterator = new RecursiveDirectoryIterator($path);
            $iterator = new RecursiveIteratorIterator($iterator, RecursiveIteratorIterator::CHILD_FIRST);
            /** @var SplFileInfo $file */
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
        } elseif (is_file($path)) {
            unlink($path);
        }
    }
}
