<?php

namespace ConductorAppOrchestration\Wait;

use ConductorAppOrchestration\Exception;

/**
 * A plan step's `wait:` block: poll a URL until it answers with an accepted status, and a body that
 * contains none of the excluded strings, or fail once the timeout passes.
 */
final class UrlWait
{
    public const DEFAULT_STATUS = [200];
    public const DEFAULT_TIMEOUT = 600;
    public const DEFAULT_INTERVAL = 5;
    public const DEFAULT_LOG_INTERVAL = 30;
    public const DEFAULT_REQUEST_TIMEOUT = 10;

    private const KEYS = [
        'url',
        'method',
        'headers',
        'body',
        'status',
        'body_excludes',
        'timeout',
        'interval',
        'log_interval',
        'request_timeout',
        'verify_tls',
    ];

    /**
     * @param array<string, string> $headers
     * @param list<int>             $status
     * @param list<string>          $bodyExcludes
     */
    private function __construct(
        public readonly string $url,
        public readonly string $method,
        public readonly array $headers,
        public readonly ?string $body,
        public readonly array $status,
        public readonly array $bodyExcludes,
        public readonly int $timeout,
        public readonly int $interval,
        public readonly int $logInterval,
        public readonly int $requestTimeout,
        public readonly bool $verifyTls,
    ) {
    }

    /**
     * Validates a step's `wait:` block and fills in the defaults.
     *
     * @throws Exception\RuntimeException naming the step and the offending key
     */
    public static function fromConfig(string $stepName, mixed $config): self
    {
        $invalid = static fn(string $problem): Exception\RuntimeException => new Exception\RuntimeException(
            sprintf('Step "%s" key "wait" %s', $stepName, $problem)
        );

        if (!is_array($config)) {
            throw $invalid('must be a map with at least a "url".');
        }

        $unknownKeys = array_diff(array_keys($config), self::KEYS);
        if ($unknownKeys) {
            throw $invalid(sprintf(
                'has unknown %s "%s". Allowed: %s.',
                1 === count($unknownKeys) ? 'key' : 'keys',
                implode('", "', $unknownKeys),
                implode(', ', self::KEYS)
            ));
        }

        $url = $config['url'] ?? null;
        if (!is_string($url) || '' === trim($url)) {
            throw $invalid('must include a non-empty string "url".');
        }

        $method = $config['method'] ?? 'GET';
        if (!is_string($method) || !preg_match('/^[A-Za-z]+$/', $method)) {
            throw $invalid('"method" must be an HTTP method such as GET or POST.');
        }

        $headers = $config['headers'] ?? [];
        if (!is_array($headers)) {
            throw $invalid('"headers" must be a map of header name to value.');
        }
        foreach ($headers as $headerName => $headerValue) {
            if (!is_string($headerName) || !is_scalar($headerValue)) {
                throw $invalid('"headers" must be a map of header name to value.');
            }
        }

        $body = $config['body'] ?? null;
        if (null !== $body && !is_string($body)) {
            throw $invalid('"body" must be a string.');
        }

        $status = $config['status'] ?? self::DEFAULT_STATUS;
        if (is_int($status)) {
            $status = [$status];
        }
        if (!is_array($status) || !$status) {
            throw $invalid('"status" must be a list of HTTP status codes.');
        }
        foreach ($status as $code) {
            if (!is_int($code) || $code < 100 || $code > 599) {
                throw $invalid('"status" must be a list of HTTP status codes.');
            }
        }

        $bodyExcludes = $config['body_excludes'] ?? [];
        if (is_string($bodyExcludes)) {
            $bodyExcludes = [$bodyExcludes];
        }
        if (!is_array($bodyExcludes)) {
            throw $invalid('"body_excludes" must be a list of strings.');
        }
        foreach ($bodyExcludes as $excluded) {
            if (!is_string($excluded) || '' === $excluded) {
                throw $invalid('"body_excludes" must be a list of non-empty strings.');
            }
        }

        $seconds = static function (string $key, int $default) use ($config, $invalid): int {
            $value = $config[$key] ?? $default;
            if (!is_int($value) || $value < 1) {
                throw $invalid(sprintf('"%s" must be a whole number of seconds, 1 or more.', $key));
            }

            return $value;
        };

        $verifyTls = $config['verify_tls'] ?? true;
        if (!is_bool($verifyTls)) {
            throw $invalid('"verify_tls" must be true or false.');
        }

        return new self(
            $url,
            strtoupper($method),
            array_map('strval', $headers),
            $body,
            array_values($status),
            array_values($bodyExcludes),
            $seconds('timeout', self::DEFAULT_TIMEOUT),
            $seconds('interval', self::DEFAULT_INTERVAL),
            $seconds('log_interval', self::DEFAULT_LOG_INTERVAL),
            $seconds('request_timeout', self::DEFAULT_REQUEST_TIMEOUT),
            $verifyTls,
        );
    }
}
