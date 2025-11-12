<?php

namespace ConductorAppOrchestration\Deploy;

interface ApplicationSkeletonDeployerAwareInterface
{
    public function setApplicationSkeletonDeployer(ApplicationSkeletonDeployer $applicationSkeletonDeployer): void;
}
