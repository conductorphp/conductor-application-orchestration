<?php

namespace ConductorAppOrchestration\Snapshot\Command;

interface SnapshotCommandInterface
{
    /**
     * @param string $repoReference
     * @param string $buildId
     * @return string|null
     */
    public function run(bool $includeAssets, bool $includeDatabases): ?string;
}
