<?php

namespace ConductorAppOrchestration\Deploy;

interface ApplicationDatabaseDeployerAwareInterface
{
    /**
     * @param ApplicationDatabaseDeployer $applicationDatabaseDeployer
     */
    public function setApplicationDatabaseDeployer(ApplicationDatabaseDeployer $applicationDatabaseDeployer): void;
}
