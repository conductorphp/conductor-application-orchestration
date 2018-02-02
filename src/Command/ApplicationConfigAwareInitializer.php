<?php

namespace DevopsToolAppOrchestration\Command;

use Interop\Container\ContainerInterface;
use Zend\ServiceManager\Initializer\InitializerInterface;
use DevopsToolAppOrchestration\Exception;

class ApplicationConfigAwareInitializer implements InitializerInterface
{
    /**
     * Initialize the given instance
     *
     * @param  ContainerInterface $container
     * @param  object             $instance
     *
     * @return void
     */
    public function __invoke(ContainerInterface $container, $instance)
    {
        if (!$instance instanceof ApplicationConfigAwareInterface) {
            return;
        }

        $config = $container->get('config');
        if (isset($config['application_orchestration']['applications'])) {
            if (!isset($config['environment'])) {
                throw new Exception\RuntimeException('Environment must be set in configuration.');
            }
            $environment = $config['environment'];
            $applicationConfig = [];
            foreach ($config['application_orchestration']['applications'] as $applicationCode => $application) {
                $sourceFilePathStack = [
                    "{$application['config_root']}/environments/$environment/files",
                    "{$application['config_root']}/files",
                ];

                // Merge in environment config and set current environment
                if (isset($application['environments'][$environment])) {
                    $environmentConfig = $application['environments'][$environment];
                    $application = array_replace_recursive($application, $environmentConfig);
                }
                unset($application['environments']);
                $application['current_environment'] = $environment;

                // Merge in platform config
                if (isset($config['application_orchestration']['platforms'][$application['platform']])) {
                    $platformConfig = $config['application_orchestration']['platforms'][$application['platform']];
                    if (!empty($platformConfig['source_file_path'])) {
                        $sourceFilePathStack[] = $platformConfig['source_file_path'];
                        unset($platformConfig['source_file_path']);
                    }
                    $application = array_replace_recursive($platformConfig, $application);
                }

                $applicationConfig[$applicationCode] = array_replace_recursive($config['application_orchestration']['defaults'], $application);
                $applicationConfig[$applicationCode]['source_file_paths'] = $sourceFilePathStack;
            }

            $instance->setApplicationConfig($applicationConfig);
        }
    }
}
