<?php

namespace ConductorAppOrchestration\Config;

use ConductorAppOrchestration\Exception;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

class ApplicationConfigFactory implements FactoryInterface
{
    private const PLATFORM_CUSTOM = 'custom';

    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null): ApplicationConfig
    {
        $config = $container->get('config');

        $zendExpressiveRoot = dirname(__DIR__, 5);
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
            if (self::PLATFORM_CUSTOM !== $application['platform']
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
        $applicationConfig['source_file_paths'] = array_merge($sourceFilePathStack,
            $config['application_orchestration']['defaults']['source_file_paths']
        );

        return new ApplicationConfig($applicationConfig);
    }
}

