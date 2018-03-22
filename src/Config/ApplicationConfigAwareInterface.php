<?php
/**
 * @author Kirk Madera <kmadera@robofirm.com>
 */

namespace ConductorAppOrchestration\Config;

interface ApplicationConfigAwareInterface
{
    /**
     * @param ApplicationConfig $applicationConfig
     */
    public function setApplicationConfig(ApplicationConfig $applicationConfig): void;
}
