<?php

namespace ConductorAppOrchestration\Deploy;

class NoCodeDeploymentState implements CodeDeploymentStateInterface
{
    /**
     * @inheritdoc
     */
    public function codeDeployed(): bool
    {
        throw new \LogicException('No code deployment state strategy set.');
    }
}

