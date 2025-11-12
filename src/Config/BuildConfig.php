<?php

namespace ConductorAppOrchestration\Config;

use ConductorAppOrchestration\Exception;

class BuildConfig
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

    public function getPlans(): array
    {
        return $this->config['plans'] ?? [];
    }

    public function getDefaultPlan(): string
    {
        return $this->config['default_plan'] ?? 'default';
    }

}
