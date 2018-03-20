<?php

namespace ConductorAppOrchestration\Snapshot\Command;

interface SnapshotCommandInterface
{
    /**
     * @param string      $snapshotName
     * @param string      $snapshotPath
     * @param string|null $branch
     * @param bool        $includeDatabases
     * @param bool        $includeAssets
     * @param array       $assetSyncConfig
     * @param array|null  $options
     *
     * @return null|string
     */
    public function run(
        string $snapshotName,
        string $snapshotPath,
        string $branch = null,
        bool $includeDatabases = true,
        bool $includeAssets = true,
        array $assetSyncConfig = [],
        array $options = null
    ): ?string;
}
