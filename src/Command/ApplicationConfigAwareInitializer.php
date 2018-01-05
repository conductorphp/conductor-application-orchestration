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
                if (isset($application['environments'][$environment])) {
                    $environmentConfig = $application['environments'][$environment];
                    $application = array_replace_recursive($application, $environmentConfig);
                }
                unset($application['environments']);
                $applicationConfig[$applicationCode] = array_replace_recursive($config['application_orchestration']['defaults'], $application);
            }

            $instance->setApplicationConfig($applicationConfig);
        }
    }
}
