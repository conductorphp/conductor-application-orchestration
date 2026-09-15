<?php

declare(strict_types=1);

namespace ConductorAppOrchestrationTest\Config;

use ConductorAppOrchestration\Config\ApplicationConfig;
use ConductorAppOrchestration\Config\EnvVarInterpolationPostProcessor;
use ConductorCore\Exception\UndefinedVariableException;
use PHPUnit\Framework\TestCase;

use function putenv;

/**
 * CTAP-1724. One pass over the merged config: process environment first, then
 * `application.environment_vars`; plan steps and other environments left alone.
 */
class EnvVarInterpolationPostProcessorTest extends TestCase
{
    private const VARS = ['CT_MYSQL_PASSWORD', 'CT_MYSQL_HOST', 'CT_FRONTEND_DOMAIN', 'CT_REGION'];

    protected function tearDown(): void
    {
        foreach (self::VARS as $name) {
            putenv($name);
        }
    }

    /** A merged config shaped the way `ConfigAggregator` hands it over, with `environment` set. */
    private function mergedConfig(string $environment, array $application = [], array $extra = []): array
    {
        return $extra + [
            'environment'               => $environment,
            'crypt_key'                 => null,
            'application_orchestration' => [
                'application' => $application + [
                    'app_name'    => 'Warranty',
                    'app_root'    => '/app',
                    'repo_url'    => 'git@example.test:noco/warranty.git',
                ],
            ],
        ];
    }

    // ------------------------------------------------------------------ the acceptance case

    /**
     * `environments/production/config.yaml` carrying `url: mysql://prod:${MYSQL_PASSWORD}@…` renders
     * the real values with both variables set and no crypt key present.
     */
    public function testSkeletonTemplateVarsAreFilledFromTheProcessEnvironment(): void
    {
        putenv('CT_MYSQL_PASSWORD=s3cret');
        putenv('CT_MYSQL_HOST=db.internal');

        $config = (new EnvVarInterpolationPostProcessor())($this->mergedConfig('production', [
            'skeleton' => [
                'files' => [
                    'config/autoload/doctrine.local.php' => [
                        'location'      => 'local',
                        'source'        => 'doctrine.local.php.twig',
                        'template_vars' => [
                            'data' => ['doctrine' => ['connection' => ['orm_default' => ['params' => [
                                'url' => 'mysql://prod:${CT_MYSQL_PASSWORD}@${CT_MYSQL_HOST}/noco_warranty',
                            ]]]]],
                        ],
                    ],
                ],
            ],
        ]));

        $url = $config['application_orchestration']['application']['skeleton']['files']
            ['config/autoload/doctrine.local.php']['template_vars']['data']['doctrine']['connection']
            ['orm_default']['params']['url'];

        $this->assertSame('mysql://prod:s3cret@db.internal/noco_warranty', $url);
        $this->assertNull($config['crypt_key']);

        // And the result still builds a valid ApplicationConfig, i.e. the pass changed values only.
        $applicationConfig = new ApplicationConfig($config['application_orchestration']['application'] + [
            'environment' => 'production',
        ]);
        $this->assertSame('production', $applicationConfig->environment);
    }

    public function testAnUnsetVariableFailsTheLoadNamingTheVariableAndPath(): void
    {
        putenv('CT_MYSQL_HOST=db.internal');
        // CT_MYSQL_PASSWORD deliberately unset.

        try {
            (new EnvVarInterpolationPostProcessor())($this->mergedConfig('production', [
                'skeleton' => ['files' => ['config/autoload/doctrine.local.php' => ['template_vars' => [
                    'url' => 'mysql://prod:${CT_MYSQL_PASSWORD}@${CT_MYSQL_HOST}/db',
                ]]]],
            ]));
            $this->fail('Expected UndefinedVariableException');
        } catch (UndefinedVariableException $exception) {
            $this->assertSame([[
                'CT_MYSQL_PASSWORD',
                'application_orchestration.application.skeleton.files[config/autoload/doctrine.local.php].template_vars.url',
            ]], $exception->references);
        }
    }

