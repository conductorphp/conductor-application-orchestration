<?php

namespace ConductorAppOrchestration;

interface ApplicationAssetDeployerAwareInterface
{
    /**
     * @param ApplicationAssetDeployer $applicationAssetDeployer
     */
    public function setApplicationAssetDeployer(ApplicationAssetDeployer $applicationAssetDeployer): void;
}
