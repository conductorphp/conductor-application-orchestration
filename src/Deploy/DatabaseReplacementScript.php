<?php

declare(strict_types=1);

namespace ConductorAppOrchestration\Deploy;

use ConductorAppOrchestration\Config\ApplicationConfig;
use ConductorAppOrchestration\Config\ReplacementConfig;
use ConductorAppOrchestration\Config\ReplacementTarget;
use ConductorCore\Database\DatabaseAdapterInterface;
use Psr\Log\LoggerInterface;

use function count;
use function date;
use function in_array;
use function implode;
use function preg_replace;
use function sprintf;
use function str_replace;

/**
 * Performs find-and-replace on database columns after importing a snapshot.
 *
 * Simple string replacement or regex with capture groups, per named replacement, with the target
 * table/column pairs and the environment-specific `to` value coming from config.
 *
 * ## What changed in 4.0 (CTAP-1630)
 *
 * Config parsing left this class. It used to hand-validate the replacement tree — around forty lines
 * of `is_array()` / `isset()` / `is_string()` / `count(explode())` checks, each arm logging a warning
 * and skipping that one entry, so a config with three mistakes took three deploys to find. That shape
 * is now declared once as a schema on {@see \ConductorAppOrchestration\Config\DeployConfig}, which
 * reports every problem at once and hands over typed {@see ReplacementConfig} objects.
 *
 * The production guard also works now. It compared `$config['current_environment']`, a key nothing in
 * conductor ever wrote — the config carries `environment` — so the comparison always saw `'unknown'`
 * and **replacements ran in production**, which is the one thing this guard exists to prevent.
 */
class DatabaseReplacementScript implements PostImportScriptInterface
{
    /** Environment names that must never be rewritten. */
    private const PROTECTED_ENVIRONMENTS = ['production', 'prod'];

    public function execute(
        DatabaseAdapterInterface $databaseAdapter,
        string $databaseName,
        ApplicationConfig $config,
        LoggerInterface $logger
    ): string {
        $environment = $config->environment;

        if (in_array($environment, self::PROTECTED_ENVIRONMENTS, true)) {
            $logger->info("Skipping database replacements in \"$environment\" environment.");

            return '';
        }

        $replacements = $config->deployConfig->getReplacements($databaseName);
        if ($replacements === []) {
            $logger->info("No replacements configured for database \"$databaseName\", skipping.");

            return '';
        }

        $logger->debug(sprintf(
            'Starting database replacements for database "%s", environment "%s": %d replacement(s).',
            $databaseName,
            $environment,
            count($replacements),
        ));

        return $this->generateReplacementSql(
            $databaseAdapter,
            $databaseName,
            $replacements,
            $config->environmentVars,
            $logger,
        );
    }

    /**
     * @param list<ReplacementConfig> $replacements
     * @param array<string, string>   $environmentVars
     */
    private function generateReplacementSql(
        DatabaseAdapterInterface $databaseAdapter,
        string $databaseName,
        array $replacements,
        array $environmentVars,
        LoggerInterface $logger
    ): string {
        $sqlStatements = [
            '-- Database Replacement Script',
            '-- Generated: ' . date('Y-m-d H:i:s'),
            "-- Database: $databaseName",
            '',
        ];

        $generatedStatements = 0;
        $operations          = 0;

        foreach ($replacements as $replacement) {
            // No `to` means the replacement is switched off for this environment — declared in
            // shared config, left unset where it should not run. Not a misconfiguration.
            if (! $replacement->isEnabled()) {
                $logger->debug("Replacement \"{$replacement->name}\" has no 'to' value, skipping.");
                continue;
            }

            $to = (string) $replacement->resolvedTo($environmentVars);

            foreach ($replacement->targets as $target) {
                $operations++;
                $description = "$target.{$replacement->name}";
                $logger->debug("Processing replacement: $description");
                $sqlStatements[] = "-- Replacement: $description";

                $skipReason = $this->skipReason($databaseAdapter, $databaseName, $target);
                if ($skipReason !== null) {
                    $logger->debug("$target does not exist, skipping.");
                    $sqlStatements[] = "-- SKIPPED: $skipReason";
                    $sqlStatements[] = '';
                    continue;
                }

                $sqlStatements[] = $replacement->regex
                    ? $this->regexReplacement($databaseAdapter, $target, $replacement->from, $to)
                    : $this->stringReplacement($databaseAdapter, $target, $replacement->from, $to);
                $sqlStatements[] = '';
                $generatedStatements++;
            }
        }

        $sqlStatements[] = '-- Replacement Summary';
        $sqlStatements[] = "-- Operations processed: $operations";
        $sqlStatements[] = "-- SQL statements generated: $generatedStatements";

        $logger->debug("Database replacements complete: $generatedStatements statement(s) generated.");

        return implode("\n", $sqlStatements);
    }

