<?php
/**
 * @author Kirk Madera <kmadera@robofirm.com>
 */

namespace ConductorAppOrchestration\Config;

use ConductorAppOrchestration\Exception;

class DeployConfig
{
    /**
     * @var array
     */
    private $config;

    /**
     * DeployConfig constructor.
     *
     * @param array $config
     */
    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * @return array
     */
    public function toArray(): array
    {
        return $this->config;
    }

    /**
     * @todo Validate by schema instead
     *
     * @throws Exception\RuntimeException
     * @throws Exception\DomainException
     */
    public function validate(): void
    {
        // No required fields
        // @todo Add validation
    }

    /**
     * @return array
     */
    public function getPlans(): array
    {
        return $this->config['plans'] ?? [];
    }

    /**
     * @return string
     */
    public function getDefaultPlan(): string
    {
        return $this->config['default_plan'] ?? 'default';
    }

}
