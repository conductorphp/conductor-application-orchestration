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
            'console'      => $this->getConsole(),
            'dependencies' => $this->getDependencies(),
            'app_setup'    => $this->getAppSetup(),
        ];
    }

    /**
     * Returns the container dependencies
     *
     * @return array
     */
    private function getDependencies()
    {
        return require(__DIR__ . '/../config/dependencies.php');
    }

    /**
     * @return array
     */
    private function getConsole()
    {
        return require(__DIR__ . '/../config/console.php');
    }

    /**
     * @return array
     */
    private function getAppSetup()
    {
        return Yaml::parse(file_get_contents(__DIR__ . '/../config/app-setup-defaults.yaml'));
    }

}
