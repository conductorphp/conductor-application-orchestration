<?php
/**
 * @author Kirk Madera <kirk.madera@rmgmedia.com>
 */

namespace ConductorAppOrchestration\Deploy;

use ConductorAppOrchestration\Config\ApplicationConfig;
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
     * @return bool
     */
    public function codeDeployed(): bool
    {
        return $this->codeDeploymentState->codeDeployed();
    }

    /**
     * @return bool
     */
    public function databasesDeployed(): bool
    {
        $databases = $this->applicationConfig->getSnapshotConfig()->getDatabases();
        foreach ($databases as $database => $databaseConfig) {
            if (isset($databaseConfig['local_database_name'])) {
                $localDatabaseName = $databaseConfig['local_database_name'];
            } else {
                $localDatabaseName = $database;
            }

            $adapterName = $databaseConfig['adapter'] ?? $this->applicationConfig->getDefaultDatabaseAdapter();
            $adapter = $this->databaseAdapterManager->getAdapter($adapterName);
            if (!$adapter->databaseExists($localDatabaseName)) {
                return false;
            }
        }
        return true;
    }

}
