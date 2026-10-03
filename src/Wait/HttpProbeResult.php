<?php

namespace ConductorAppOrchestration\Wait;

/**
 * One request's outcome: an HTTP status and body, or no status and the transport error. A permanent
 * error is one that retrying cannot fix, such as a malformed URL (CTAP-2150).
 */
final class HttpProbeResult
{
    public function __construct(
        public readonly ?int $status,
        public readonly string $body = '',
        public readonly ?string $error = null,
        public readonly bool $permanent = false,
    ) {
    }

    public static function transportError(string $error, bool $permanent = false): self
    {
        return new self(null, '', $error, $permanent);
    }
}
