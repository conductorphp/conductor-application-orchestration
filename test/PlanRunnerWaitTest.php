<?php

namespace ConductorAppOrchestrationTest;

use ConductorAppOrchestration\Deploy\Command\DeployCommandInterface;
use ConductorAppOrchestration\Exception\RuntimeException;
use ConductorAppOrchestration\Plan;
use ConductorAppOrchestration\PlanRunner;
use ConductorAppOrchestration\Wait\HttpProbeInterface;
use ConductorAppOrchestration\Wait\HttpProbeResult;
use ConductorAppOrchestration\Wait\UrlWaiter;
use ConductorCore\Shell\Adapter\ShellAdapterInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use ReflectionClass;
use Stringable;

/**
 * CTAP-2145. A step's `wait:` polls a URL before the step's command runs, so a sync plan can wait for
 * its source app without a shell helper. The polling itself is covered by UrlWaiterTest; this covers
 * how the runner and the plan treat the step.
 *
 * Built without the constructor, as PlanRunnerNoticeTest does.
 */
class PlanRunnerWaitTest extends TestCase
{
    private PlanRunner $planRunner;

    /** @var list<string> what happened, in order: "probe <method> <url>" and "command <command>" */
    private array $events = [];

    /** @var list<HttpProbeResult> */
    private array $probeResults = [];

    /** @var AbstractLogger&object{records: list<array{0: string, 1: string}>} */
    private $logger;

    public function setUp(): void
    {
        $this->logger = new class extends AbstractLogger {
            /** @var list<array{0: string, 1: string}> */
            public array $records = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->records[] = [$level, (string) $message];
            }
        };

        $events = &$this->events;
        $shellAdapter = new class ($events) implements ShellAdapterInterface {
            public function __construct(private array &$events)
            {
            }

            public function isCallable(string $command): bool
            {
                return true;
            }

            public function runShellCommand(
                string $command,
                ?string $currentWorkingDirectory = null,
                ?array $environmentVariables = null,
                int $priority = self::PRIORITY_NORMAL,
                ?array $options = null
            ): string {
                $this->events[] = "command $command";

                return '';
            }
        };

        $probeResults = &$this->probeResults;
        $probe = new class ($events, $probeResults) implements HttpProbeInterface {
            public function __construct(private array &$events, private array &$results)
            {
            }

            public function probe(
                string $method,
                string $url,
                array $headers,
                ?string $body,
                int $timeout,
                bool $verifyTls
            ): HttpProbeResult {
                $this->events[] = trim("probe $method $url " . json_encode($headers) . " $body");

                return count($this->results) > 1 ? array_shift($this->results) : $this->results[0];
            }
        };

