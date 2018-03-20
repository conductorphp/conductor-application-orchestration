<?php

namespace ConductorAppOrchestration;

interface ApplicationCodeDeployerAwareInterface
{
    public function setApplicationCodeDeployer(ApplicationCodeDeployer $applicationCodeDeployer): void;
}
