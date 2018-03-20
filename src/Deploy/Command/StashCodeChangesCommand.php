<?php

namespace ConductorAppOrchestration\Deploy\Command;

use ConductorAppOrchestration\GitElephant\Repository;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Class StashCodeChangesCommand
 *
 * @package ConductorAppOrchestration\Deploy\Command
 */
class StashCodeChangesCommand
    implements DeployCommandInterface, LoggerAwareInterface
{
    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct()
    {
        $this->logger = new NullLogger();
    }

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
        if (!$branch) {
            $this->logger->notice(
                'Add condition "code-repo" to this step in your deployment plan. This step can only be run when '
                .'deploying code from a repo. Skipped.'
            );
            return null;
        }

        // @todo Use RepositoryInterface here to allow for different VCS
        $repo = new Repository($codeRoot);
        if ($repo->isDirty()) {
            $this->logger->info('Stashing code changes.');
            $repo->stash('Conductor stash', true);
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
}
