<?php

namespace ConductorAppOrchestrationTest;

use ConductorAppOrchestration\Config\ApplicationConfig;
use Psr\Log\LoggerInterface;

/**
 * Builds a valid {@see ApplicationConfig} for tests.
 *
 * ApplicationConfig validates at construction, so a test cannot hand it a two-key array any more.
 * The four required keys are supplied here and anything else is merged over them, which keeps each
 * test stating only the config it actually cares about.
 */
trait BuildsApplicationConfigTrait
{
    /** @param array<string, mixed> $overrides */
    private function applicationConfig(array $overrides = [], ?LoggerInterface $logger = null): ApplicationConfig
    {
        return new ApplicationConfig($overrides + [
            'app_name'    => 'Test App',
            'app_root'    => '/app',
            'repo_url'    => 'git@example.test:test/app.git',
            'environment' => 'qa',
        ], $logger);
    }
}
