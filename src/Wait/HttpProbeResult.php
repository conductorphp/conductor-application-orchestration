<?php

namespace ConductorAppOrchestration\Wait;

/** One request's outcome: an HTTP status and body, or no status and the transport error. */
final class HttpProbeResult
{
    public function __construct(
        public readonly ?int $status,
        public readonly string $body = '',
        public readonly ?string $error = null,
    ) {
    }

    public static function transportError(string $error): self
    {
        return new self(null, '', $error);
    }
}
