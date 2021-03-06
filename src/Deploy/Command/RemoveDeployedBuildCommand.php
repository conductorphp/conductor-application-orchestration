<?php

namespace ConductorAppOrchestration\Deploy\Command;

use ConductorAppOrchestration\Config\ApplicationConfig;
use ConductorAppOrchestration\Config\ApplicationConfigAwareInterface;
use ConductorAppOrchestration\Exception;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Class RemoveDeployedBuildCommand
 *
 * @package ConductorAppOrchestration\Snapshot\Command
 */
class RemoveDeployedBuildCommand
    implements DeployCommandInterface, ApplicationConfigAwareInterface, LoggerAwareInterface
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
        if (!$buildId) {
            $this->logger->notice(
                'Add condition "code-build" to this step in your deployment plan. This step can only be run when deploying '
                . 'code from a build. Skipped.'
            );
            return null;
        }

        if (!isset($this->applicationConfig)) {
            throw new Exception\RuntimeException('$this->applicationConfig must be set.');
        }

        $buildDeployPath = $this->applicationConfig->getCodePath($buildId);
        $this->logger->debug("Removing deployed build at \"$buildDeployPath\".");
        $this->removePath($buildDeployPath);
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
