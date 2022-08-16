<?php

namespace ConductorAppOrchestration\Deploy;

interface ApplicationCodeDeployerAwareInterface
{
    /**
     * @param ApplicationCodeDeployer $applicationCodeDeployer
     */
    public function setApplicationCodeDeployer(ApplicationCodeDeployer $applicationCodeDeployer): void;
}