    // ------------------------------------------------------------------ every section, one pass

    public function testCoreAdapterConfigIsInterpolatedToo(): void
    {
        putenv('CT_MYSQL_PASSWORD=s3cret');
        putenv('CT_REGION=eu-west-1');

        $config = (new EnvVarInterpolationPostProcessor())($this->mergedConfig('production', [], [
            'database'   => ['adapters' => ['default' => ['arguments' => ['password' => '${CT_MYSQL_PASSWORD}']]]],
            'filesystem' => ['adapters' => ['aws' => ['arguments' => ['client' => ['region' => '${CT_REGION}']]]]],
        ]));

        $this->assertSame('s3cret', $config['database']['adapters']['default']['arguments']['password']);
        $this->assertSame('eu-west-1', $config['filesystem']['adapters']['aws']['arguments']['client']['region']);
    }

    /** The regression case: `to: 'https://${FRONTEND_DOMAIN}'` fed by `environment_vars`. */
    public function testEnvironmentVarsAreTheFallbackSourceForReplacements(): void
    {
        $config = (new EnvVarInterpolationPostProcessor())($this->mergedConfig('qa', [
            'environment_vars' => ['CT_FRONTEND_DOMAIN' => 'qa.example.test'],
            'deploy'           => ['databases' => ['main' => ['replacements' => [
                'frontend' => ['from' => 'https://www.example.test', 'to' => 'https://${CT_FRONTEND_DOMAIN}', 'targets' => ['core_config_data.value']],
            ]]]],
        ]));

        $this->assertSame(
            'https://qa.example.test',
            $config['application_orchestration']['application']['deploy']['databases']['main']['replacements']['frontend']['to'],
        );
    }

    public function testTheProcessEnvironmentWinsOverEnvironmentVars(): void
    {
        putenv('CT_FRONTEND_DOMAIN=override.example.test');

        $config = (new EnvVarInterpolationPostProcessor())($this->mergedConfig('qa', [
            'environment_vars' => ['CT_FRONTEND_DOMAIN' => 'qa.example.test'],
            'servers'          => ['web' => ['host' => '${CT_FRONTEND_DOMAIN}']],
        ]));

        $this->assertSame('override.example.test', $config['application_orchestration']['application']['servers']['web']['host']);
    }

    /** `environment_vars` may lean on the process environment; they are resolved before use. */
    public function testEnvironmentVarsThemselvesAreInterpolatedFromTheProcessEnvironment(): void
    {
        putenv('CT_FRONTEND_DOMAIN=qa.example.test');

        $config = (new EnvVarInterpolationPostProcessor())($this->mergedConfig('qa', [
            'environment_vars' => ['FRONTEND_URL' => 'https://${CT_FRONTEND_DOMAIN}'],
            'servers'          => ['web' => ['url' => '${FRONTEND_URL}']],
        ]));

        $application = $config['application_orchestration']['application'];
        $this->assertSame('https://qa.example.test', $application['environment_vars']['FRONTEND_URL']);
        $this->assertSame('https://qa.example.test', $application['servers']['web']['url']);
    }

    public function testAnUndefinedVariableInsideEnvironmentVarsNamesThatPath(): void
    {
        try {
            (new EnvVarInterpolationPostProcessor())($this->mergedConfig('qa', [
                'environment_vars' => ['FRONTEND_URL' => 'https://${CT_FRONTEND_DOMAIN}'],
            ]));
            $this->fail('Expected UndefinedVariableException');
        } catch (UndefinedVariableException $exception) {
            $this->assertSame(
                [['CT_FRONTEND_DOMAIN', 'application_orchestration.application.environment_vars.FRONTEND_URL']],
                $exception->references,
            );
        }
    }

