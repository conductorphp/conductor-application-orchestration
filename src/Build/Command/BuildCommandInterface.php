<?php

namespace ConductorAppOrchestration\Build\Command;

interface BuildCommandInterface
{
    public function run(string $repoReference, string $buildId, string $savePath, array $options = null): ?string;
}
