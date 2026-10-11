<?php

declare(strict_types=1);

namespace ConductorAppOrchestration\Platform;

use ConductorAppOrchestration\Deploy\CodeDeploymentStateInterface;
use ConductorAppOrchestration\Deploy\DefaultCodeDeploymentStateStrategy;
use ConductorAppOrchestration\Exception;
use ConductorAppOrchestration\Maintenance\DefaultMaintenanceStrategy;
use ConductorAppOrchestration\Maintenance\MaintenanceStrategyInterface;
use Psr\Container\ContainerInterface;

use function array_keys;
use function array_merge;
use function get_debug_type;
use function implode;
use function sort;
use function sprintf;

/**
 * Resolves the services a platform-support module contributes, for the configured `application.platform`.
 *
 * A platform module registers its strategies by name under its own platform key and never binds the
 * interface itself:
 *
 *     'application_orchestration' => ['platforms' => ['magento2' => [
 *         'services' => [
 *             PlatformManager::SERVICE_MAINTENANCE_STRATEGY  => AppMaintenanceStrategy::class,
 *             PlatformManager::SERVICE_CODE_DEPLOYMENT_STATE => Deploy\CodeDeploymentState::class,
 *         ],
 *     ]]]
 *
 * Before this, every platform module aliased the same two interfaces, so with two modules installed
 * the winner was whichever ConfigProvider the host listed last, and application-orchestration's own
 * defaults had to be listed before the platform module or they overrode it. Here the choice is the
 * configured platform, and every platform module can be installed at once (CTAP-1757).
 *
 * `custom` is the platform of an application no module describes and resolves to the defaults. A
 * registered platform that omits a service gets the default for that service too (symfony4 has no
 * maintenance strategy). A platform that no installed module registers is refused here, at first
 * use, not at boot, so `list` and `app:config:show` work on a host without the module; a plan run
 * asks {@see assertInstalled()} before its first step.
 */
final class PlatformManager
{
    public const PLATFORM_CUSTOM = 'custom';

    public const SERVICE_MAINTENANCE_STRATEGY  = 'maintenance_strategy';
    public const SERVICE_CODE_DEPLOYMENT_STATE = 'code_deployment_state';

    /** The composer package that registers each platform conductor knows of, for the refusal message. */
    private const KNOWN_MODULES = [
        'clicktap'  => 'conductor/clicktap-platform-support',
        'drupal'    => 'conductor/drupal-platform-support',
        'magento1'  => 'conductor/magento-1-platform-support',
        'magento2'  => 'conductor/magento-2-platform-support',
        'symfony4'  => 'conductor/symfony-4-platform-support',
        'wordpress' => 'conductor/wordpress-platform-support',
    ];

    /** @var array<string, object> Resolved once per role, so every consumer shares one instance. */
    private array $resolved = [];

    /**
     * @param string $platform The configured `application.platform`
     * @param array<string, array<string, string>> $platformServices Registered platform => role => service name
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly string $platform,
        private readonly array $platformServices,
    ) {
    }

    public function getPlatform(): string
    {
        return $this->platform;
    }

    /** @return list<string> What `application.platform` may name: every registered platform plus `custom`. */
    public function getPlatformNames(): array
    {
        $names = array_merge([self::PLATFORM_CUSTOM], array_keys($this->platformServices));
        sort($names);

        return $names;
    }

    public function isInstalled(): bool
    {
        return $this->platform === self::PLATFORM_CUSTOM || isset($this->platformServices[$this->platform]);
    }

    /** @throws Exception\PlatformNotInstalledException When no installed module registers the configured platform */
    public function assertInstalled(): void
    {
        if ($this->isInstalled()) {
            return;
        }

        $install = isset(self::KNOWN_MODULES[$this->platform])
            ? sprintf('Install %s', self::KNOWN_MODULES[$this->platform])
            : 'Install the platform-support module that registers it';

        throw new Exception\PlatformNotInstalledException(sprintf(
            'application.platform is "%s", but no installed module registers that platform. %s, or set'
            . ' application.platform to one of: %s.',
            $this->platform,
            $install,
            implode(', ', $this->getPlatformNames()),
        ));
    }

    public function getMaintenanceStrategy(): MaintenanceStrategyInterface
    {
        return $this->resolve(
            self::SERVICE_MAINTENANCE_STRATEGY,
            DefaultMaintenanceStrategy::class,
            MaintenanceStrategyInterface::class,
        );
    }

    public function getCodeDeploymentState(): CodeDeploymentStateInterface
    {
        return $this->resolve(
            self::SERVICE_CODE_DEPLOYMENT_STATE,
            DefaultCodeDeploymentStateStrategy::class,
            CodeDeploymentStateInterface::class,
        );
    }

    /**
     * @template T of object
     * @param class-string<T> $type
     * @return T
     */
    private function resolve(string $role, string $default, string $type): object
    {
        if (!isset($this->resolved[$role])) {
            $this->assertInstalled();

            $serviceName = $this->platformServices[$this->platform][$role] ?? $default;
            $service     = $this->container->get($serviceName);
            if (!$service instanceof $type) {
                throw new Exception\DomainException(sprintf(
                    'Platform "%s" registers "%s" as its %s, but the container returned %s, which is not a %s.',
                    $this->platform,
                    $serviceName,
                    $role,
                    get_debug_type($service),
                    $type,
                ));
            }

            $this->resolved[$role] = $service;
        }

        return $this->resolved[$role];
    }
}
