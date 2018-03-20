<?php

namespace ConductorAppOrchestration;

interface ApplicationAssetDeployerAwareInterface
{
    public function setApplicationAssetDeployer(ApplicationAssetDeployer $applicationAssetDeployer): void;
}
