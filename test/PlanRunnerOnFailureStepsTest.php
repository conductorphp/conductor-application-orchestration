<?php

namespace ConductorAppOrchestrationTest;

use ConductorAppOrchestration\Exception\RuntimeException;
use ConductorAppOrchestration\Plan;
use ConductorAppOrchestration\PlanRunner;
use ConductorAppOrchestration\Snapshot\Command\SnapshotCommandInterface;
use ConductorCore\Exception\ShellCommandFailedException;
use ConductorCore\Shell\Adapter\ShellAdapterInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use ReflectionClass;
use Stringable;

/**
 * A plan's `on_failure_steps` run when a preflight, clean or main step throws, so a plan that
 * enabled maintenance mode can switch it back off. They are best-effort and never replace the
 * original failure (CTAP-2197).
 *
 * Built without the constructor, as the other PlanRunner tests are. The step interface is not the
 * deploy one, so runPlan() skips the deployment-state lookup, and every step is a `command:` run by
 * a fake shell adapter that fails the commands it is told to.
 */
class PlanRunnerOnFailureStepsTest extends TestCase
{
    private PlanRunner $planRunner;

    /** @var AbstractLogger&object{records: array<int, array{level: string, message: string}>} */
    private $logger;

    /** @var ShellAdapterInterface&object{failing: string[], ran: array<int, array{command: string, environment: array}>} */
    private $shellAdapter;

    private string $planPath;

    public function setUp(): void
    {
        $this->logger = new class extends AbstractLogger {
            /** @var array<int, array{level: string, message: string}> */
            public array $records = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->records[] = ['level' => (string) $level, 'message' => (string) $message];
            }
        };

