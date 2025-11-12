<?php

namespace ConductorAppOrchestration\Deploy;

use ConductorAppOrchestration\Config\ApplicationConfig;
use ConductorCore\Shell\Adapter\ShellAdapterInterface;
use Exception;

class DefaultCodeDeploymentStateStrategy implements CodeDeploymentStateInterface
{
    private ApplicationConfig $applicationConfig;
    private ShellAdapterInterface $shellAdapter;

    public function __construct(ApplicationConfig $applicationConfig, ShellAdapterInterface $shellAdapter)
    {
        $this->applicationConfig = $applicationConfig;
        $this->shellAdapter = $shellAdapter;
    }

    public function codeDeployed(): bool
    {
        $codeRoot = $this->applicationConfig->getCurrentPath();
        $command = '[[ -f ' . escapeshellcmd("$codeRoot/public/index.php") . ' ]] || exit 1';
        try {
            $this->shellAdapter->runShellCommand($command);
        } catch (Exception $e) {
            return false;
        }
        return true;
    }
}
