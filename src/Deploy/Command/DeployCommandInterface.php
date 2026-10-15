<?php

namespace ConductorAppOrchestration\Deploy\Command;

interface DeployCommandInterface
{
    /**
     * @param bool $allowFullRollback From `app:deploy --allow-full-rollback`: keep what is needed to put the
     *                                environment back, even where the plan opts out. A step that destroys
     *                                nothing has nothing to keep and ignores it; DeployDatabasesCommand
     *                                takes its pre-restore dump.
     */
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
    ): ?string;
}

