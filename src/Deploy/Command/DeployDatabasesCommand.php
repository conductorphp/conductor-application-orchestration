<?php

namespace ConductorAppOrchestration\Deploy\Command;

use ConductorAppOrchestration\Deploy\ApplicationDatabaseDeployer;
use ConductorAppOrchestration\Deploy\ApplicationDatabaseDeployerAwareInterface;
use ConductorAppOrchestration\Config\ApplicationConfig;
use ConductorAppOrchestration\Config\ApplicationConfigAwareInterface;
use ConductorAppOrchestration\Exception;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Class DeployDatabasesCommand
 *
 * @package ConductorAppOrchestration\Snapshot\Command
 */
class DeployDatabasesCommand
    implements DeployCommandInterface, ApplicationDatabaseDeployerAwareInterface, LoggerAwareInterface, ApplicationConfigAwareInterface
{
    /**
     * @var ApplicationDatabaseDeployer
     */
    private $applicationDatabaseDeployer;
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
        if (!$includeDatabases) {
            $this->logger->notice(
                'Add condition "databases" to this step in your deployment plan. This step can only be run when deploying '
                . 'databases. Skipped.'
            );
            return null;
        }

        if (!isset($this->applicationDatabaseDeployer)) {
            throw new Exception\RuntimeException('$this->applicationDatabaseDeployer must be set.');
        }

        if (!isset($this->applicationConfig)) {
            throw new Exception\RuntimeException('$this->applicationConfig must be set.');
        }

        $databases = $this->applicationConfig->getSnapshotConfig()->getDatabases();
        if (!empty($options['databases'])) {
            $databases = array_replace_recursive($databases, $options['databases']);
        }

        $this->applicationDatabaseDeployer->deployDatabases(
            $snapshotPath,
            $snapshotName,
            $databases,
            $branch
        );
        return null;
    }

    /**
     * @inheritdoc
     */
    public function setApplicationDatabaseDeployer(ApplicationDatabaseDeployer $applicationDatabaseDeployer): void
    {
        $this->applicationDatabaseDeployer = $applicationDatabaseDeployer;
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
