<?php

namespace ConductorAppOrchestration\Deploy;

interface ApplicationAssetDeployerAwareInterface
{
    /**
     * @param ApplicationAssetDeployer $applicationAssetDeployer
     */
    public function setApplicationAssetDeployer(ApplicationAssetDeployer $applicationAssetDeployer): void;
}
