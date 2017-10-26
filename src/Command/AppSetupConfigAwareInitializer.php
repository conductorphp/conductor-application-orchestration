<?php

namespace DevopsToolAppOrchestration\Command;

use Interop\Container\ContainerInterface;
use Zend\ServiceManager\Initializer\InitializerInterface;

class AppSetupConfigAwareInitializer implements InitializerInterface
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
        if (!$instance instanceof AppSetupConfigAwareInterface) {
            return;
        }

        $config = $container->get('config');
        if (isset($config['app_setup'])) {
            $instance->setAppSetupConfig($config['app_setup']);
        }
    }
}
