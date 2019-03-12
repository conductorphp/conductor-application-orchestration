<?php

namespace ConductorAppOrchestration\Config;

use ConductorAppOrchestration\Exception;
use Interop\Container\ContainerInterface;
use Interop\Container\Exception\ContainerException;
use Zend\ServiceManager\Exception\ServiceNotCreatedException;
use Zend\ServiceManager\Exception\ServiceNotFoundException;
use Zend\ServiceManager\Factory\FactoryInterface;

class ApplicationConfigFactory implements FactoryInterface
{

    const PLATFORM_CUSTOM = 'custom';

    /**
     * Create an object
     *
     * @param  ContainerInterface $container
     * @param  string             $requestedName
     * @param  null|array         $options
     *
     * @return object
     * @throws ServiceNotFoundException if unable to resolve the service.
     * @throws ServiceNotCreatedException if an exception is raised when
     *     creating a service.
     * @throws ContainerException if any other error occurs
     */
    public function __invoke(ContainerInterface $container, $requestedName, array $options = null)
    {
        $config = $container->get('config');

        $zendExpressiveRoot = realpath(__DIR__ . '/../../../../..');
        $environment = $config['environment'] ?? 'development';
        $sourceFilePathStack = [
            $zendExpressiveRoot . '/config/app/environments/' . $environment . '/files',
            $zendExpressiveRoot . '/config/app/files',
        ];
        $application = [
            'environment' => $environment,
        ];

        if (isset($config['application_orchestration']['application'])) {
            $application = array_replace_recursive($config['application_orchestration']['application'], $application);

            // Merge in environment config and set current environment
            if (isset($application['environments'][$environment])) {
                $environmentConfig = $application['environments'][$environment];
                $application = array_replace_recursive($application, $environmentConfig);
            }
            unset($application['environments']);

            // Merge in platform config
            if (self::PLATFORM_CUSTOM  != $application['platform']
                && !isset($config['application_orchestration']['platforms'][$application['platform']])
            ) {
                throw new Exception\RuntimeException(sprintf('Platform configured as "%s", but there '
                    . 'is no "application_orchestration/platforms/%s" configuration key defined. You '
                    . 'likely need to include the correct platform support package. '
                    . 'See https://github.com/conductorphp/?q=platform-support',
                    $application['platform'],
                    $application['platform']
                ));
            }

            if (isset($config['application_orchestration']['platforms'][$application['platform']])) {
                $platformConfig = $config['application_orchestration']['platforms'][$application['platform']];

                if (!empty($platformConfig['source_file_path'])) {
                    $sourceFilePathStack[] = $platformConfig['source_file_path'];
                    unset($platformConfig['source_file_path']);
                }

                $application = array_replace_recursive($platformConfig, $application);
            }
        }

        $applicationConfig = array_replace_recursive(
            $config['application_orchestration']['defaults'],
            $application
        );
        $applicationConfig['source_file_paths'] = $sourceFilePathStack;

        return new ApplicationConfig($applicationConfig);
    }

}

