<?php

namespace ConductorAppOrchestration;

interface ApplicationSkeletonDeployerAwareInterface
{
    /**
     * @param ApplicationSkeletonDeployer $applicationSkeletonDeployer
     */
    public function setApplicationSkeletonDeployer(ApplicationSkeletonDeployer $applicationSkeletonDeployer): void;
}
