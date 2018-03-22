<?php
/**
 * @author Kirk Madera <kmadera@robofirm.com>
 */

namespace ConductorAppOrchestration\Deploy;

use ConductorAppOrchestration\Config\ApplicationConfig;
use ConductorAppOrchestration\Deploy\Command\DeployCommandInterface;
use ConductorAppOrchestration\Exception;
use ConductorAppOrchestration\PlanRunner;
use ConductorCore\Database\DatabaseAdapterManager;
use ConductorCore\Filesystem\MountManager\MountManager;
use ConductorCore\Shell\Adapter\ShellAdapterInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * @todo Update to consider multiple server environments
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
            $assetPath = $this->getAssetPath($asset);
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
            $adapterName = $databaseConfig['adapter'] ?? $this->applicationConfig->getDefaultDatabaseAdapter();
            $adapter = $this->databaseAdapterManager->getAdapter($adapterName);
            if (!$adapter->databaseExists($adapterName)) {
                return false;
            }
        }
        return true;
    }

    /**
     * @todo Possibly move this to another class
     * @param string $asset
     *
     * @return string
     */
    private function getAssetPath(string $asset): string
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


}
