<?php

namespace ConductorAppOrchestration\Wait;

use Closure;
use ConductorAppOrchestration\Exception;
use Psr\Log\LoggerInterface;

/**
 * Runs a `wait:` step: polls until the URL is ready or the timeout passes.
 *
 * Every poll that is not ready has a reason (the transport error, the status, or the excluded text
 * the body matched). The reason is logged at NOTICE on the first poll, whenever it changes, and
 * otherwise every `log_interval` seconds, so at default verbosity a wrong URL shows up at once and a
 * long wait is distinguishable from a hang without a line per poll.
 */
final class UrlWaiter
{
    private HttpProbeInterface $probe;
    private Closure $clock;
    private Closure $sleep;

    /**
     * @param (Closure(): float)|null      $clock seconds, monotonic enough to measure the wait
     * @param (Closure(float): void)|null  $sleep
     */
    public function __construct(?HttpProbeInterface $probe = null, ?Closure $clock = null, ?Closure $sleep = null)
    {
        $this->probe = $probe ?? new CurlHttpProbe();
        $this->clock = $clock ?? static fn(): float => hrtime(true) / 1e9;
        $this->sleep = $sleep ?? static function (float $seconds): void {
            usleep((int) round($seconds * 1e6));
        };
    }

    /**
     * @throws Exception\RuntimeException once the timeout passes, naming the URL and the last result
     */
    public function wait(UrlWait $wait, LoggerInterface $logger): void
    {
        $target = "$wait->method $wait->url";
        $start = ($this->clock)();
        $deadline = $start + $wait->timeout;
        $lastReason = null;
        $lastLoggedAt = null;
        $polls = 0;

        while (true) {
            $remaining = $deadline - ($this->clock)();
            $result = $this->probe->probe(
                $wait->method,
                $wait->url,
                $wait->headers,
                $wait->body,
                max(1, min($wait->requestTimeout, (int) ceil($remaining))),
                $wait->verifyTls
            );
            $polls++;
            $now = ($this->clock)();
            $elapsed = (int) round($now - $start);
            $reason = $this->notReadyReason($result, $wait);

            if (null === $reason) {
                if (1 === $polls) {
                    $logger->info(sprintf('%s is ready (HTTP %d).', $target, $result->status));
                } else {
                    $logger->notice(sprintf('%s is ready (HTTP %d) after %ds.', $target, $result->status, $elapsed));
                }

                return;
            }

            if ($now >= $deadline) {
                throw new Exception\RuntimeException(sprintf(
                    'Timed out after %ds waiting for %s. Last result: %s.',
                    $wait->timeout,
                    $target,
                    $reason
                ));
            }

            if ($reason !== $lastReason || $now - $lastLoggedAt >= $wait->logInterval) {
                $logger->notice(sprintf(
                    'Waiting for %s: %s (%ds of %ds).',
                    $target,
                    $reason,
                    $elapsed,
                    $wait->timeout
                ));
                $lastReason = $reason;
                $lastLoggedAt = $now;
            }

            ($this->sleep)(min((float) $wait->interval, $deadline - $now));
        }
    }

    /** Null when the result is ready; otherwise what the operator needs to see to fix it. */
    private function notReadyReason(HttpProbeResult $result, UrlWait $wait): ?string
    {
        if (null === $result->status) {
            // curl's "after 3 ms" changes on every poll; dropping it lets a repeated reason be recognized
            return 'no response, ' . preg_replace('/ after \d+ ms/', '', $result->error ?? 'unknown error');
        }

        if (!in_array($result->status, $wait->status, true)) {
            return sprintf('HTTP %d, expected %s', $result->status, implode(' or ', $wait->status));
        }

        foreach ($wait->bodyExcludes as $excluded) {
            if (str_contains($result->body, $excluded)) {
                return sprintf('HTTP %d but the body contains "%s"', $result->status, $excluded);
            }
        }

        return null;
    }
}
