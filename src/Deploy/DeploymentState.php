<?php

namespace ConductorAppOrchestration\Deploy;

use ConductorAppOrchestration\Config\ApplicationConfig;
use ConductorCore\Database\DatabaseAdapterManager;

/**
 * @todo Update to consider multiple server environments
 */
class DeploymentState
{
    private ApplicationConfig $applicationConfig;
    private DatabaseAdapterManager $databaseAdapterManager;
    private CodeDeploymentStateInterface $codeDeploymentState;

    public function __construct(
        ApplicationConfig            $applicationConfig,
        DatabaseAdapterManager       $databaseAdapterManager,
        CodeDeploymentStateInterface $codeDeploymentState
    ) {
        $this->applicationConfig = $applicationConfig;
        $this->databaseAdapterManager = $databaseAdapterManager;
        $this->codeDeploymentState = $codeDeploymentState;
    }

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

    public function codeDeployed(): bool
    {
        return $this->codeDeploymentState->codeDeployed();
    }

    public function databasesDeployed(): bool
    {
        $databases = $this->applicationConfig->getDatabases();
        foreach ($databases as $database => $databaseConfig) {
            $alias = $databaseConfig['alias'] ?? $database;
            $adapterName = $databaseConfig['adapter'] ?? $this->applicationConfig->getDefaultDatabaseAdapter();
            $adapter = $this->databaseAdapterManager->getAdapter($adapterName);
            if (!$adapter->databaseExists($alias)) {
                return false;
            }
        }
        return true;
    }

}
