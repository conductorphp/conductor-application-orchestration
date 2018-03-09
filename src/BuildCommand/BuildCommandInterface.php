<?php

namespace ConductorAppOrchestration\BuildCommand;

interface BuildCommandInterface
{
    /**
     * @param string $repoReference
     * @param string $buildId
     * @return string|null
     */
    public function run(string $repoReference, string $buildId): ?string;
}
