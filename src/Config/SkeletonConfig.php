<?php

namespace ConductorAppOrchestration\Config;

use ConductorAppOrchestration\Exception;

class SkeletonConfig
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function toArray(): array
    {
        return $this->config;
    }

    /**
     * @throws Exception\RuntimeException
     * @throws Exception\DomainException
     * @todo Validate by schema instead
     *
     */
    public function validate(): void
    {
        // No required fields
        // @todo Add validation
    }

    public function getDirectories(): array
    {
        return $this->config['directories'] ?? [];
    }

    public function getFiles(): array
    {
        return $this->config['files'] ?? [];
    }

    public function getSymlinks(): array
    {
        return $this->config['symlinks'] ?? [];
    }

}
