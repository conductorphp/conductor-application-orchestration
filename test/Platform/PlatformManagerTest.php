<?php

declare(strict_types=1);

namespace ConductorAppOrchestrationTest\Platform;

use ConductorAppOrchestration\Deploy\CodeDeploymentStateInterface;
use ConductorAppOrchestration\Deploy\DefaultCodeDeploymentStateStrategy;
use ConductorAppOrchestration\Exception\DomainException;
use ConductorAppOrchestration\Exception\PlatformNotInstalledException;
use ConductorAppOrchestration\Maintenance\DefaultMaintenanceStrategy;
use ConductorAppOrchestration\Maintenance\MaintenanceStrategyInterface;
use ConductorAppOrchestration\Platform\PlatformManager;
use Laminas\ServiceManager\ServiceManager;
use PHPUnit\Framework\TestCase;
use stdClass;

final class PlatformManagerTest extends TestCase
{
    private const MAGENTO_MAINTENANCE = 'test.magento2.maintenance';
    private const MAGENTO_CODE_STATE  = 'test.magento2.code_state';
    private const SYMFONY_CODE_STATE  = 'test.symfony4.code_state';

    private ServiceManager $container;
    private MaintenanceStrategyInterface $magentoMaintenance;
    private CodeDeploymentStateInterface $magentoCodeState;

    protected function setUp(): void
    {
        $this->magentoMaintenance = $this->createStub(MaintenanceStrategyInterface::class);
        $this->magentoCodeState   = $this->createStub(CodeDeploymentStateInterface::class);

        $this->container = new ServiceManager(['services' => [
            DefaultMaintenanceStrategy::class         => new DefaultMaintenanceStrategy(),
            DefaultCodeDeploymentStateStrategy::class => $this->createStub(DefaultCodeDeploymentStateStrategy::class),
            self::MAGENTO_MAINTENANCE                 => $this->magentoMaintenance,
            self::MAGENTO_CODE_STATE                  => $this->magentoCodeState,
            self::SYMFONY_CODE_STATE                  => $this->createStub(CodeDeploymentStateInterface::class),
            'test.not-a-strategy'                     => new stdClass(),
        ]]);
    }

    /** @return array<string, array<string, string>> */
    private function registrations(): array
    {
        return [
            'magento2' => [
                PlatformManager::SERVICE_MAINTENANCE_STRATEGY  => self::MAGENTO_MAINTENANCE,
                PlatformManager::SERVICE_CODE_DEPLOYMENT_STATE => self::MAGENTO_CODE_STATE,
            ],
            'symfony4' => [
                PlatformManager::SERVICE_CODE_DEPLOYMENT_STATE => self::SYMFONY_CODE_STATE,
            ],
            'broken'   => [
                PlatformManager::SERVICE_MAINTENANCE_STRATEGY => 'test.not-a-strategy',
            ],
        ];
    }

    private function manager(string $platform): PlatformManager
    {
        return new PlatformManager($this->container, $platform, $this->registrations());
    }

    public function testCustomResolvesToTheDefaults(): void
    {
        $manager = $this->manager('custom');

        $this->assertTrue($manager->isInstalled());
        $this->assertInstanceOf(DefaultMaintenanceStrategy::class, $manager->getMaintenanceStrategy());
        $this->assertInstanceOf(DefaultCodeDeploymentStateStrategy::class, $manager->getCodeDeploymentState());
    }

    public function testARegisteredPlatformResolvesToItsOwnServices(): void
    {
        $manager = $this->manager('magento2');

        $this->assertSame($this->magentoMaintenance, $manager->getMaintenanceStrategy());
        $this->assertSame($this->magentoCodeState, $manager->getCodeDeploymentState());
    }

    public function testARoleThePlatformDoesNotRegisterFallsBackToTheDefault(): void
    {
        $manager = $this->manager('symfony4');

        $this->assertInstanceOf(DefaultMaintenanceStrategy::class, $manager->getMaintenanceStrategy());
        $this->assertSame($this->container->get(self::SYMFONY_CODE_STATE), $manager->getCodeDeploymentState());
    }

    public function testEachRoleIsResolvedOnce(): void
    {
        $manager = $this->manager('custom');

        $this->assertSame($manager->getMaintenanceStrategy(), $manager->getMaintenanceStrategy());
    }

    public function testPlatformNamesAreTheRegistrationsPlusCustom(): void
    {
        $this->assertSame(['broken', 'custom', 'magento2', 'symfony4'], $this->manager('custom')->getPlatformNames());
    }

    public function testAnUnregisteredPlatformIsRefusedAtFirstUseNamingTheModule(): void
    {
        $manager = $this->manager('drupal');

        // Construction and the inspection methods never refuse; only resolving a service does.
        $this->assertFalse($manager->isInstalled());
        $this->assertSame('drupal', $manager->getPlatform());

        $this->expectException(PlatformNotInstalledException::class);
        $this->expectExceptionMessage(
            'application.platform is "drupal", but no installed module registers that platform.'
            . ' Install conductor/drupal-platform-support, or set application.platform to one of:'
            . ' broken, custom, magento2, symfony4.'
        );
        $manager->getMaintenanceStrategy();
    }

    public function testAnUnknownPlatformIsRefusedWithoutNamingAModule(): void
    {
        $this->expectException(PlatformNotInstalledException::class);
        $this->expectExceptionMessage('Install the platform-support module that registers it, or set');
        $this->manager('shopify')->assertInstalled();
    }

    public function testAssertInstalledIsQuietForAnInstalledPlatform(): void
    {
        $this->manager('magento2')->assertInstalled();
        $this->manager('custom')->assertInstalled();
        $this->addToAssertionCount(2);
    }

    public function testARegistrationOfTheWrongTypeIsADomainError(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Platform "broken" registers "test.not-a-strategy" as its maintenance_strategy, but the container returned stdClass');
        $this->manager('broken')->getMaintenanceStrategy();
    }
}
