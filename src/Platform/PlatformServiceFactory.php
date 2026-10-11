<?php

declare(strict_types=1);

namespace ConductorAppOrchestration\Platform;

use ConductorAppOrchestration\Deploy\CodeDeploymentStateInterface;
use ConductorAppOrchestration\Exception;
use ConductorAppOrchestration\Maintenance\MaintenanceStrategyInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

use function sprintf;

/**
 * Builds the two platform-provided contracts application-orchestration registers under their
 * interface names. The interfaces stay resolvable by name, so every consumer keeps its constructor;
 * what changes is that the binding is per configured platform instead of last-ConfigProvider-wins.
 */
class PlatformServiceFactory implements FactoryInterface
{
    public function __invoke(
        ContainerInterface $container,
        $requestedName,
        ?array $options = null
    ): MaintenanceStrategyInterface|CodeDeploymentStateInterface {
        $platformManager = $container->get(PlatformManager::class);

        return match ($requestedName) {
            MaintenanceStrategyInterface::class => new PlatformMaintenanceStrategy($platformManager),
            CodeDeploymentStateInterface::class => new PlatformCodeDeploymentState($platformManager),
            default => throw new Exception\DomainException(sprintf(
                '%s builds %s and %s, not "%s".',
                self::class,
                MaintenanceStrategyInterface::class,
                CodeDeploymentStateInterface::class,
                $requestedName,
            )),
        };
    }
}