        $reflection = new ReflectionClass(PlanRunner::class);
        $this->planRunner = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('logger')->setValue($this->planRunner, $this->logger);
        $reflection->getProperty('shellAdapter')->setValue($this->planRunner, $shellAdapter);
        $reflection->getProperty('planPath')->setValue($this->planRunner, sys_get_temp_dir());
        $this->planRunner->setUrlWaiter(new UrlWaiter($probe, static fn(): float => 0.0, static function (): void {
        }));
    }

    public function testAWaitOnlyStepPollsAndRunsNothing(): void
    {
        $this->probeResults = [new HttpProbeResult(200)];

        $this->runStep('wait-for-magento', ['wait' => ['url' => 'http://magento/rest']]);

        $this->assertSame(['probe GET http://magento/rest []'], $this->events);
    }

    public function testTheWaitFinishesBeforeTheStepsCommandRuns(): void
    {
        $this->probeResults = [new HttpProbeResult(502), new HttpProbeResult(200)];

        $this->runStep('import', ['wait' => ['url' => 'http://magento/rest'], 'command' => 'console import']);

        $this->assertSame(
            ['probe GET http://magento/rest []', 'probe GET http://magento/rest []', 'command console import'],
            $this->events
        );
    }

    public function testAWaitDoesNotRunWhenItsConditionsAreNotMet(): void
    {
        $this->runStep('wait-for-magento', ['conditions' => ['databases'], 'wait' => ['url' => 'http://magento/rest']], ['code']);

        $this->assertSame([], $this->events);
    }

    /** The URL, body and header values resolve from the same environment a `command:` gets. */
    public function testVariablesExpandFromTheStepEnvironment(): void
    {
        $this->probeResults = [new HttpProbeResult(200)];

        $this->runStep(
            'wait-for-graphql',
            [
                'wait' => [
                    'url' => '${MAGENTO_BASE_URL}/graphql',
                    'method' => 'post',
                    'headers' => ['Store' => '${STORE_CODE:-default}'],
                    'body' => '{"query":"{ storeConfig { code } }","build":"${buildId}"}',
                ],
                'environment_variables' => ['MAGENTO_BASE_URL' => 'https://magento.example.com'],
            ],
            [],
            ['buildId' => 'b42']
        );

        $this->assertSame(
            ['probe POST https://magento.example.com/graphql {"Store":"default"} {"query":"{ storeConfig { code } }","build":"b42"}'],
            $this->events
        );
    }

    public function testATimeoutFailsTheStepAtError(): void
    {
        // setUp's clock never moves; this one advances on sleep so the timeout is reached
        $now = 0.0;
        $this->planRunner->setUrlWaiter(new UrlWaiter(
            new class implements HttpProbeInterface {
                public function probe(string $method, string $url, array $headers, ?string $body, int $timeout, bool $verifyTls): HttpProbeResult
                {
                    return HttpProbeResult::transportError('Could not resolve host: magneto');
                }
            },
            static function () use (&$now): float {
                return $now;
            },
            static function (float $seconds) use (&$now): void {
                $now += $seconds;
            }
        ));

        try {
            $this->runStep('wait-for-magento', ['wait' => ['url' => 'http://magneto/rest', 'timeout' => 1], 'command' => 'never']);
            $this->fail('Expected the wait to time out.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('http://magneto/rest', $e->getMessage());
        }

        $this->assertSame([], $this->events, 'The command after a failed wait must not run.');
        $this->assertContains(
            [
                LogLevel::ERROR,
                'Step "wait-for-magento" failed. Timed out after 1s waiting for GET http://magneto/rest. '
                . 'Last result: no response, Could not resolve host: magneto.',
            ],
            $this->logger->records
        );
    }

    public function testAWaitOnlyStepIsAStepRatherThanAParallelGroup(): void
    {
        $plan = $this->plan(['wait-for-magento' => ['wait' => ['url' => 'http://magento/rest']]]);

        $this->assertSame(['wait' => ['url' => 'http://magento/rest']], $plan->getSteps()['wait-for-magento']);
    }

    public function testAMistypedWaitKeyFailsThePlan(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Step "wait-for-magento" key "wait" has unknown key "statuses"');

        $this->plan(['wait-for-magento' => ['wait' => ['url' => 'http://magento/rest', 'statuses' => [200]]]]);
    }

    public function testAWaitNeedsAUrl(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must include a non-empty string "url"');

        $this->plan(['wait-for-magento' => ['wait' => ['timeout' => 60]]]);
    }

    public function testAWaitMayNotRunInsideAParallelGroup(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('may not include "wait" inside a parallel group');

        $this->plan(['group' => ['a' => ['command' => 'true'], 'b' => ['wait' => ['url' => 'http://magento/rest']]]]);
    }

    public function testAWaitMayNotSitOnAParallelGroup(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('may not include "wait" with "steps"');

        $this->plan(['group' => ['wait' => ['url' => 'http://magento/rest'], 'steps' => ['a' => ['command' => 'true']]]]);
    }

    /** CTAP-2150: an unset variable fails the step before the first poll, naming the variable. */
    public function testAnUnsetVariableFailsBeforeTheFirstPoll(): void
    {
        try {
            $this->runStep('wait-for-magento', ['wait' => ['url' => 'https://${UNSET_FOR_CTAP_2150}/rest', 'timeout' => 3600]]);
            $this->fail('Expected the step to fail.');
        } catch (RuntimeException $e) {
            $this->assertSame(
                'Step "wait-for-magento" key "wait" cannot be resolved: url: UNSET_FOR_CTAP_2150 is not set.',
                $e->getMessage()
            );
        }

        $this->assertSame([], $this->events, 'Nothing may be polled.');
    }

    public function testUnsetVariablesInTheBodyAndHeadersAreReportedToo(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('body: UNSET_BODY_CTAP_2150 is not set; header "Store": UNSET_STORE_CTAP_2150 is not set');

        $this->runStep('wait-for-graphql', ['wait' => [
            'url' => 'https://example.com/graphql',
            'headers' => ['Store' => '$UNSET_STORE_CTAP_2150'],
            'body' => '{"q":"$UNSET_BODY_CTAP_2150"}',
        ]]);
    }

    public function testARequiredVariableFailsWithItsMessage(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('url: UNSET_CTAP_2150 set the commerce base URL');

        $this->runStep('wait-for-magento', ['wait' => ['url' => '${UNSET_CTAP_2150:?set the commerce base URL}/rest']]);
    }

    public function testARequiredVariableExpandsWhenSet(): void
    {
        $this->probeResults = [new HttpProbeResult(200)];

        $this->runStep('wait-for-magento', [
            'wait' => ['url' => '${MAGENTO_BASE_URL:?set it}/rest'],
            'environment_variables' => ['MAGENTO_BASE_URL' => 'https://shop.example.com'],
        ]);

        $this->assertSame(['probe GET https://shop.example.com/rest []'], $this->events);
    }

    /** A set but empty variable passes bash -u, but leaves a URL with no host: that fails too. */
    public function testAUrlWithoutAHostFailsBeforeTheFirstPoll(): void
    {
        try {
            $this->runStep('wait-for-magento', [
                'wait' => ['url' => 'https://${MAGENTO_HOST}/rest'],
                'environment_variables' => ['MAGENTO_HOST' => ''],
            ]);
            $this->fail('Expected the step to fail.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('url resolves to "https:///rest", which is not an http(s) URL with a host', $e->getMessage());
        }

        $this->assertSame([], $this->events);
    }

    public function testAnUnsupportedExpansionFailsThePlanWhenItLoads(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Step "wait-for-magento" key "wait" url uses ${MAGENTO_BASE_URL:+x}, which is not supported');

        $this->plan(['wait-for-magento' => ['wait' => ['url' => '${MAGENTO_BASE_URL:+x}/rest']]]);
    }

    public function testSupportedExpansionsLoad(): void
    {
        $plan = $this->plan(['wait-for-magento' => ['wait' => ['url' => '${MAGENTO_BASE_URL:?set it}/rest/${STORE:-default}']]]);

        $this->assertArrayHasKey('wait-for-magento', $plan->getSteps());
    }

    private function runStep(string $name, array $step, array $conditions = [], array $stepArguments = []): void
    {
        $method = (new ReflectionClass(PlanRunner::class))->getMethod('runStep');
        $method->invoke($this->planRunner, $name, $step, $conditions, [], $stepArguments);
    }

    private function plan(array $steps): Plan
    {
        return new Plan('test', ['steps' => $steps], DeployCommandInterface::class);
    }
}
