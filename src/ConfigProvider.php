<?php

namespace DevopsToolAppOrchestration;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Yaml\Yaml;
use Zend\ConfigAggregator\GlobTrait;

class ConfigProvider
{
    /**
     * Returns the configuration array
     *
     * To add a bit of a structure, each section is defined in a separate
     * method which returns an array with its configuration.
     *
     * @return array
     */
    public function __invoke()
    {
        return [
            'console'                   => $this->getConsoleConfig(),
            'dependencies'              => $this->getDependencyConfig(),
            'application_orchestration' => $this->getApplicationOrchestrationConfig(),
        ];
    }

    /**
     * Returns the container dependencies
     *
     * @return array
     */
    private function getDependencyConfig(): array
    {
        return require(__DIR__ . '/../config/dependencies.php');
    }

    /**
     * @return array
     */
    private function getConsoleConfig(): array
    {
        return require(__DIR__ . '/../config/console.php');
    }

    /**
     * @return array
     */
    private function getApplicationOrchestrationConfig(): array
    {
        $config = require(__DIR__ . '/../config/application-orchestration.php');
        $config['applications'] = $this->getApplicationConfig();
        return $config;
    }

    /**
     * @return array
     */
    private function getApplicationConfig(): array
    {
        $applicationConfig = [];
        $iterator = new \DirectoryIterator('config/autoload/application-orchestration/applications');
        foreach ($iterator as $applicationDir) {
            if ($applicationDir->isDot()) {
                continue;
            }
            $applicationCode = $applicationDir->getBasename();
            $config = Yaml::parse(file_get_contents($applicationDir->getPathname() . '/config.yaml'));
            $config['config_root'] = realpath($applicationDir->getPathname());
            $config['environments'] = [];

            $environmentIterator = new \DirectoryIterator($applicationDir->getPathname() . '/environments');
            foreach ($environmentIterator as $environmentDir) {
                if ($environmentDir->isDot()) {
                    continue;
                }

                $environmentCode = $environmentDir->getBasename();
                $config['environments'][$environmentCode] = Yaml::parse(
                    file_get_contents($environmentDir->getPathname() . '/config.yaml')
                );
            }

            $applicationConfig[$applicationCode] = $config;
        }

        return $applicationConfig;
    }

}
