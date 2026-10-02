<?php

namespace ConductorAppOrchestrationTest;

use ConductorAppOrchestration\Deploy\Command\DeployCommandInterface;
use ConductorAppOrchestration\Exception\RuntimeException;
use ConductorAppOrchestration\Plan;
use ConductorAppOrchestration\PlanRunner;
use ConductorCore\Shell\Adapter\ShellAdapterInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use ReflectionClass;
use Stringable;

/**
 * CTAP-2143. A step's `notice:` is logged at NOTICE, which shows at default verbosity, where neither
 * "Step: …" nor a step's own output does — an `echo` step is invisible there. It fires only when the
 * step's conditions are met, and the runner keeps the ones that fired for the deploy command to
 * repeat at the end.
 *
 * Built without the constructor, as PlanRunnerStepEnvironmentTest does, with only the collaborators
 * the command and notice branches touch.
 */
class PlanRunnerNoticeTest extends TestCase
{
    private PlanRunner $planRunner;

    /** @var AbstractLogger&object{records: list<array{0: string, 1: string}>} */
    private $logger;

    /** @var ShellAdapterInterface&object{commands: list<string>} */
    private $shellAdapter;

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

        $this->shellAdapter = new class implements ShellAdapterInterface {
            /** @var list<string> */
            public array $commands = [];

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
                $this->commands[] = $command;

                return '';
            }
        };

        $reflection = new ReflectionClass(PlanRunner::class);
        $this->planRunner = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('logger')->setValue($this->planRunner, $this->logger);
        $reflection->getProperty('shellAdapter')->setValue($this->planRunner, $this->shellAdapter);
        $reflection->getProperty('planPath')->setValue($this->planRunner, sys_get_temp_dir());
    }

    public function testANoticeOnlyStepLogsAtNoticeAndRunsNothing(): void
    {
        $this->runStep('sync-reminder', ['conditions' => ['databases'], 'notice' => 'Run the sync plan.'], ['databases']);

        $this->assertSame([[LogLevel::NOTICE, 'Run the sync plan.']], $this->noticeRecords());
        $this->assertSame([], $this->shellAdapter->commands);
        $this->assertSame([['step' => 'sync-reminder', 'notice' => 'Run the sync plan.']], $this->planRunner->getNotices());
    }

    public function testANoticeDoesNotFireWhenItsConditionsAreNotMet(): void
    {
        $this->runStep('sync-reminder', ['conditions' => ['databases'], 'notice' => 'Run the sync plan.'], ['code']);

        $this->assertSame([], $this->noticeRecords());
        $this->assertSame([], $this->planRunner->getNotices());
    }

    public function testANoticeDoesNotFireWhenItsDependenciesAreNotMet(): void
    {
        $this->runStep('sync-reminder', ['depends' => ['databases'], 'notice' => 'Run the sync plan.']);

        $this->assertSame([], $this->planRunner->getNotices());
    }

    public function testANoticeOnACommandStepIsLoggedAndTheCommandStillRuns(): void
    {
        $this->runStep('reindex', ['command' => 'true', 'notice' => 'Reindexing.']);

        $this->assertSame([[LogLevel::NOTICE, 'Reindexing.']], $this->noticeRecords());
        $this->assertSame(['true'], $this->shellAdapter->commands);
    }

    public function testNoticesAccumulateInTheOrderTheyFired(): void
    {
        $this->runStep('first', ['notice' => 'One.']);
        $this->runStep('second', ['notice' => 'Two.']);

        $this->assertSame(['One.', 'Two.'], array_column($this->planRunner->getNotices(), 'notice'));
    }

    /** Placeholders resolve from the same environment a `command:` gets, as bash would expand them. */
    public function testVariablesExpandFromTheStepEnvironment(): void
    {
        $this->runStep(
            'reminder',
            [
                'notice' => 'Snapshot ${snapshotName} on $SITE_HOST, plan ${SYNC_PLAN:-sync}, empty "${UNSET_FOR_CTAP_2143}".',
                'environment_variables' => ['SITE_HOST' => 'www.example.com'],
            ],
            [],
            ['snapshotName' => 'prod-2026-10-01']
        );

        $this->assertSame(
            'Snapshot prod-2026-10-01 on www.example.com, plan sync, empty "".',
            $this->planRunner->getNotices()[0]['notice']
        );
    }

    /** A notice is prose: backticks quote a command, they do not run one, and `\$` is a literal. */
    public function testANoticeIsNotHandedToAShell(): void
    {
        $this->runStep('reminder', ['notice' => 'Run `conductor app:deploy --plan sync` (\$5 $(id) $9).']);

        $this->assertSame(
            'Run `conductor app:deploy --plan sync` ($5 $(id) $9).',
            $this->planRunner->getNotices()[0]['notice']
        );
        $this->assertSame([], $this->shellAdapter->commands);
    }

    public function testANoticeOnlyStepIsAStepRatherThanAParallelGroup(): void
    {
        $plan = $this->plan(['sync-reminder' => ['conditions' => ['databases'], 'notice' => 'Run the sync plan.']]);

        $this->assertSame(
            ['conditions' => ['databases'], 'notice' => 'Run the sync plan.'],
            $plan->getSteps()['sync-reminder']
        );
    }

    public function testANoticeOnlyStepMayRunInAParallelGroup(): void
    {
        $plan = $this->plan(['group' => ['a' => ['command' => 'true'], 'b' => ['notice' => 'Hello.']]]);

        $this->assertSame(['notice' => 'Hello.'], $plan->getSteps()['group']['steps']['b']);
    }

    public function testANoticeMustBeANonEmptyString(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('key "notice" must be a non-empty string');

        $this->plan(['reminder' => ['notice' => ['not', 'a', 'string']]]);
    }

    public function testANoticeMayNotSitOnAParallelGroup(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('may not include "notice" with "steps"');

        $this->plan(['group' => ['notice' => 'Hello.', 'steps' => ['a' => ['command' => 'true']]]]);
    }

    /** @return list<array{0: string, 1: string}> */
    private function noticeRecords(): array
    {
        return array_values(array_filter(
            $this->logger->records,
            static fn(array $record): bool => LogLevel::NOTICE === $record[0]
        ));
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
