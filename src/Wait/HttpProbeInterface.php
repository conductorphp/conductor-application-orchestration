<?php

namespace ConductorAppOrchestration\Wait;

interface HttpProbeInterface
{
    /**
     * Sends one request. Never throws for a transport failure: connection refused, a DNS failure or
     * a timeout come back as a result with no status and the reason in `error`.
     *
     * @param array<string, string> $headers
     */
    public function probe(
        string $method,
        string $url,
        array $headers,
        ?string $body,
        int $timeout,
        bool $verifyTls
    ): HttpProbeResult;
}
