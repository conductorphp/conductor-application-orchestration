<?php

namespace ConductorAppOrchestration\Config;

use ConductorCore\Config\ConfigDirectory;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

class ApplicationConfigFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null): ApplicationConfig
    {
        $config = $container->get('config');

        $environment = $config['environment'] ?? 'development';
        // The launcher puts the resolved directory in config (`conductor.config_directory`) as well
        // as in the container; config is read here because the reflection abstract factory makes
        // has(ConfigDirectory::class) true even when nothing registered one.
        if (!empty($config['conductor']['config_directory'])) {
            $configDirectory     = new ConfigDirectory($config['conductor']['config_directory']);
            $sourceFilePathStack = [
                $configDirectory->environmentFilesPath($environment),
                $configDirectory->filesPath(),
            ];
        } else {
            // A host from before conductor/conductor: the configuration sits five levels above this
            // file in the composer vendor tree. The launcher there registers no ConfigDirectory.
            $conductorRoot       = dirname(__DIR__, 5);
            $sourceFilePathStack = [
                $conductorRoot . '/config/app/environments/' . $environment . '/files',
                $conductorRoot . '/config/app/files',
            ];
        }
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

            // Merge in platform config. A platform that no installed module registers is not refused
            // here: that would fail every command at boot, `list` included. PlatformManager refuses it
            // at the first use of a platform service, and PlanRunner asks before its first step.
            if (isset($config['application_orchestration']['platforms'][$application['platform']])) {
                $platformConfig = $config['application_orchestration']['platforms'][$application['platform']];

                if (!empty($platformConfig['source_file_path'])) {
                    $sourceFilePathStack[] = $platformConfig['source_file_path'];
                    unset($platformConfig['source_file_path']);
                }
                // The module's service registrations are PlatformManager's, not application config.
                unset($platformConfig['services']);

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

        // The logger is passed in rather than pushed in later with setLogger(): ApplicationConfig is
        // readonly, and it needs a logger during construction to warn about deprecated snapshot keys.
        return new ApplicationConfig(
            $applicationConfig,
            $container->has(LoggerInterface::class) ? $container->get(LoggerInterface::class) : null,
        );
    }
}

