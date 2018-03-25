<?php

namespace ConductorAppOrchestration\Deploy\Command;

use ConductorAppOrchestration\ApplicationAssetDeployer;
use ConductorAppOrchestration\ApplicationAssetDeployerAwareInterface;
use ConductorAppOrchestration\Config\ApplicationConfig;
use ConductorAppOrchestration\Config\ApplicationConfigAwareInterface;
use ConductorAppOrchestration\Exception;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Class DeployAssetsCommand
 *
 * @package ConductorAppOrchestration\Snapshot\Command
 */
class DeployAssetsCommand
    implements DeployCommandInterface, ApplicationAssetDeployerAwareInterface, LoggerAwareInterface, ApplicationConfigAwareInterface
{
    /**
     * @var ApplicationAssetDeployer
     */
    private $applicationAssetDeployer;
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

        if (!isset($this->applicationAssetDeployer)) {
            throw new Exception\RuntimeException('$this->applicationAssetDeployer must be set.');
        }

        if (!isset($this->applicationConfig)) {
            throw new Exception\RuntimeException('$this->applicationConfig must be set.');
        }

        $assets = $this->applicationConfig->getSnapshotConfig()->getAssets();
        if (!empty($options['assets'])) {
            $assets = array_replace_recursive($assets, $options['assets']);
        }

        $this->applicationAssetDeployer->deployAssets(
            $snapshotPath,
            $snapshotName,
            $assets,
            [] // @todo add sync options
        );
        return null;
    }

    /**
     * @inheritdoc
     */
    public function setApplicationAssetDeployer(ApplicationAssetDeployer $applicationAssetDeployer): void
    {
        $this->applicationAssetDeployer = $applicationAssetDeployer;
    }

    /**
     * @inheritdoc
     */
    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * @param ApplicationConfig $applicationConfig
     */
    public function setApplicationConfig(ApplicationConfig $applicationConfig): void
    {
        $this->applicationConfig = $applicationConfig;
    }
}
