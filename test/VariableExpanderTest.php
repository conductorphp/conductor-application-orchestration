<?php

namespace ConductorAppOrchestrationTest;

use ConductorAppOrchestration\VariableExpander;
use PHPUnit\Framework\TestCase;

/** CTAP-2150. Bash's expansion rules for a step's text, without a shell. */
class VariableExpanderTest extends TestCase
{
    private const ENV = ['HOST' => 'shop.example.com', 'EMPTY' => ''];

    public function testPlainVariables(): void
    {
        $this->assertSame('https://shop.example.com/rest', $this->expand('https://$HOST/rest', $problems));
        $this->assertSame('https://shop.example.com/rest', $this->expand('https://${HOST}/rest', $problems));
        $this->assertSame([], $problems);
    }

    public function testAnUnsetVariableIsEmptyAndReported(): void
    {
        $this->assertSame('https:///rest', $this->expand('https://${MAGENTO_BASE_URL}/rest', $problems));
        $this->assertSame(['MAGENTO_BASE_URL is not set'], $problems);
    }

    /** As under bash -u, a set but empty variable is not an error. */
    public function testAnEmptyVariableIsNotReported(): void
    {
        $this->assertSame('', $this->expand('$EMPTY', $problems));
        $this->assertSame([], $problems);
    }

    public function testDefaults(): void
    {
        $this->assertSame('x', $this->expand('${MISSING:-x}', $problems));
        $this->assertSame('x', $this->expand('${EMPTY:-x}', $problems));
        $this->assertSame('', $this->expand('${EMPTY-x}', $problems));
        $this->assertSame('x', $this->expand('${MISSING-x}', $problems));
        $this->assertSame('shop.example.com', $this->expand('${HOST:-x}', $problems));
        $this->assertSame([], $problems);
    }

    public function testRequiredVariables(): void
    {
        $this->assertSame('shop.example.com', $this->expand('${HOST:?set it}', $problems));
        $this->assertSame([], $problems);

        $this->expand('${MISSING:?set the commerce base URL}', $problems);
        $this->assertSame(['MISSING set the commerce base URL'], $problems);

        $this->expand('${EMPTY:?}', $problems);
        $this->assertSame(['EMPTY is empty'], $problems);

        // Without the colon an empty value passes
        $this->expand('${EMPTY?}', $problems);
        $this->assertSame([], $problems);
    }

    public function testEscapesAndShellSyntaxStayLiteral(): void
    {
        $this->assertSame('$HOST `id` $(id)', $this->expand('\$HOST `id` $(id)', $problems));
        $this->assertSame([], $problems);
    }

    public function testUnsupportedExpressionsAreFound(): void
    {
        $this->assertSame(['${HOST:+x}', '${#HOST}'], VariableExpander::unsupported('${HOST:+x}/${#HOST}/${HOST:?y}'));
        $this->assertSame([], VariableExpander::unsupported('${HOST}/${A:-b}/${C?d}/\${literal}'));
    }

    private function expand(string $text, ?array &$problems): string
    {
        return VariableExpander::expand($text, self::ENV, $problems);
    }
}
