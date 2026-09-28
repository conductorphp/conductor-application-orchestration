<?php

namespace ConductorAppOrchestrationTest;

use ConductorAppOrchestration\PlanRunner;
use ConductorCore\Exception\ShellCommandFailedException;
use ConductorCore\Shell\Adapter\ShellAdapterInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use ReflectionClass;
use Stringable;

/**
 * A `command:` step that exits non-zero used to leave its output only in the exception message,
 * which the console renders to stderr, and only at a verbosity that shows the exception block. A
 * hook runner that captured stdout alone saw the step name and nothing else. The runner now logs
 * the step's stdout and stderr at ERROR, through the logger, before rethrowing (CTAP-2006).
 *
 * Built without the constructor, as the other PlanRunner tests are, with only the collaborators
 * the command branch touches.
 */
class PlanRunnerFailedStepTest extends TestCase
{
    private PlanRunner $planRunner;

    /** @var AbstractLogger&object{records: array<int, array{level: string, message: string}>} */
    private $logger;

    /** @var ShellAdapterInterface&object{failure: ?ShellCommandFailedException} */
    private $shellAdapter;

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
            public ?ShellCommandFailedException $failure = null;

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
                if ($this->failure) {
                    throw $this->failure;
                }

                return "all good\n";
            }
        };

        $reflection = new ReflectionClass(PlanRunner::class);
        $this->planRunner = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('logger')->setValue($this->planRunner, $this->logger);
        $reflection->getProperty('shellAdapter')->setValue($this->planRunner, $this->shellAdapter);
        $reflection->getProperty('planPath')->setValue($this->planRunner, sys_get_temp_dir());
    }

    public function testAFailingStepLogsItsOutputAtErrorAndRethrows(): void
    {
        $this->shellAdapter->failure = new ShellCommandFailedException(
            'vendor/bin/console config:env:verify',
            1,
            "APP_URL is not set\n",
            "warning: DB_PORT defaulted to 3306\n"
        );

        try {
            $this->runCommandStep('config-env-verify', ['command' => 'vendor/bin/console config:env:verify']);
            $this->fail('The step failure must still abort the plan.');
        } catch (ShellCommandFailedException $e) {
            $this->assertSame($this->shellAdapter->failure, $e);
        }

        $errors = $this->messagesAt(LogLevel::ERROR);
        $this->assertCount(1, $errors);
        $this->assertSame(
            "Step \"config-env-verify\" failed with exit status 1.\n"
            . "Command: vendor/bin/console config:env:verify\n"
            . "Stdout: APP_URL is not set\n"
            . 'Stderr: warning: DB_PORT defaulted to 3306',
            $errors[0]
        );
    }

    public function testAStreamTheCommandLeftEmptyIsOmitted(): void
    {
        $this->shellAdapter->failure = new ShellCommandFailedException('false', 1, '', "only stderr\n");

        $this->runFailingCommandStep(['command' => 'false']);

        $errors = $this->messagesAt(LogLevel::ERROR);
        $this->assertCount(1, $errors);
        $this->assertStringNotContainsString('Stdout', $errors[0]);
        $this->assertStringContainsString("\nStderr: only stderr", $errors[0]);
    }

    public function testMultiLineCommandAndOutputEachStartOnTheirOwnLine(): void
    {
        $this->shellAdapter->failure = new ShellCommandFailedException("one\ntwo", 2, "line 1\nline 2\n");

        $this->runFailingCommandStep(['command' => "one\ntwo"]);

        $this->assertSame(
            "Step \"step\" failed with exit status 2.\nCommand:\none\ntwo\nStdout:\nline 1\nline 2",
            $this->messagesAt(LogLevel::ERROR)[0]
        );
    }

    public function testASuccessfulStepLogsItsOutputAtDebugOnly(): void
    {
        $this->runCommandStep('step', ['command' => 'true']);

        $this->assertSame([], $this->messagesAt(LogLevel::ERROR));
        $this->assertContains('Step "step" output: all good', $this->messagesAt(LogLevel::DEBUG));
    }

    /** @return string[] */
    private function messagesAt(string $level): array
    {
        return array_values(array_map(
            static fn(array $record): string => $record['message'],
            array_filter($this->logger->records, static fn(array $record): bool => $record['level'] === $level)
        ));
    }

    private function runFailingCommandStep(array $step): void
    {
        try {
            $this->runCommandStep('step', $step);
            $this->fail('The step failure must still abort the plan.');
        } catch (ShellCommandFailedException) {
        }
    }

    private function runCommandStep(string $name, array $step): void
    {
        $method = (new ReflectionClass(PlanRunner::class))->getMethod('runStep');
        $method->invoke($this->planRunner, $name, $step, [], [], []);
    }
}
