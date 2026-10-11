<?php

declare(strict_types=1);

namespace ConductorAppOrchestration\Platform;

use ConductorAppOrchestration\Config\ApplicationConfig;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

class PlatformManagerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null): PlatformManager
    {
        $config = $container->get('config');

        $platformServices = [];
        foreach ($config['application_orchestration']['platforms'] ?? [] as $platform => $platformConfig) {
            $platformServices[$platform] = $platformConfig['services'] ?? [];
        }

        // The platform comes from the parsed application config rather than the raw key so an
        // environment override of `platform` is honored the same way everywhere.
        return new PlatformManager(
            $container,
            $container->get(ApplicationConfig::class)->getPlatform(),
            $platformServices,
        );
    }
}
