<?php

namespace ConductorAppOrchestration\Deploy\Command;

use ConductorAppOrchestration\Deploy\ApplicationCodeDeployer;
use ConductorAppOrchestration\Deploy\ApplicationCodeDeployerAwareInterface;
use ConductorAppOrchestration\Exception;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class DeployCodeCommand
    implements DeployCommandInterface, ApplicationCodeDeployerAwareInterface, LoggerAwareInterface
{
    private ApplicationCodeDeployer $applicationCodeDeployer;
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

        $this->applicationCodeDeployer->deployCode($buildId, $buildPath, $repoReference);
        return null;
    }

    public function setApplicationCodeDeployer(ApplicationCodeDeployer $applicationCodeDeployer): void
    {
        $this->applicationCodeDeployer = $applicationCodeDeployer;
    }

    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }
}
