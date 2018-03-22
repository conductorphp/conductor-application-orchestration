<?php

namespace ConductorAppOrchestration;

interface ApplicationCodeDeployerAwareInterface
{
    /**
     * @param ApplicationCodeDeployer $applicationCodeDeployer
     */
    public function setApplicationCodeDeployer(ApplicationCodeDeployer $applicationCodeDeployer): void;
}
