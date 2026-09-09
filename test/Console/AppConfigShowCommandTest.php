<?php

namespace ConductorAppOrchestrationTest\Console;

use ConductorAppOrchestration\Console\AppConfigShowCommand;
use ConductorAppOrchestrationTest\BuildsApplicationConfigTrait;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * CTAP-1634. The command walked the config with `!is_scalar($value)` as its "this is a branch"
 * test, so a `null` leaf failed the scalar check and was recursed into — straight into
 * `ksort(null)`, a fatal. `shared_path` is nullable and unset by default, so this fired on any
 * config that did not happen to set it.
 */
class AppConfigShowCommandTest extends TestCase
{
    use BuildsApplicationConfigTrait;

    /** @param array<string, mixed> $overrides */
    private function runCommand(array $overrides = [], ?string $filter = null): CommandTester
    {
        $command = new AppConfigShowCommand($this->applicationConfig($overrides), 'app:config:show');
        $tester = new CommandTester($command);
        $tester->execute($filter === null ? [] : ['filter' => $filter]);

        return $tester;
    }

    public function testANullValuedKeyIsRenderedRatherThanRecursedInto(): void
    {
        $tester = $this->runCommand();

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertMatchesRegularExpression('/\bshared_path\b/', $tester->getDisplay());
    }

    /** A null is "declared but unset", which is worth seeing in a config dump — so an empty cell. */
    public function testANullRendersAsAnEmptyCell(): void
    {
        $display = $this->runCommand(filter: 'shared_path')->getDisplay();

        $this->assertMatchesRegularExpression('/\|\s*shared_path\s*\|\s*\|/', $display);
    }

    public function testNullsNestedUnderASubKeyAreRenderedToo(): void
    {
        $tester = $this->runCommand(['databases' => ['mydb' => ['adapter' => null]]]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('databases/mydb/adapter', $tester->getDisplay());
    }

    /** `false` and `null` both rendered as an empty cell before; neither is now ambiguous. */
    public function testBooleansRenderAsTrueAndFalseRatherThanOneAndEmpty(): void
    {
        $display = $this->runCommand(['template_vars' => ['on' => true, 'off' => false]])->getDisplay();

        $this->assertMatchesRegularExpression('/\|\s*template_vars\/off\s*\|\s*false\s*\|/', $display);
        $this->assertMatchesRegularExpression('/\|\s*template_vars\/on\s*\|\s*true\s*\|/', $display);
    }

    public function testAnEmptyArrayIsShownAsAKeyRatherThanVanishing(): void
    {
        $display = $this->runCommand(['template_vars' => []])->getDisplay();

        $this->assertMatchesRegularExpression('/\|\s*template_vars\s*\|\s*\[\]\s*\|/', $display);
    }

    public function testTheFilterStillLimitsTheRowsShown(): void
    {
        $display = $this->runCommand(filter: 'app_*')->getDisplay();

        $this->assertStringContainsString('app_name', $display);
        $this->assertStringNotContainsString('repo_url', $display);
    }
}
