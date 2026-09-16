<?php

namespace ConductorAppOrchestrationTest;

use ConductorAppOrchestration\PlanRunner;
use ConductorCore\Shell\Adapter\ShellAdapterInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;

/**
 * A `command:` step runs with conductor's environment layered under the step arguments and the
 * step's own `environment_variables`. Symfony exports conductor's -v/-vv into that environment as
 * SHELL_VERBOSITY, and every child console command would run just as loud. These pin the rule:
 * quiet and -vvv pass through, -v and -vv run the child at its default, and a step that sets the
 * variable itself is left alone.
 *
 * Built without the constructor, as PlanRunnerParallelStepsTest does, with only the collaborators
 * the command branch touches.
 */
class PlanRunnerStepEnvironmentTest extends TestCase
{
    private PlanRunner $planRunner;

    /** @var ShellAdapterInterface&object{environments: array<int, array<string, string>|null>} */
    private $shellAdapter;

    public function setUp(): void
    {
        $this->shellAdapter = new class implements ShellAdapterInterface {
            /** @var array<int, array<string, string>|null> */
            public array $environments = [];

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
                $this->environments[] = $environmentVariables;

                return '';
            }
        };

        $reflection = new ReflectionClass(PlanRunner::class);
        $this->planRunner = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('logger')->setValue($this->planRunner, new NullLogger());
        $reflection->getProperty('shellAdapter')->setValue($this->planRunner, $this->shellAdapter);
        $reflection->getProperty('planPath')->setValue($this->planRunner, sys_get_temp_dir());
    }

    public function testAStepDoesNotInheritConductorsVerboseFlag(): void
    {
        $this->withShellVerbosity('2', fn() => $this->runCommandStep(['command' => 'true']));

        $this->assertArrayNotHasKey('SHELL_VERBOSITY', $this->stepEnvironment());
    }

    public function testAStepInheritsConductorsDebugFlag(): void
    {
        $this->withShellVerbosity('3', fn() => $this->runCommandStep(['command' => 'true']));

        $this->assertSame('3', $this->stepEnvironment()['SHELL_VERBOSITY']);
    }

    public function testAStepsOwnVerbosityWins(): void
    {
        $this->withShellVerbosity('3', fn() => $this->runCommandStep([
            'command' => 'true',
            'environment_variables' => ['SHELL_VERBOSITY' => '1'],
        ]));

        $this->assertSame('1', $this->stepEnvironment()['SHELL_VERBOSITY']);
    }

    public function testTheRestOfTheEnvironmentStillReachesTheStep(): void
    {
        $this->withShellVerbosity('2', fn() => $this->runCommandStep(['command' => 'true']));

        $this->assertSame(getenv('PATH'), $this->stepEnvironment()['PATH']);
    }

    /** @return array<string, string> */
    private function stepEnvironment(): array
    {
        $this->assertCount(1, $this->shellAdapter->environments, 'the step must run exactly one command');

        return $this->shellAdapter->environments[0] ?? [];
    }

    private function runCommandStep(array $step): void
    {
        $method = (new ReflectionClass(PlanRunner::class))->getMethod('runStep');
        $method->invoke($this->planRunner, 'step', $step, [], [], []);
    }

    private function withShellVerbosity(string $level, callable $test): void
    {
        $previous = getenv('SHELL_VERBOSITY');
        putenv('SHELL_VERBOSITY=' . $level);
        try {
            $test();
        } finally {
            putenv(false === $previous ? 'SHELL_VERBOSITY' : 'SHELL_VERBOSITY=' . $previous);
        }
    }
}
