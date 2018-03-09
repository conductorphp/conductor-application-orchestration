<?php

namespace ConductorAppOrchestration\Build\Command;

interface BuildCommandInterface
{
    /**
     * @param string $repoReference
     * @param string $buildId
     * @param string $savePath
     * @param array|null $options
     */
    public function run(string $repoReference, string $buildId, string $savePath, array $options = null): void;
}
