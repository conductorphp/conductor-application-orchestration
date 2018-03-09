<?php
/**
 * @author Kirk Madera <kmadera@robofirm.com>
 */

namespace ConductorAppOrchestration\Config;

interface ApplicationConfigAwareInterface
{
    public function setApplicationConfig(ApplicationConfig $applicationConfig): void;
}
