<?php

namespace ConductorAppOrchestration\Deploy;

interface ApplicationDatabaseDeployerAwareInterface
{
    public function setApplicationDatabaseDeployer(ApplicationDatabaseDeployer $applicationDatabaseDeployer): void;
}
