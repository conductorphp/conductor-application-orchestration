<?php

namespace ConductorAppOrchestration\Snapshot\Command;

interface SnapshotCommandInterface
{
    public function run(
        string $snapshotName,
        string $snapshotPath,
        bool   $includeDatabases = true,
        bool   $includeAssets = true,
        array  $assetSyncConfig = [],
        ?array  $options = null
    ): ?string;
}
