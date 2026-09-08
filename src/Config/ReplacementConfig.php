<?php

declare(strict_types=1);

namespace ConductorAppOrchestration\Config;

use function preg_replace_callback;
use function str_contains;

/**
 * One named find-and-replace applied to a restored snapshot.
 *
 * `$to` is nullable on purpose. A replacement with no `to` is DISABLED, not invalid — the pattern is
 * to declare it in shared defaults and leave `to` unset in the environments that should not run it.
 * The old hand-rolled parser expressed that by logging at debug and `continue`-ing;
 * {@see \ConductorAppOrchestration\Deploy\DatabaseReplacementScript} still does the skipping and the
 * logging, because that is a business rule with a logger to hand, not a validation rule.
 */
final readonly class ReplacementConfig
{
    /** @param list<ReplacementTarget> $targets */
    public function __construct(
        public string $name,
        public string $from,
        public ?string $to,
        public bool $regex,
        public array $targets,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->to !== null;
    }

    /**
     * `$to` with `${VAR}` placeholders filled from the environment vars.
     *
     * An unknown variable is left as written rather than blanked, so a typo shows up in the
     * generated SQL instead of quietly replacing content with an empty string.
     *
     * @param array<string, mixed> $environmentVars
     */
    public function resolvedTo(array $environmentVars): ?string
    {
        if ($this->to === null || $environmentVars === [] || ! str_contains($this->to, '${')) {
            return $this->to;
        }

        return preg_replace_callback(
            '/\$\{([A-Z_]+)\}/',
            static fn(array $matches): string => isset($environmentVars[$matches[1]])
                ? (string) $environmentVars[$matches[1]]
                : $matches[0],
            $this->to,
        );
    }
}
