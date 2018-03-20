<?php

namespace ConductorAppOrchestration;

interface ApplicationDatabaseDeployerAwareInterface
{
    public function setApplicationDatabaseDeployer(ApplicationDatabaseDeployer $applicationDatabaseDeployer): void;
}
