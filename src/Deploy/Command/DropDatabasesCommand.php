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

class DropDatabasesCommand
    implements DeployCommandInterface, ApplicationConfigAwareInterface, DatabaseAdapterManagerAwareInterface,
    LoggerAwareInterface
{
    private ApplicationConfig $applicationConfig;
    private DatabaseAdapterManager $databaseAdapterManager;
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
        if (!$includeDatabases) {
            $this->logger->notice(
                'Add condition "databases" to this step in your deployment plan. This step can only be run when deploying '
                . 'databases. Skipped.'
            );
            return null;
        }

        if (!isset($this->databaseAdapterManager)) {
            throw new Exception\RuntimeException('$this->databaseAdapterManager must be set.');
        }

        if (!isset($this->applicationConfig)) {
            throw new Exception\RuntimeException('$this->applicationConfig must be set.');
        }

        $databaseConfig = $this->applicationConfig->getSnapshotConfig()->getDatabases();
        $databases = $options['databases'] ?? array_keys($databaseConfig);
        if (empty($databases)) {
            throw new Exception\RuntimeException('No databases configured.');
        }

        foreach ($databases as $database) {
            $adapterName = $databaseConfig[$database]['adapter'] ??
                $this->applicationConfig->getDefaultDatabaseAdapter();
            $adapter = $this->databaseAdapterManager->getAdapter($adapterName);
            if ($adapter->databaseExists($database)) {
                $this->logger->debug("Dropping database \"$database\" using the \"$adapterName\" database adapter.");
                $adapter->dropDatabase($database);
            }
        }

        return null;
    }

    public function setApplicationConfig(ApplicationConfig $applicationConfig): void
    {
        $this->applicationConfig = $applicationConfig;
    }

    public function setDatabaseAdapterManager(
        DatabaseAdapterManager $databaseAdapterManager
    ): void {
        $this->databaseAdapterManager = $databaseAdapterManager;
    }

    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }
}
