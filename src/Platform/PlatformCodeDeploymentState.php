<?php

declare(strict_types=1);

namespace ConductorAppOrchestration\Platform;

use ConductorAppOrchestration\Deploy\CodeDeploymentStateInterface;

/**
 * The {@see CodeDeploymentStateInterface} the container hands out: the configured platform's
 * implementation, resolved on first use. See {@see PlatformMaintenanceStrategy} for why not at boot.
 */
final class PlatformCodeDeploymentState implements CodeDeploymentStateInterface
{
    private ?CodeDeploymentStateInterface $state = null;

    public function __construct(private readonly PlatformManager $platformManager)
    {
    }

    public function codeDeployed(): bool
    {
        $this->state ??= $this->platformManager->getCodeDeploymentState();

        return $this->state->codeDeployed();
    }
}
