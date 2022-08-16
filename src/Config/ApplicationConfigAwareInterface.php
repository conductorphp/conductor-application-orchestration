<?php

namespace ConductorAppOrchestration\Config;

interface ApplicationConfigAwareInterface
{
    public function setApplicationConfig(ApplicationConfig $applicationConfig): void;
}
