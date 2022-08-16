<?php

namespace ConductorAppOrchestration\Deploy;

interface ApplicationSkeletonDeployerAwareInterface
{
    /**
     * @param ApplicationSkeletonDeployer $applicationSkeletonDeployer
     */
    public function setApplicationSkeletonDeployer(ApplicationSkeletonDeployer $applicationSkeletonDeployer): void;
}
