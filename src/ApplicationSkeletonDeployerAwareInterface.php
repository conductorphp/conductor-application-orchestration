<?php

namespace ConductorAppOrchestration;

interface ApplicationSkeletonDeployerAwareInterface
{
    public function setApplicationSkeletonDeployer(ApplicationSkeletonDeployer $applicationSkeletonDeployer): void;
}
