<?php

declare(strict_types=1);

namespace ConductorAppOrchestration\Config;

use ConductorCore\Config\EnvVarInterpolator;

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
     * The filling is done by {@see EnvVarInterpolator} in its lenient mode, so what counts as a
     * placeholder — the name characters, the `$${VAR}` escape, a `|filter` — is decided in exactly
     * one place. This used to run its own `/\$\{([A-Z_]+)\}/`, which meant `${DOMAIN2}` or
     * `${frontend_domain}` was a placeholder to the post-processor and literal text here (CTAP-1728).
     * Two things follow from sharing the engine: an empty environment var now counts as unset and is
     * left as written rather than substituted with nothing, and a bad `|filter` is an error.
     *
     * This predates config-wide interpolation. A project that wires
     * {@see EnvVarInterpolationPostProcessor} into its `config.php` has every placeholder resolved
     * — or rejected — at config load, and `$to` arrives here with none left, so this is a no-op
     * there. It stays for projects that have not adopted the post-processor.
     *
     * @param array<string, mixed> $environmentVars
     * @throws \ConductorCore\Exception\InvalidPlaceholderException On an unknown filter or a rejected value.
     */
    public function resolvedTo(array $environmentVars): ?string
    {
        if ($this->to === null || ! str_contains($this->to, '${')) {
            return $this->to;
        }

        return (new EnvVarInterpolator($environmentVars))->interpolateString(
            $this->to,
            "replacements.{$this->name}.to",
            failOnUndefined: false,
        );
    }
}
