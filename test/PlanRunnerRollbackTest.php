<?php

namespace ConductorAppOrchestrationTest;

use ConductorAppOrchestration\Exception\RuntimeException;
use ConductorAppOrchestration\PlanRunner;
use ConductorAppOrchestration\Snapshot\Command\SnapshotCommandInterface;
use ConductorCore\Shell\Adapter\ShellAdapterInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;

/**
 * `--rollback` used to read its preflight list with getRollbackSteps(), so the plan's
 * `rollback_preflight_steps` never ran and its `rollback_steps` ran twice (CTAP-2199).
 *
 * Built without the constructor, as the other PlanRunner tests are. The step interface is not the
 * deploy one, so runPlan() skips the deployment-state lookup, and every step is a `command:` that a
 * fake shell adapter records.
 */
class PlanRunnerRollbackTest extends TestCase
{
    private PlanRunner $planRunner;

    /** @var ShellAdapterInterface&object{ran: string[]} */
    private $shellAdapter;

    private string $planPath;

    public function setUp(): void
    {
        $this->shellAdapter = new class implements ShellAdapterInterface {
            /** @var string[] */
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
                $this->ran[] = $command;

                return '';
            }
        };

        $this->planPath = sys_get_temp_dir() . '/conductor-rollback-' . bin2hex(random_bytes(4));

        $reflection = new ReflectionClass(PlanRunner::class);
        $this->planRunner = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('logger')->setValue($this->planRunner, new NullLogger());
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

    public function testRollbackRunsThePreflightStepsOnceThenTheRollbackStepsOnce(): void
    {
        $this->rollback([
            'steps' => ['deploy' => 'deploy'],
            'rollback_preflight_steps' => ['check' => 'check'],
            'rollback_steps' => ['restore' => 'restore', 'relink' => 'relink'],
        ]);

        $this->assertSame(['check', 'restore', 'relink'], $this->shellAdapter->ran);
    }

    public function testRollbackWithoutPreflightStepsRunsEachRollbackStepOnce(): void
    {
        $this->rollback(['steps' => ['deploy' => 'deploy'], 'rollback_steps' => ['restore' => 'restore']]);

        $this->assertSame(['restore'], $this->shellAdapter->ran);
    }

    public function testRollbackWithoutRollbackStepsStillFails(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Rollback requested but plan "test" does not include any rollback steps.');

        try {
            $this->rollback(['steps' => ['deploy' => 'deploy'], 'rollback_preflight_steps' => ['check' => 'check']]);
        } finally {
            $this->assertSame([], $this->shellAdapter->ran);
        }
    }

    private function rollback(array $plan): void
    {
        $this->planRunner->setPlans(['test' => $plan]);
        $this->planRunner->runPlan('test', [], [], false, true);
    }
}
