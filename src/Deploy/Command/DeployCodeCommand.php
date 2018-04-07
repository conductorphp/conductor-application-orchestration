<?php

namespace ConductorAppOrchestration\Deploy\Command;

use ConductorAppOrchestration\Deploy\ApplicationCodeDeployer;
use ConductorAppOrchestration\Deploy\ApplicationCodeDeployerAwareInterface;
use ConductorAppOrchestration\Exception;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Class DeployCodeCommand
 *
 * @package ConductorAppOrchestration\Snapshot\Command
 */
class DeployCodeCommand
    implements DeployCommandInterface, ApplicationCodeDeployerAwareInterface, LoggerAwareInterface
{
    /**
     * @var ApplicationCodeDeployer
     */
    private $applicationCodeDeployer;
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
        if (!$buildId && !$repoReference) {
            $this->logger->notice(
                'Add condition "code" to this step in your deployment plan. This step can only be run when deploying '
                . 'code. Skipped.'
            );
            return null;
        }

        if (!isset($this->applicationCodeDeployer)) {
            throw new Exception\RuntimeException('$this->applicationCodeDeployer must be set.');
        }

        // @todo Deal with update and stash arguments
        $this->applicationCodeDeployer->deployCode($buildId, $buildPath, $repoReference);
        return null;
    }

    /**
     * @inheritdoc
     */
    public function setApplicationCodeDeployer(ApplicationCodeDeployer $applicationCodeDeployer): void
    {
        $this->applicationCodeDeployer = $applicationCodeDeployer;
    }

    /**
     * @inheritdoc
     */
    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }
}
