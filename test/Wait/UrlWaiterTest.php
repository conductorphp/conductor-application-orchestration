<?php

namespace ConductorAppOrchestrationTest\Wait;

use ConductorAppOrchestration\Exception\RuntimeException;
use ConductorAppOrchestration\Wait\HttpProbeInterface;
use ConductorAppOrchestration\Wait\HttpProbeResult;
use ConductorAppOrchestration\Wait\UrlWait;
use ConductorAppOrchestration\Wait\UrlWaiter;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Stringable;

/**
 * CTAP-2145. The poll loop on a fake clock: a scripted probe answers each poll, and sleeping only
 * advances the clock.
 */
class UrlWaiterTest extends TestCase
{
    private float $now = 1000.0;

    /** @var list<HttpProbeResult> */
    private array $script = [];

    /** @var list<array{method: string, url: string, timeout: int}> */
    private array $requests = [];

    /** @var AbstractLogger&object{records: list<array{0: string, 1: string}>} */
    private $logger;

    private UrlWaiter $waiter;

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

        $test = $this;
        $probe = new class ($test) implements HttpProbeInterface {
            public function __construct(private UrlWaiterTest $test)
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
                return $this->test->nextResult($method, $url, $timeout);
            }
        };

        $this->waiter = new UrlWaiter(
            $probe,
            fn(): float => $this->now,
            function (float $seconds): void {
                $this->now += $seconds;
            }
        );
    }

    /** @internal called by the fake probe */
    public function nextResult(string $method, string $url, int $timeout): HttpProbeResult
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'timeout' => $timeout];

        // Past the script, the last answer repeats
        return count($this->script) > 1 ? array_shift($this->script) : $this->script[0];
    }

    public function testReadyOnTheFirstPollLogsNothingAtNotice(): void
    {
        $this->script = [new HttpProbeResult(200, '{}')];

        $this->waiter->wait($this->wait(), $this->logger);

        $this->assertCount(1, $this->requests);
        $this->assertSame([], $this->records(LogLevel::NOTICE));
        $this->assertSame(['GET http://magento/rest is ready (HTTP 200).'], $this->records(LogLevel::INFO));
    }

    public function testPollsThroughConnectionErrorsUntilReady(): void
    {
        $this->script = [
            HttpProbeResult::transportError('Failed to connect to magento port 80 after 1 ms: Connection refused'),
            HttpProbeResult::transportError('Failed to connect to magento port 80 after 3 ms: Connection refused'),
            new HttpProbeResult(502, 'Bad Gateway'),
            new HttpProbeResult(200, '[]'),
        ];

        $this->waiter->wait($this->wait(['interval' => 5]), $this->logger);

        $this->assertCount(4, $this->requests);
        $this->assertSame(
            [
                // The same refusal twice is one line, though curl's "after N ms" differs
                'Waiting for GET http://magento/rest: no response, Failed to connect to magento port 80: '
                . 'Connection refused (0s of 600s).',
                'Waiting for GET http://magento/rest: HTTP 502, expected 200 (10s of 600s).',
                'GET http://magento/rest is ready (HTTP 200) after 15s.',
            ],
            $this->records(LogLevel::NOTICE)
        );
    }

    public function testAnUnchangedReasonIsRepeatedEveryLogInterval(): void
    {
        $this->script = [
            ...array_fill(0, 7, new HttpProbeResult(503)),
            new HttpProbeResult(200),
        ];

        $this->waiter->wait($this->wait(['interval' => 5, 'log_interval' => 15]), $this->logger);

        $this->assertSame(
            [
                'Waiting for GET http://magento/rest: HTTP 503, expected 200 (0s of 600s).',
                'Waiting for GET http://magento/rest: HTTP 503, expected 200 (15s of 600s).',
                'Waiting for GET http://magento/rest: HTTP 503, expected 200 (30s of 600s).',
                'GET http://magento/rest is ready (HTTP 200) after 35s.',
            ],
            $this->records(LogLevel::NOTICE)
        );
    }

    /** Magento answers 200 with "Autoload error" before composer install; that is not ready. */
    public function testAnAcceptedStatusWithAnExcludedBodyIsNotReady(): void
    {
        $this->script = [
            new HttpProbeResult(200, '<html>Autoload error: vendor/autoload.php is missing</html>'),
            new HttpProbeResult(200, '[{"id":"US"}]'),
        ];

        $this->waiter->wait($this->wait(['body_excludes' => ['Autoload error']]), $this->logger);

        $this->assertSame(
            'Waiting for GET http://magento/rest: HTTP 200 but the body contains "Autoload error" (0s of 600s).',
            $this->records(LogLevel::NOTICE)[0]
        );
        $this->assertCount(2, $this->requests);
    }

    public function testAnyListedStatusIsAccepted(): void
    {
        $this->script = [new HttpProbeResult(401)];

        $this->waiter->wait($this->wait(['status' => [200, 401, 403]]), $this->logger);

        $this->assertCount(1, $this->requests);
    }

    public function testTheTimeoutFailsNamingTheUrlAndTheLastResult(): void
    {
        $this->script = [HttpProbeResult::transportError('Could not resolve host: magneto')];

        try {
            $this->waiter->wait($this->wait(['timeout' => 12, 'interval' => 5]), $this->logger);
            $this->fail('Expected the wait to time out.');
        } catch (RuntimeException $e) {
            $this->assertSame(
                'Timed out after 12s waiting for GET http://magento/rest. Last result: no response, '
                . 'Could not resolve host: magneto.',
                $e->getMessage()
            );
        }

        // Polls at 0, 5 and 10s, then once more at the deadline rather than sleeping past it
        $this->assertCount(4, $this->requests);
        $this->assertSame(1012.0, $this->now);
    }

    public function testARequestNeverOutlivesTheDeadline(): void
    {
        $this->script = [new HttpProbeResult(503), new HttpProbeResult(200)];

        $this->waiter->wait($this->wait(['timeout' => 7, 'interval' => 5, 'request_timeout' => 10]), $this->logger);

        $this->assertSame([7, 2], array_column($this->requests, 'timeout'));
    }

    /** CTAP-2150: an error the next poll would hit again fails at once instead of at the timeout. */
    public function testAPermanentErrorFailsAtOnce(): void
    {
        $this->script = [HttpProbeResult::transportError('URL rejected: Malformed input to a URL function', true)];

        try {
            $this->waiter->wait($this->wait(['timeout' => 3600]), $this->logger);
            $this->fail('Expected the wait to fail.');
        } catch (RuntimeException $e) {
            $this->assertSame(
                'Cannot poll GET http://magento/rest: URL rejected: Malformed input to a URL function. '
                . 'Retrying cannot fix this; check the URL.',
                $e->getMessage()
            );
        }

        $this->assertCount(1, $this->requests);
        $this->assertSame(1000.0, $this->now, 'It must not sleep before failing.');
    }

    /** @return list<string> */
    private function records(string $level): array
    {
        return array_values(array_map(
            static fn(array $record): string => $record[1],
            array_filter($this->logger->records, static fn(array $record): bool => $level === $record[0])
        ));
    }

    private function wait(array $config = []): UrlWait
    {
        return UrlWait::fromConfig('wait-for-magento', ['url' => 'http://magento/rest', ...$config]);
    }
}
