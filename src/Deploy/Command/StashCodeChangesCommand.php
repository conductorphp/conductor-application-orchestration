<?php

namespace ConductorAppOrchestration\Deploy\Command;

use ConductorCore\Repository\RepositoryAdapterAwareInterface;
use ConductorCore\Repository\RepositoryAdapterInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Class StashCodeChangesCommand
 *
 * @package ConductorAppOrchestration\Deploy\Command
 */
class StashCodeChangesCommand
    implements DeployCommandInterface, RepositoryAdapterAwareInterface, LoggerAwareInterface
{
    /**
     * @var RepositoryAdapterInterface
     */
    private $repositoryAdapter;
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
        if (!$repoReference) {
            $this->logger->notice(
                'Add condition "code-repo" to this step in your deployment plan. This step can only be run when '
                . 'deploying code from a repo. Skipped.'
            );
            return null;
        }

        $this->repositoryAdapter->setPath($codeRoot);
        if (!$this->repositoryAdapter->isClean()) {
            $this->logger->info('Stashing code changes.');
            $this->repositoryAdapter->stash('Conductor stash');
        }

        return null;
    }

    /**
     * @inheritdoc
     */
    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * @inheritdoc
     */
    public function setRepositoryAdapter(RepositoryAdapterInterface $repositoryAdapter): void
    {
        $this->repositoryAdapter = $repositoryAdapter;
    }
}
