<?php
/**
 * @author Kirk Madera <kmadera@robofirm.com>
 */

namespace ConductorAppOrchestration\Deploy;

use ConductorAppOrchestration\Config\ApplicationConfig;
use ConductorAppOrchestration\FileLayoutInterface;
use ConductorCore\Database\DatabaseAdapterManager;

/**
 * @todo    Update to consider multiple server environments
 *
 * Class DeploymentState
 *
 * @package ConductorAppOrchestration\Deploy
 */
class DeploymentState
{
    /**
     * @var ApplicationConfig
     */
    private $applicationConfig;
    /**
     * @var DatabaseAdapterManager
     */
    private $databaseAdapterManager;
    /**
     * @var CodeDeploymentStateInterface
     */
    private $codeDeploymentState;

    public function __construct(
        ApplicationConfig $applicationConfig,
        DatabaseAdapterManager $databaseAdapterManager,
        CodeDeploymentStateInterface $codeDeploymentState
    ) {
        $this->applicationConfig = $applicationConfig;
        $this->databaseAdapterManager = $databaseAdapterManager;
        $this->codeDeploymentState = $codeDeploymentState;
    }

    /**
     * @return bool
     */
    public function assetsDeployed(): bool
    {
        $assets = $this->applicationConfig->getSnapshotConfig()->getAssets();
        foreach ($assets as $asset => $assetConfig) {
            $assetConfig = $this->applicationConfig->getSnapshotConfig()->getAssets();
            $location = $assetConfig[$asset]['location'] ?? 'code';
            $assetPath = $this->applicationConfig->getPath($location) . '/' . $asset;
            if (!file_exists($assetPath)) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param string|null $branch
     *
     * @return bool
     */
    public function codeDeployed(string $branch = null): bool
    {
        return $this->codeDeploymentState->codeDeployed($branch);
    }

    /**
     * @param string|null $branch
     *
     * @return bool
     */
    public function databasesDeployed(string $branch = null): bool
    {
        $isBranchFileLayoutStrategy = FileLayoutInterface::STRATEGY_BRANCH == $this->applicationConfig->getFileLayoutStrategy();
        $databases = $this->applicationConfig->getSnapshotConfig()->getDatabases();
        foreach ($databases as $database => $databaseConfig) {
            $adapterName = $databaseConfig['adapter'] ?? $this->applicationConfig->getDefaultDatabaseAdapter();
            $adapter = $this->databaseAdapterManager->getAdapter($adapterName);
            if ($isBranchFileLayoutStrategy && $branch) {
                $database .= '_' . $this->sanitizeBranchForDatabase($branch);
            }

            if (!$adapter->databaseExists($database)) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param $branch
     *
     * @return string
     */
    private function sanitizeBranchForDatabase($branch)
    {
        return strtolower(preg_replace('/[^a-z0-9\.-]/i', '_', $branch));
    }

}
