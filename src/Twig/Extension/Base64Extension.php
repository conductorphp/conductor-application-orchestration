<?php

declare(strict_types=1);

namespace ConductorAppOrchestration\Twig\Extension;

use ConductorAppOrchestration\Exception\RuntimeException;
use ConductorCore\Config\EnvVarInterpolator;
use ConductorCore\Exception\InvalidArgumentException;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * `b64decode` for skeleton templates, so a multi-line value can travel as one environment variable.
 *
 * Environment variables are single-line by convention, and a PEM key or a JSON blob is not. The
 * convention (CTAP-1724) is that the variable holds the value base64 encoded and the template
 * decodes it where it is written:
 *
 *     # environments/production/config.yaml
 *     template_vars:
 *       jwt_private_key: '${JWT_PRIVATE_KEY}'
 *
 *     {# config/autoload/jwt.local.php.twig #}
 *     'private_key' => {{ jwt_private_key|b64decode|var_export }},
 *
 * The same decode the interpolator's `${VAR|b64decode}` placeholder does, by the same code
 * ({@see EnvVarInterpolator::applyFilter()}): whitespace stripped, then strict, so anything that is
 * not base64 is an error at deploy time rather than a silently truncated key. Prefer the placeholder
 * form in YAML when the value is rendered through a shared template such as `var-export.php.twig`;
 * this filter is for a template the project owns and wants to decode in.
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
        try {
            return EnvVarInterpolator::applyFilter('b64decode', (string) $value);
        } catch (InvalidArgumentException $exception) {
            throw new RuntimeException('Value passed to the "b64decode" filter is not valid base64.', 0, $exception);
        }
    }
}