    /** Why this target cannot be rewritten, or null when it can. */
    private function skipReason(
        DatabaseAdapterInterface $databaseAdapter,
        string $databaseName,
        ReplacementTarget $target
    ): ?string {
        $tables = $databaseAdapter->getTableMetadata($databaseName);
        if (! isset($tables[$target->table])) {
            return 'Table does not exist';
        }

        // Before conductor/core 4.0 the interface could not return a result, so this check did not
        // exist: columnExists() returned true unconditionally and a replacement naming a dropped
        // column produced an UPDATE that failed mid-deploy (CTAP-1629).
        $columns = $databaseAdapter->fetchAll(
            'SELECT 1 FROM information_schema.COLUMNS '
            . 'WHERE TABLE_SCHEMA = :database AND TABLE_NAME = :table AND COLUMN_NAME = :column LIMIT 1',
            $databaseName,
            [':database' => $databaseName, ':table' => $target->table, ':column' => $target->column],
        );

        return $columns === [] ? 'Column does not exist' : null;
    }

    /**
     * Replace both the plain and the JSON-escaped spelling of `$from`.
     *
     * Snapshot content holds URLs both ways — `https://example.com` in a text column and
     * `https:\/\/example.com` inside a JSON one — so a replacement that handled only the plain form
     * would leave every JSON-embedded copy behind.
     */
    private function stringReplacement(
        DatabaseAdapterInterface $databaseAdapter,
        ReplacementTarget $target,
        string $from,
        string $to
    ): string {
        return sprintf(
            'UPDATE `%s` SET `%s` = REPLACE(REPLACE(`%s`, %s, %s), %s, %s) WHERE `%s` LIKE %s OR `%s` LIKE %s;',
            $target->table,
            $target->column,
            $target->column,
            $databaseAdapter->quote($this->escapeForJson($from)),
            $databaseAdapter->quote($this->escapeForJson($to)),
            $databaseAdapter->quote($from),
            $databaseAdapter->quote($to),
            $target->column,
            $databaseAdapter->quote('%' . $this->escapeForJsonLike($from) . '%'),
            $target->column,
            $databaseAdapter->quote("%$from%"),
        );
    }

    private function regexReplacement(
        DatabaseAdapterInterface $databaseAdapter,
        ReplacementTarget $target,
        string $from,
        string $to
    ): string {
        $fromJsonPattern   = $this->regexPatternForJson($from);
        $toJsonReplacement = $this->regexReplacementForJson($to);

        return sprintf(
            'UPDATE `%s` SET `%s` = REGEXP_REPLACE(REGEXP_REPLACE(`%s`, %s, %s), %s, %s) '
            . 'WHERE `%s` REGEXP %s OR `%s` REGEXP %s;',
            $target->table,
            $target->column,
            $target->column,
            $databaseAdapter->quote($fromJsonPattern),
            $databaseAdapter->quote($toJsonReplacement),
            $databaseAdapter->quote($from),
            $databaseAdapter->quote($to),
            $target->column,
            $databaseAdapter->quote($fromJsonPattern),
            $target->column,
            $databaseAdapter->quote($from),
        );
    }

    /** `https://domain.com` -> `https:\/\/domain.com`, the JSON-escaped spelling. */
    private function escapeForJson(string $value): string
    {
        return str_replace('/', '\\/', $value);
    }

    /**
     * The JSON-escaped spelling as a LIKE pattern.
     *
     * Four backslashes in the SQL literal: two survive SQL string parsing, two more survive LIKE
     * pattern parsing, leaving the one literal backslash that matches the stored `\/`.
     */
    private function escapeForJsonLike(string $value): string
    {
        return str_replace('/', '\\\\\/', $value);
    }

    /** A regex pattern rewritten to match JSON-escaped forward slashes. */
    private function regexPatternForJson(string $pattern): string
    {
        return str_replace('/', '\\\\/', $pattern);
    }

    /** A regex replacement rewritten to emit JSON-escaped slashes, preserving `\1` capture refs. */
    private function regexReplacementForJson(string $replacement): string
    {
        $replacement = preg_replace('/\\\\(\d+)/', '<<<CAPTURE$1>>>', $replacement);
        $replacement = str_replace('/', '\\/', (string) $replacement);

        return (string) preg_replace('/<<<CAPTURE(\d+)>>>/', '\\\\$1', $replacement);
    }
}
