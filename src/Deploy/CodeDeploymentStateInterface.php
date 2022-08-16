<?php

namespace ConductorAppOrchestration\Deploy;

interface CodeDeploymentStateInterface
{
    public function codeDeployed(): bool;
}
