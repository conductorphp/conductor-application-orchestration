<?php

namespace DevopsToolAppOrchestration;

use DevopsToolMySqlSupport\Exception;
use Interop\Container\ContainerInterface;
use Interop\Container\Exception\ContainerException;
use Zend\ServiceManager\Exception\ServiceNotCreatedException;
use Zend\ServiceManager\Exception\ServiceNotFoundException;
use Zend\ServiceManager\Factory\FactoryInterface;

class ApplicationConfigFactory implements FactoryInterface
{

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
        if (!isset($config['application_orchestration']['application'])) {
            throw new Exception\RuntimeException(
                '"application_orchestration/application" key must be set in configuration.'
            );
        }

        if (!isset($config['environment'])) {
            throw new Exception\RuntimeException('"environment" key must be set in configuration.');
        }

        $application = $config['application_orchestration']['application'];
        $environment = $config['environment'];
        $zendExpressiveRoot = realpath(__DIR__ . '/../../../..');
        $sourceFilePathStack = [
            $zendExpressiveRoot . '/config/autoload/environments/ ' . $environment . '/files',
            $zendExpressiveRoot . '/config/autoload/files',
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

        $applicationConfig = array_replace_recursive($config['application_orchestration']['defaults'], $application);
        $applicationConfig['source_file_paths'] = $sourceFilePathStack;

        return new ApplicationConfig($applicationConfig);
    }

}

