<?php

namespace ConductorAppOrchestrationTest\Console;

use ConductorAppOrchestration\Console\AppPlansCommand;
use ConductorAppOrchestrationTest\BuildsApplicationConfigTrait;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * CTAP-2143. Deploy tooling asks "does this app define a `sync` plan?" by reading this command's
 * stdout, so the contract is exact: plan names, one per line, nothing else, exit 0 even when empty.
 */
class AppPlansCommandTest extends TestCase
{
    use BuildsApplicationConfigTrait;

    private const STEPS = ['steps' => ['a' => 'true']];

    /** @param array<string, mixed> $overrides */
    private function runCommand(array $overrides = [], array $input = []): CommandTester
    {
        $tester = new CommandTester(new AppPlansCommand($this->applicationConfig($overrides)));
        $tester->execute($input);

        return $tester;
    }

    public function testDeployPlansArePrintedOnePerLineAndNothingElse(): void
    {
        $tester = $this->runCommand(['deploy' => ['plans' => ['initial' => self::STEPS, 'sync' => self::STEPS]]]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertSame("initial\nsync\n", $tester->getDisplay());
    }

    public function testNoPlansIsAnEmptyListRatherThanAnError(): void
    {
        $tester = $this->runCommand();

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertSame('', $tester->getDisplay());
    }

    /** `app:deploy --plan sync` rejects a plan an environment emptied, so it is not listed. */
    public function testABlankedOutPlanIsNotListed(): void
    {
        $tester = $this->runCommand(['deploy' => ['plans' => ['initial' => self::STEPS, 'sync' => []]]]);

        $this->assertSame("initial\n", $tester->getDisplay());
    }

    public function testTypeSelectsAnotherSection(): void
    {
        $tester = $this->runCommand(
            ['deploy' => ['plans' => ['initial' => self::STEPS]], 'build' => ['plans' => ['production' => self::STEPS]]],
            ['--type' => 'build']
        );

        $this->assertSame("production\n", $tester->getDisplay());
    }

    public function testAnUnknownTypeFailsWithoutWritingToStdout(): void
    {
        $this->expectException(InvalidOptionException::class);

        $this->runCommand([], ['--type' => 'sync']);
    }
}
