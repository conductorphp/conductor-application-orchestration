<?php

declare(strict_types=1);

namespace ConductorAppOrchestrationTest\Config;

use ConductorAppOrchestration\Config\ReplacementConfig;
use ConductorAppOrchestration\Config\ReplacementTarget;
use ConductorCore\Config\EnvVarInterpolator;
use ConductorCore\Exception\InvalidPlaceholderException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function base64_encode;

/**
 * CTAP-1728. `ReplacementConfig` and `EnvVarInterpolator` agree on what a placeholder is.
 *
 * `resolvedTo()` used to run its own `/\$\{([A-Z_]+)\}/`, so a name with a digit or a lowercase
 * letter was a placeholder to the config-wide pass and literal text here. It now fills through the
 * interpolator's lenient mode; these pin the cases the old inline pattern got differently.
 */
class ReplacementConfigTest extends TestCase
{
    private function replacement(?string $to): ReplacementConfig
    {
        return new ReplacementConfig('frontend', 'https://www.example.test', $to, false, [
            new ReplacementTarget('core_config_data', 'value'),
        ]);
    }

    public function testFillsFromEnvironmentVarsAsBefore(): void
    {
        $this->assertSame(
            'https://qa.example.test',
            $this->replacement('https://${FRONTEND_DOMAIN}')->resolvedTo(['FRONTEND_DOMAIN' => 'qa.example.test']),
        );
    }

    /** The lenient behavior it always had: a typo shows up in the SQL rather than as an empty string. */
    public function testAnUnknownVariableIsLeftAsWritten(): void
    {
        $this->assertSame(
            'https://${FRONTEND_DOMAIN}',
            $this->replacement('https://${FRONTEND_DOMAIN}')->resolvedTo(['OTHER' => 'x']),
        );
        $this->assertSame('https://${FRONTEND_DOMAIN}', $this->replacement('https://${FRONTEND_DOMAIN}')->resolvedTo([]));
    }

    public function testADisabledReplacementStaysNull(): void
    {
        $this->assertNull($this->replacement(null)->resolvedTo(['FRONTEND_DOMAIN' => 'x']));
    }

    /**
     * Placeholders the old inline `[A-Z_]+` pattern did NOT recognize. The config-wide pass did,
     * so the two disagreed on the same string; they no longer can.
     */
    #[DataProvider('placeholdersTheOldPatternMissed')]
    public function testAgreesWithTheInterpolatorOnWhatAPlaceholderIs(string $to, array $vars, string $expected): void
    {
        $this->assertSame($expected, $this->replacement($to)->resolvedTo($vars));
        $this->assertSame($expected, (new EnvVarInterpolator($vars))->interpolateString($to, 'x'));
    }

    /** @return iterable<string, array{string, array<string, string>, string}> */
    public static function placeholdersTheOldPatternMissed(): iterable
    {
        yield 'digit in the name' => ['https://${DOMAIN2}', ['DOMAIN2' => 'two.example.test'], 'https://two.example.test'];
        yield 'lowercase name'    => ['https://${frontend_domain}', ['frontend_domain' => 'lc.example.test'], 'https://lc.example.test'];
        yield 'escape'            => ['literal $${FRONTEND_DOMAIN}', ['FRONTEND_DOMAIN' => 'x'], 'literal ${FRONTEND_DOMAIN}'];
        yield 'filter'            => ['${VALUE|b64decode}', ['VALUE' => base64_encode('decoded')], 'decoded'];
    }

    /** Shared engine, shared rule: set-but-empty is unset, and is left as written rather than blanked. */
    public function testAnEmptyEnvironmentVarCountsAsUnset(): void
    {
        $this->assertSame(
            'https://${FRONTEND_DOMAIN}',
            $this->replacement('https://${FRONTEND_DOMAIN}')->resolvedTo(['FRONTEND_DOMAIN' => '']),
        );
    }

    public function testABadFilterIsAnErrorNamingTheReplacement(): void
    {
        $this->expectException(InvalidPlaceholderException::class);
        $this->expectExceptionMessageMatches('/replacements\.frontend\.to/');

        $this->replacement('${FRONTEND_DOMAIN|nope}')->resolvedTo(['FRONTEND_DOMAIN' => 'x']);
    }
}
