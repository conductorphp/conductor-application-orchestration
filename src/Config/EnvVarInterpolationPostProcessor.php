<?php

declare(strict_types=1);

namespace ConductorAppOrchestration\Config;

use ConductorCore\Config\EnvVarInterpolator;

use function array_replace;
use function is_array;
use function is_scalar;

/**
 * Fills `${VAR}` placeholders across the whole merged config, once, at load time.
 *
 * Wired into the project's `config/config.php` as a `ConfigAggregator` post-processor:
 *
 *     $aggregator = new ConfigAggregator([…providers…], $cacheConfig['config_cache_path'], [
 *         new EnvVarInterpolationPostProcessor(),
 *     ]);
 *
 * A post-processor rather than a per-file provider wrapper (the way `ENC[…]` decryption is done)
 * because the fallback variable source lives in the config itself: `application.environment_vars`
 * is spread over `global.yaml` and the environment's own files, and is only complete once they are
 * merged. Running after the merge is also what makes this ONE pass over everything — skeleton
 * `template_vars`, `database.adapters.*`, `filesystem.adapters.*`, `replacements`, and anything
 * else — rather than a substitution each consumer remembers to apply.
 *
 * ## Variable sources, in order
 *
 * 1. The process environment (`.env` in development, the platform's secret store in production).
 * 2. `application_orchestration.application.environment_vars`, overlaid by the current
 *    environment's `application.environments.<env>.environment_vars`. This stays the home for
 *    non-secret constants such as a frontend domain. The values here may themselves reference
 *    process environment variables, and are resolved first.
 *
 * ## What is left alone
 *
 * - **Plan steps.** Everything under `*.plans.<plan>.steps` (and `preflight_steps`, `clean_steps`,
 *   `rollback_*_steps`) is shell text that `PlanRunner` hands to `bash` with the same process
 *   environment merged in, so `${VAR}` there already means what a reader expects — and `${attempt}`
 *   in a retry loop is a shell variable that does not exist at config-load time. Steps can also be
 *   bare command strings with no `command:` key, so the whole step subtree is skipped rather than
 *   one key.
 * - **Other environments.** `application.environments.<name>` for every environment other than
 *   the one being run. A production-only `${PROD_DB_PASSWORD}` must not fail a QA deploy;
 *   {@see ApplicationConfigFactory} discards those sections anyway.
 *
 * ## Ordering relative to `ENC[…]`
 *
 * Decryption happens in the providers, so it runs before this. The two coexist: a value is either
 * an `ENC[…]` string or carries `${VAR}` placeholders, and each mechanism ignores the other's
 * syntax. The one consequence of the order is that a *decrypted* plaintext containing a literal
 * `${NAME}` sequence would be interpolated. Such a value belongs in an environment variable, base64
 * encoded if it must carry that text verbatim.
 *
 * Undefined variables fail the load with an exception naming every variable and config path, so a
 * deploy stops before any plan step runs — see {@see EnvVarInterpolator}.
 */
final class EnvVarInterpolationPostProcessor
{
    /**
     * Plan step subtrees, under any plan of any section (`build`, `deploy`, `snapshot`), whether
     * the plan sits on the application, a platform package, the defaults, or an environment.
     */
    public const SKIP_PATHS = [
        '*.plans.*.steps',
        '*.plans.*.*_steps',
    ];

    private const APPLICATION_PATH = 'application_orchestration.application';

    /**
     * @param array<string, mixed> $config The merged config, as `ConfigAggregator` hands it over.
     * @return array<string, mixed>
     */
    public function __invoke(array $config): array
    {
        $environment = isset($config['environment']) && is_scalar($config['environment'])
            ? (string) $config['environment']
            : null;

        $otherEnvironments = $this->detachOtherEnvironments($config, $environment);

        $interpolator = EnvVarInterpolator::fromProcessEnvironment(
            $this->environmentVars($config, $environment),
            self::SKIP_PATHS,
        );

        $config = $interpolator->interpolate($config);

        foreach ($otherEnvironments as $name => $environmentConfig) {
            $config['application_orchestration']['application']['environments'][$name] = $environmentConfig;
        }

        return $config;
    }

    /**
     * The fallback variables: `application.environment_vars` overlaid by the current environment's.
     *
     * Resolved against the process environment alone before anything else is, so a constant such as
     * `FRONTEND_URL: https://${FRONTEND_DOMAIN}` can lean on the environment but not on its
     * siblings — which keeps resolution a single pass with no ordering or cycles to reason about.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function environmentVars(array $config, ?string $environment): array
    {
        $application = $config['application_orchestration']['application'] ?? [];
        if (! is_array($application)) {
            return [];
        }

        $processEnvironment = EnvVarInterpolator::fromProcessEnvironment();
        $vars               = [];

        if (is_array($application['environment_vars'] ?? null)) {
            $vars = $processEnvironment->interpolate(
                $application['environment_vars'],
                self::APPLICATION_PATH . '.environment_vars',
            );
        }

        if ($environment !== null && is_array($application['environments'][$environment]['environment_vars'] ?? null)) {
            $vars = array_replace($vars, $processEnvironment->interpolate(
                $application['environments'][$environment]['environment_vars'],
                EnvVarInterpolator::childPath(self::APPLICATION_PATH . '.environments', $environment)
                . '.environment_vars',
            ));
        }

        return $vars;
    }

    /**
     * Remove every `application.environments.<name>` other than the current one, returning them so
     * the caller can put them back untouched after the pass.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function detachOtherEnvironments(array &$config, ?string $environment): array
    {
        $environments = $config['application_orchestration']['application']['environments'] ?? null;
        if (! is_array($environments)) {
            return [];
        }

        $others = [];
        foreach ($environments as $name => $environmentConfig) {
            if ((string) $name === $environment) {
                continue;
            }

            $others[$name] = $environmentConfig;
            unset($config['application_orchestration']['application']['environments'][$name]);
        }

        return $others;
    }
}
