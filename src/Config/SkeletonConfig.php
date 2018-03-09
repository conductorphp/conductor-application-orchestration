<?php
/**
 * @author Kirk Madera <kmadera@robofirm.com>
 */

namespace ConductorAppOrchestration\Config;

use ConductorAppOrchestration\Exception;

class SkeletonConfig
{
    /**
     * @var array
     */
    private $config;

    /**
     * SkeletonConfig constructor.
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
    public function getDirectories(): array
    {
        return $this->config['directories'] ?? [];
    }

    /**
     * @return array
     */
    public function getFiles(): array
    {
        return $this->config['files'] ?? [];
    }

    /**
     * @return array
     */
    public function getSymlinks(): array
    {
        return $this->config['symlinks'] ?? [];
    }

}