    /** The in-tree `application.environments.<env>` form contributes its `environment_vars` too. */
    public function testTheCurrentEnvironmentsNestedEnvironmentVarsOverlayTheGlobalOnes(): void
    {
        $config = (new EnvVarInterpolationPostProcessor())($this->mergedConfig('uat', [
            'environment_vars' => ['CT_FRONTEND_DOMAIN' => 'www.example.test', 'CT_REGION' => 'us-east-1'],
            'environments'     => [
                'uat' => ['environment_vars' => ['CT_FRONTEND_DOMAIN' => 'uat.example.test']],
            ],
            'servers'          => ['web' => ['host' => '${CT_FRONTEND_DOMAIN}', 'region' => '${CT_REGION}']],
        ]));

        $server = $config['application_orchestration']['application']['servers']['web'];
        $this->assertSame('uat.example.test', $server['host']);
        $this->assertSame('us-east-1', $server['region']);
    }

    // ------------------------------------------------------------------ what is left alone

    /** A production-only variable must not fail a QA deploy; the factory discards that section. */
    public function testOtherEnvironmentsAreLeftUntouched(): void
    {
        $production = [
            'databases' => ['main' => ['url' => 'mysql://prod:${CT_PROD_ONLY_PASSWORD}@prod-db/app']],
        ];

        $config = (new EnvVarInterpolationPostProcessor())($this->mergedConfig('qa', [
            'environments' => [
                'qa'         => ['environment_vars' => ['CT_FRONTEND_DOMAIN' => 'qa.example.test']],
                'production' => $production,
            ],
        ]));

        $environments = $config['application_orchestration']['application']['environments'];
        $this->assertSame($production, $environments['production']);
        $this->assertArrayHasKey('qa', $environments);
    }

    /** Plan steps are shell text; `${attempt}` is the shell's, at run time, not ours. */
    public function testPlanStepsAreLeftToTheShell(): void
    {
        $steps = [
            'wait-for-db' => 'until mysqladmin ping; do echo "waiting (${attempt})"; done',
            'build'       => ['command' => 'composer install --no-dev ${COMPOSER_FLAGS}'],
            'parallel'    => ['a' => 'echo ${A}', 'b' => 'echo ${B}'],
        ];
        $preflight = ['check' => 'test -n "${CT_MYSQL_HOST:?required}"'];

        $config = (new EnvVarInterpolationPostProcessor())($this->mergedConfig('qa', [
            'deploy' => ['plans' => ['default' => ['steps' => $steps, 'preflight_steps' => $preflight]]],
            'build'  => ['plans' => ['default' => ['steps' => $steps, 'clean_steps' => $preflight]]],
        ]));

        $plans = $config['application_orchestration']['application'];
        $this->assertSame($steps, $plans['deploy']['plans']['default']['steps']);
        $this->assertSame($preflight, $plans['deploy']['plans']['default']['preflight_steps']);
        $this->assertSame($steps, $plans['build']['plans']['default']['steps']);
        $this->assertSame($preflight, $plans['build']['plans']['default']['clean_steps']);
    }

    public function testAConfigWithNoPlaceholdersIsReturnedUnchanged(): void
    {
        $merged = $this->mergedConfig('qa', ['skeleton' => ['files' => []]], [
            'database' => ['adapters' => ['default' => ['arguments' => ['password' => 'literal']]]],
        ]);

        $this->assertSame($merged, (new EnvVarInterpolationPostProcessor())($merged));
    }

    public function testEscapedPlaceholderSurvivesToTheTemplate(): void
    {
        $config = (new EnvVarInterpolationPostProcessor())($this->mergedConfig('qa', [
            'template_vars' => ['shell_snippet' => 'echo $${HOME}'],
        ]));

        $this->assertSame('echo ${HOME}', $config['application_orchestration']['application']['template_vars']['shell_snippet']);
    }
}