        $this->shellAdapter = new class implements ShellAdapterInterface {
            /** @var string[] */
            public array $failing = [];
            /** @var array<int, array{command: string, environment: array}> */
            public array $ran = [];

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
                $this->ran[] = ['command' => $command, 'environment' => $environmentVariables ?? []];
                if (in_array($command, $this->failing, true)) {
                    throw new ShellCommandFailedException($command, 1, '', "$command broke\n");
                }

                return '';
            }
        };

        $this->planPath = sys_get_temp_dir() . '/conductor-on-failure-' . bin2hex(random_bytes(4));

        $reflection = new ReflectionClass(PlanRunner::class);
        $this->planRunner = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('logger')->setValue($this->planRunner, $this->logger);
        $reflection->getProperty('shellAdapter')->setValue($this->planRunner, $this->shellAdapter);
        $reflection->getProperty('diskSpaceErrorThreshold')->setValue($this->planRunner, 0);
        $reflection->getProperty('diskSpaceWarningThreshold')->setValue($this->planRunner, 0);
        $this->planRunner->setPlanPath($this->planPath);
        $this->planRunner->setStepInterface(SnapshotCommandInterface::class);
    }

    public function tearDown(): void
    {
        if (is_dir($this->planPath)) {
            rmdir($this->planPath);
        }
    }

    public function testAFailingStepRunsTheOnFailureStepsThenRethrowsItsOwnFailure(): void
    {
        $this->shellAdapter->failing = ['three'];

        $e = $this->runFailingPlan([
            'steps' => ['step-1' => 'one', 'step-2' => 'two', 'step-3' => 'three', 'step-4' => 'four'],
            'on_failure_steps' => ['disable-maintenance' => 'maintenance off'],
        ]);

        $this->assertSame('three', $e->getCommand());
        $this->assertSame(['one', 'two', 'three', 'maintenance off'], $this->commandsRun());
        $this->assertContains(
            'Running on_failure steps after step "step-3" failed: An error occurred while running shell command: '
            . '"three"',
            $this->messagesAt(LogLevel::WARNING)
        );
    }

    public function testAFailingOnFailureStepIsLoggedAndTheNextOneStillRuns(): void
    {
        $this->shellAdapter->failing = ['three', 'cleanup'];

        $e = $this->runFailingPlan([
            'steps' => ['step-1' => 'one', 'step-2' => 'two', 'step-3' => 'three'],
            'on_failure_steps' => ['cleanup' => 'cleanup', 'disable-maintenance' => 'maintenance off'],
        ]);

        $this->assertSame('three', $e->getCommand());
        $this->assertSame(['one', 'two', 'three', 'cleanup', 'maintenance off'], $this->commandsRun());
        $this->assertContains(
            'on_failure step "cleanup" failed, continuing with the rest: An error occurred while running shell '
            . 'command: "cleanup"',
            $this->messagesAt(LogLevel::ERROR)
        );
    }

    public function testThePlanWithoutOnFailureStepsBehavesAsBefore(): void
    {
        $this->shellAdapter->failing = ['two'];

        $e = $this->runFailingPlan(['steps' => ['step-1' => 'one', 'step-2' => 'two', 'step-3' => 'three']]);

        $this->assertSame('two', $e->getCommand());
        $this->assertSame(['one', 'two'], $this->commandsRun());
        $this->assertSame([], $this->messagesAt(LogLevel::WARNING));
    }

    public function testOnFailureStepsDoNotRunWhenThePlanSucceeds(): void
    {
        $this->runPlan(['steps' => ['step-1' => 'one'], 'on_failure_steps' => ['disable-maintenance' => 'off']]);

        $this->assertSame(['one'], $this->commandsRun());
    }

    public function testTheFailedStepNameIsInTheOnFailureStepsEnvironmentOnly(): void
    {
        $this->shellAdapter->failing = ['two'];

        $this->runFailingPlan([
            'steps' => ['step-1' => 'one', 'step-2' => 'two'],
            'on_failure_steps' => [
                'report' => ['command' => 'report', 'notice' => 'Deploy failed at ${FAILED_STEP}.'],
            ],
        ]);

        $environments = array_column($this->shellAdapter->ran, 'environment', 'command');
        $this->assertArrayNotHasKey('FAILED_STEP', $environments['one']);
        $this->assertArrayNotHasKey('FAILED_STEP', $environments['two']);
        $this->assertSame('step-2', $environments['report']['FAILED_STEP']);
        $this->assertSame('Deploy failed at step-2.', $this->planRunner->getNotices()[0]['notice']);
    }

    public function testAFailureInsideAParallelGroupNamesTheChildStep(): void
    {
        $this->shellAdapter->failing = ['beta'];

        $this->runFailingPlan([
            'steps' => ['group' => ['alpha' => 'alpha', 'beta' => 'beta']],
            'on_failure_steps' => ['report' => 'report'],
        ], \Throwable::class);

        $environments = array_column($this->shellAdapter->ran, 'environment', 'command');
        $this->assertSame('beta', $environments['report']['FAILED_STEP']);
    }

    public function testAPreflightFailureRunsTheOnFailureSteps(): void
    {
        $this->shellAdapter->failing = ['check'];

        $this->runFailingPlan([
            'preflight_steps' => ['check' => 'check'],
            'steps' => ['step-1' => 'one'],
            'on_failure_steps' => ['report' => 'report'],
        ]);

        $this->assertSame(['check', 'report'], $this->commandsRun());
    }

    public function testOnFailureStepsHonorConditions(): void
    {
        $this->shellAdapter->failing = ['one'];

        $this->runFailingPlan([
            'steps' => ['step-1' => 'one'],
            'on_failure_steps' => [
                'skipped' => ['command' => 'skipped', 'conditions' => ['production']],
                'kept' => ['command' => 'kept', 'conditions' => ['local']],
            ],
        ]);

        $this->assertSame(['one', 'kept'], $this->commandsRun());
    }

    public function testRollbackDoesNotRunOnFailureSteps(): void
    {
        $this->shellAdapter->failing = ['undo'];

        $this->expectException(ShellCommandFailedException::class);
        try {
            $this->runPlan(
                [
                    'steps' => ['step-1' => 'one'],
                    'rollback_steps' => ['undo' => 'undo'],
                    'on_failure_steps' => ['report' => 'report'],
                ],
                rollback: true
            );
        } finally {
            $this->assertSame(['undo'], $this->commandsRun());
        }
    }

    public function testThePlanExposesItsOnFailureSteps(): void
    {
        $plan = new Plan('p', ['steps' => ['a' => 'a'], 'on_failure_steps' => ['off' => 'off']], SnapshotCommandInterface::class);

        $this->assertSame(['off' => ['command' => 'off']], $plan->getOnFailureSteps());
        $this->assertSame([], (new Plan('p', ['steps' => ['a' => 'a']], SnapshotCommandInterface::class))->getOnFailureSteps());
    }

    /**
     * @template T of \Throwable
     * @param class-string<T> $expected
     * @return T
     */
    private function runFailingPlan(array $plan, string $expected = ShellCommandFailedException::class): \Throwable
    {
        try {
            $this->runPlan($plan, ['local']);
        } catch (\Throwable $e) {
            $this->assertInstanceOf($expected, $e);
            return $e;
        }

        $this->fail('The step failure must still fail the plan.');
    }

    private function runPlan(array $plan, array $conditions = ['local'], bool $rollback = false): void
    {
        $this->planRunner->setPlans(['test' => $plan]);
        $this->planRunner->runPlan('test', $conditions, [], false, $rollback);
    }

    /** @return string[] */
    private function commandsRun(): array
    {
        return array_column($this->shellAdapter->ran, 'command');
    }

    /** @return string[] */
    private function messagesAt(string $level): array
    {
        return array_values(array_map(
            static fn(array $record): string => $record['message'],
            array_filter($this->logger->records, static fn(array $record): bool => $record['level'] === $level)
        ));
    }
}
