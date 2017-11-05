<?php

namespace DevopsToolAppOrchestration\Command;

use Interop\Container\ContainerInterface;
use Zend\ServiceManager\Initializer\InitializerInterface;

class ApplicationOrchestrationConfigAwareInitializer implements InitializerInterface
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
        if (!$instance instanceof ApplicationOrchestrationConfigAwareInterface) {
            return;
        }

        $config = $container->get('config');
        if (isset($config['application_orchestration'])) {
            $instance->setApplicationOrchestrationConfig($config['application_orchestration']);
        }
    }
}
