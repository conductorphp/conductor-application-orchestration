<?php

namespace ConductorAppOrchestration\Deploy;

interface ApplicationCodeDeployerAwareInterface
{
    public function setApplicationCodeDeployer(ApplicationCodeDeployer $applicationCodeDeployer): void;
}
