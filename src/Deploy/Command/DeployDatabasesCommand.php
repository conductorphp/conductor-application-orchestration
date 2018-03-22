<?php

namespace ConductorAppOrchestration\Deploy\Command;

use ConductorAppOrchestration\ApplicationDatabaseDeployer;
use ConductorAppOrchestration\ApplicationDatabaseDeployerAwareInterface;
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
    implements DeployCommandInterface, ApplicationDatabaseDeployerAwareInterface, LoggerAwareInterface
{
    /**
     * @var ApplicationDatabaseDeployer
     */
    private $applicationDatabaseDeployer;
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

        if (empty($options['databases'])) {
            throw new Exception\RuntimeException('Option "databases" must be specified.');
        }

        if (!isset($this->applicationDatabaseDeployer)) {
            throw new Exception\RuntimeException('$this->applicationDatabaseDeployer must be set.');
        }

        $this->applicationDatabaseDeployer->deployDatabases(
            $snapshotPath,
            $snapshotName,
            $options['databases'],
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

}
