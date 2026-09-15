<?php

declare(strict_types=1);

namespace ConductorAppOrchestration\Twig\Extension;

use ConductorAppOrchestration\Exception\RuntimeException;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

use function base64_decode;
use function preg_replace;

/**
 * `b64decode` for skeleton templates, so a multi-line value can travel as one environment variable.
 *
 * Environment variables are single-line by convention, and a PEM key or a JSON blob is not. The
 * convention (CTAP-1724) is that the variable holds the value base64 encoded and the template
 * decodes it where it is written:
 *
 *     # environments/production/config.yaml
 *     template_vars:
 *       jwt_private_key: '${JWT_PRIVATE_KEY_B64}'
 *
 *     {# config/autoload/jwt.local.php.twig #}
 *     'private_key' => {{ jwt_private_key|b64decode|var_export }},
 *
 * Whitespace is stripped before decoding, because `base64` wraps its output at 76 columns unless
 * told not to and a trailing newline is easy to carry along. Decoding is strict: anything else that
 * is not base64 is an error at deploy time, not a silently truncated key.
 */
class Base64Extension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('b64decode', [$this, 'decode']),
        ];
    }

    /** @throws RuntimeException when the value is not valid base64 */
    public function decode(?string $value): string
    {
        $decoded = base64_decode((string) preg_replace('/\s+/', '', (string) $value), true);

        if ($decoded === false) {
            throw new RuntimeException('Value passed to the "b64decode" filter is not valid base64.');
        }

        return $decoded;
    }
}
