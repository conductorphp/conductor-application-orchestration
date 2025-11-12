<?php

namespace ConductorAppOrchestration;

class ConfigProvider
{
    /**
     * To add a bit of a structure, each section is defined in a separate
     * method which returns an array with its configuration.
     *
     * @return array
     */
    public function __invoke(): array
    {
        return [
            'console' => $this->getConsoleConfig(),
            'dependencies' => $this->getDependencyConfig(),
            'application_orchestration' => $this->getApplicationOrchestrationConfig(),
        ];
    }

    private function getDependencyConfig(): array
    {
        return require(__DIR__ . '/../config/dependencies.php');
    }

    private function getConsoleConfig(): array
    {
        return require(__DIR__ . '/../config/console.php');
    }

    private function getApplicationOrchestrationConfig(): array
    {
        return require(__DIR__ . '/../config/application-orchestration.php');
    }

}
