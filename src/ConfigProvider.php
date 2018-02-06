<?php

namespace DevopsToolAppOrchestration;

use Symfony\Component\Yaml\Yaml;

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
        $config['application'] = $this->getApplicationConfig();
        return $config;
    }

    /**
     * @return array
     */
    private function getApplicationConfig(): array
    {
        // @todo We should just use php files probably instead. Or make expressive generally ready yaml files. This module
        //       shouldn't reacy out into config/autoload
        $config = Yaml::parse(file_get_contents('config/autoload/application-orchestration/config.yaml'));
        $config['config_root'] = realpath('config/autoload/application-orchestration');
        $config['environments'] = [];

        $environmentIterator = new \DirectoryIterator('config/autoload/application-orchestration/environments');
        foreach ($environmentIterator as $environmentDir) {
            if ($environmentDir->isDot()) {
                continue;
            }

            $environmentCode = $environmentDir->getBasename();
            $config['environments'][$environmentCode] = Yaml::parse(
                file_get_contents($environmentDir->getPathname() . '/config.yaml')
            );
        }

        return $config;
    }

}
