<?php

namespace ConductorAppOrchestration;

interface ApplicationDatabaseDeployerAwareInterface
{
    /**
     * @param ApplicationDatabaseDeployer $applicationDatabaseDeployer
     */
    public function setApplicationDatabaseDeployer(ApplicationDatabaseDeployer $applicationDatabaseDeployer): void;
}
