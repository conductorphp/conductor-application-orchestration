<?php

declare(strict_types=1);

namespace ConductorAppOrchestrationTest\Platform;

use ConductorAppOrchestration\Exception\PlatformNotInstalledException;
use ConductorAppOrchestration\Maintenance\MaintenanceStrategyInterface;
use ConductorAppOrchestration\Platform\PlatformCodeDeploymentState;
use ConductorAppOrchestration\Platform\PlatformMaintenanceStrategy;
use ConductorAppOrchestration\Platform\PlatformManager;
use Laminas\ServiceManager\ServiceManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class PlatformMaintenanceStrategyTest extends TestCase
{
    public function testResolvesOnFirstCallAndForwardsTheLoggerSetBeforeIt(): void
    {
        $logger   = new NullLogger();
        $strategy = new class implements MaintenanceStrategyInterface, LoggerAwareInterface {
            public ?LoggerInterface $logger = null;
            public int $enabled = 0;

            public function setLogger(LoggerInterface $logger): void
            {
                $this->logger = $logger;
            }

            public function enable(?string $buildId = null): void
            {
                $this->enabled++;
            }

            public function disable(?string $buildId = null): void
            {
            }

            public function isEnabled(?string $buildId = null): bool
            {
                return $this->enabled > 0;
            }
        };

        $container = new ServiceManager(['services' => ['test.maintenance' => $strategy]]);
        $manager   = new PlatformManager($container, 'p', [
            'p' => [PlatformManager::SERVICE_MAINTENANCE_STRATEGY => 'test.maintenance'],
        ]);

        $proxy = new PlatformMaintenanceStrategy($manager);
        $proxy->setLogger($logger);
        $this->assertNull($strategy->logger, 'not resolved by setLogger()');

        $proxy->enable('build-1');
        $this->assertSame($logger, $strategy->logger, 'the logger set before resolution reaches the real strategy');
        $this->assertTrue($proxy->isEnabled());
        $this->assertSame(1, $strategy->enabled);
    }

    public function testAnUninstalledPlatformIsRefusedOnTheFirstCallNotAtConstruction(): void
    {
        $manager = new PlatformManager(new ServiceManager(), 'drupal', []);

        $maintenance = new PlatformMaintenanceStrategy($manager);
        $codeState   = new PlatformCodeDeploymentState($manager);
        $maintenance->setLogger(new NullLogger());

        $this->expectException(PlatformNotInstalledException::class);
        $codeState->codeDeployed();
    }
}
