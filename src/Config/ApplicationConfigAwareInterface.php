<?php
/**
 * @author Kirk Madera <kirk.madera@rmgmedia.com>
 */

namespace ConductorAppOrchestration\Config;

interface ApplicationConfigAwareInterface
{
    /**
     * @param ApplicationConfig $applicationConfig
     */
    public function setApplicationConfig(ApplicationConfig $applicationConfig): void;
}
