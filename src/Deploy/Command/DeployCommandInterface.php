<?php

namespace ConductorAppOrchestration\Deploy\Command;

interface DeployCommandInterface
{
    /**
     * @param string $codeRoot
     * @param string|null $buildId
     * @param string|null $buildPath
     * @param string|null $branch
     * @param string|null $snapshotName
     * @param string|null $snapshotPath
     * @param bool        $includeAssets
     * @param array       $assetSyncConfig
     * @param bool        $includeDatabases
     * @param bool        $allowFullRollback
     * @param array|null  $options
     *
     * @return null|string
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
    ): ?string;
}

