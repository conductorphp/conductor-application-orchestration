<?php

namespace ConductorAppOrchestration\Build\Command;

interface BuildCommandInterface
{
    /**
     * @param string     $branch
     * @param string     $buildId
     * @param string     $savePath
     * @param array|null $options
     *
     * @return null|string
     */
    public function run(string $branch, string $buildId, string $savePath, array $options = null): ?string;
}
