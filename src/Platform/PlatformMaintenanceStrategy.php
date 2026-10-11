<?php

declare(strict_types=1);

namespace ConductorAppOrchestration\Platform;

use ConductorAppOrchestration\Maintenance\MaintenanceStrategyInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;

/**
 * The {@see MaintenanceStrategyInterface} the container hands out: the configured platform's strategy,
 * resolved on first use.
 *
 * Every console command is constructed at boot, and `AppMaintenanceCommand` and `PlanRunner` take the
 * strategy in their constructors, so resolving it eagerly would make an uninstalled platform fail
 * `list`. Resolving on the first call keeps boot independent of which platform modules are present.
 */
final class PlatformMaintenanceStrategy implements MaintenanceStrategyInterface, LoggerAwareInterface
{
    private ?MaintenanceStrategyInterface $strategy = null;
    private ?LoggerInterface $logger = null;

    public function __construct(private readonly PlatformManager $platformManager)
    {
    }

    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
        if ($this->strategy instanceof LoggerAwareInterface) {
            $this->strategy->setLogger($logger);
        }
    }

    public function enable(?string $buildId = null): void
    {
        $this->strategy()->enable($buildId);
    }

    public function disable(?string $buildId = null): void
    {
        $this->strategy()->disable($buildId);
    }

    public function isEnabled(?string $buildId = null): bool
    {
        return $this->strategy()->isEnabled($buildId);
    }

    private function strategy(): MaintenanceStrategyInterface
    {
        if ($this->strategy === null) {
            $strategy = $this->platformManager->getMaintenanceStrategy();
            if ($this->logger !== null && $strategy instanceof LoggerAwareInterface) {
                $strategy->setLogger($this->logger);
            }
            $this->strategy = $strategy;
        }

        return $this->strategy;
    }
}
