<?php

namespace ConductorAppOrchestration;

/**
 * Expands variables in a plan step's text (a `notice:`, a `wait:` URL, body or header) the way bash
 * expands them in a `command:` run with the same environment, without handing the text to a shell:
 * backticks and `$(…)` stay literal.
 *
 * Supported, with bash's meaning:
 *
 * - `$NAME`, `${NAME}`
 * - `${NAME:-default}` (unset or empty), `${NAME-default}` (unset)
 * - `${NAME:?message}` (unset or empty is an error), `${NAME?message}` (unset is an error)
 * - `\$` is a literal `$`
 *
 * A variable that is unset, or that fails a `?` test, becomes the empty string and is reported, so a
 * caller that must not proceed can fail the way `bash -u` would (CTAP-2150). Any other `${…}` form is
 * left as it is; {@see unsupported()} finds them so a plan can reject them when it loads.
 */
final class VariableExpander
{
    private const NAME = '[A-Za-z_][A-Za-z0-9_]*';

    /**
     * @param array<string, string> $environment
     * @param list<string>          $problems    one sentence per unset variable or failed `?` test
     */
    public static function expand(string $text, array $environment, ?array &$problems = null): string
    {
        $problems = [];

        return preg_replace_callback(
            '/(?<escaped>\\\\\$)|\$\{(?<braced>' . self::NAME . ')(?:(?<op>:?[-?])(?<word>[^}]*))?\}|\$(?<bare>' . self::NAME . ')/',
            static function (array $match) use ($environment, &$problems): string {
                if (null !== $match['escaped']) {
                    return '$';
                }

                $name = $match['braced'] ?? $match['bare'];
                $isSet = array_key_exists($name, $environment);
                $value = $environment[$name] ?? '';
                $op = $match['op'];

                if (null === $op) {
                    if (! $isSet) {
                        $problems[] = sprintf('%s is not set', $name);
                    }

                    return $value;
                }

                // With a colon, an empty value counts as unset, as in bash
                $missing = str_starts_with($op, ':') ? '' === $value : ! $isSet;
                if (str_ends_with($op, '-')) {
                    return $missing ? $match['word'] : $value;
                }

                if ($missing) {
                    $problems[] = sprintf(
                        '%s %s',
                        $name,
                        '' !== $match['word'] ? $match['word'] : ($isSet ? 'is empty' : 'is not set')
                    );
                }

                return $value;
            },
            $text,
            flags: PREG_UNMATCHED_AS_NULL
        );
    }

    /**
     * The `${…}` expressions in a text that {@see expand()} does not support, such as `${NAME:+x}` or
     * `${#NAME}`. An escaped `\${` is not reported.
     *
     * @return list<string>
     */
    public static function unsupported(string $text): array
    {
        preg_match_all('/(?<!\\\\)\$\{[^}]*\}?/', $text, $matches);

        return array_values(array_filter(
            $matches[0],
            static fn(string $expression): bool => ! preg_match(
                '/^\$\{' . self::NAME . '(?::?[-?][^}]*)?\}$/',
                $expression
            )
        ));
    }
}
