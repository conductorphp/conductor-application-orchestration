<?php

declare(strict_types=1);

namespace ConductorAppOrchestration\Deploy;

use ConductorAppOrchestration\Exception;
use ConductorCore\Database\DatabaseAdapterInterface;
use PDO;
use PDOStatement;
use Psr\Log\LoggerInterface;

use function array_key_exists;
use function array_keys;
use function implode;
use function in_array;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_string;
use function json_decode;
use function json_encode;
use function rtrim;
use function sprintf;
use function trim;

use const JSON_THROW_ON_ERROR;

/**
 * Schema-aware helpers for post-import scripts.
 *
 * ## Why this exists
 *
 * Post-import scripts run inside `deploy-databases`, against the RAW RESTORED SNAPSHOT — before any
 * schema migration and before the application's own data installers. A flat `.sql` file cannot ask
 * what it is running against, so every predicate had to be written for whichever schema the source
 * environment's snapshot currently carries, and hand-flipped when the release that changes that
 * schema reached the source. Both failure modes are bad and the silent one is worse:
 *
 * | snapshot state | `WHERE code = 'X'` | `WHERE JSON_EXTRACT(attributes_global, '$.code') = 'X'` |
 * |---|---|---|
 * | column promoted   | correct                     | matches 0 rows, **silently** |
 * | not yet promoted  | deploy dies, unknown column | correct |
 *
 * Neither form can be verified against a working development database — that one has already been
 * migrated, so it misleads in both directions. The only honest test is a freshly restored snapshot.
 *
 * This class reads the live snapshot's schema and picks the predicate itself, so a consumer script
 * describes *what* it wants changed and never carries a "flip this once the source deploys" note.
 *
 * ## Why it opens its own connection
 *
 * {@see DatabaseAdapterInterface} exposes only `run(): void` — no result set — so a post-import
 * script cannot read the schema through it. The adapter's credentials are in the application config
 * (`database.adapters.default.arguments`), which {@see PostImportScriptInterface::execute()}
 * receives, so this builds a read-only companion connection from the same credentials. There is
 * nothing extra to configure per project. Table and column existence both come from one
 * `information_schema` query on that connection.
 *
 * ## What post-import is NOT for
 *
 * Anything the application's data installers create. Those run several steps AFTER post-import, so a
 * record introduced by a newer module version has no row in the snapshot at all and NO post-import
 * SQL in ANY predicate form can touch it. Such values belong in a deploy step ordered after the
 * data-installer step. Every mutator here logs a clear skip in that case rather than issuing a
 * silent 0-row UPDATE.
 *
 * Post-import is the right lever for exactly one thing: correcting values the snapshot already
 * carries — credentials, endpoints, domains — before the application boots against them.
 *
 * ## Usage
 *
 * A consumer composes a {@see PostImportScript} around a callable and never touches this
 * constructor — the script builds the support object and collects the result.
 *
 * ```php
 * // config/app/files/environment-settings.php
 * return new PostImportScript(
 *     naturalKeys: ['system_setting' => 'path'],
 *     apply: static function (PostImportSupport $support): void {
 *         $support->setGlobalAttributes('system_setting', 'area/frontend/cookie_domain', [
 *             'value' => 'example.qa.test',
 *         ]);
 *     },
 * );
 * ```
 */
final class PostImportSupport
{
    /**
     * Natural key column per table, registered by the consuming application.
     *
     * The name is both the promoted COLUMN and the pre-promotion `attributes_global` JSON key: a
     * promotion migration moves the value from `$.<key>` into a column of the same name and strips
     * it from the JSON, so one name describes both schemas.
     *
     * @var array<string, string>
     */
    private array $naturalKeys = [];

    /** Scoped-entity companions to `attributes_global`, in resolution order (lowest last). */
    private const SCOPE_COLUMNS = ['attributes_view', 'attributes_website'];

    /** @var list<string> */
    private array $statements = [];

    /** @var array<string, list<string>>|null */
    private ?array $columns = null;

    private ?PDO $pdo = null;

    /** @param array<string, mixed> $config */
    public function __construct(
        private readonly DatabaseAdapterInterface $databaseAdapter,
        private readonly string $databaseName,
        private readonly array $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Register the natural key column for one or more tables.
     *
     * @param array<string, string> $naturalKeys table => key column
     */
    public function withNaturalKeys(array $naturalKeys): self
    {
        foreach ($naturalKeys as $table => $keyColumn) {
            $this->naturalKeys[$table] = $keyColumn;
        }

        return $this;
    }

    public function logger(): LoggerInterface
    {
        return $this->logger;
    }

    /** Escape hatch: the deployer's own adapter, for a consumer that needs more than the helpers. */
    public function databaseAdapter(): DatabaseAdapterInterface
    {
        return $this->databaseAdapter;
    }

    public function databaseName(): string
    {
        return $this->databaseName;
    }

    /** The environment being deployed, e.g. `qa`. */
    public function environment(): string
    {
        $environment = $this->config['current_environment'] ?? null;

        return is_string($environment) && $environment !== '' ? $environment : 'unknown';
    }

    /** @return array<string, mixed> */
    public function environmentVars(): array
    {
        $vars = $this->config['environment_vars'] ?? [];

        return is_array($vars) ? $vars : [];
    }

    // ------------------------------------------------------------------ schema introspection

    public function hasTable(string $table): bool
    {
        $this->loadSchema();

        return isset($this->columns[$table]);
    }

    public function hasColumn(string $table, string $column): bool
    {
        return in_array($column, $this->columnsFor($table), true);
    }

    // ------------------------------------------------------------------ addressing a row

    /**
     * The WHERE fragment identifying `$key` in `$table` on the schema actually present.
     *
     * Returns null — with a logged reason — when the table is absent, no natural key is registered
     * for it, or neither the column nor the JSON key is available. A null return means "skip this",
     * never "match everything".
     */
    public function keyPredicate(string $table, string $key): ?string
    {
        if (! $this->hasTable($table)) {
            $this->skip("table `$table` is not in the snapshot");

            return null;
        }

        $keyColumn = $this->naturalKeys[$table] ?? null;
        if ($keyColumn === null) {
            $this->skip("no natural key registered for `$table` — call withNaturalKeys()");

            return null;
        }

        if ($this->hasColumn($table, $keyColumn)) {
            return sprintf('`%s` = %s', $keyColumn, $this->quote($key));
        }

        if ($this->hasColumn($table, 'attributes_global')) {
            // Pre-promotion snapshot: the key still lives in the JSON bag.
            //
            // JSON_UNQUOTE(JSON_EXTRACT(...)) rather than the tidier JSON_VALUE(): availability of
            // JSON_VALUE varies by patch level (observed "FUNCTION JSON_VALUE does not exist" on a
            // MariaDB 10.6.x, while 10.6.25 has it — there is no clean version boundary to rely on).
            // This form is exactly equivalent and works on every supported MySQL and MariaDB.
            return sprintf(
                "JSON_UNQUOTE(JSON_EXTRACT(`attributes_global`, '$.%s')) = %s",
                $keyColumn,
                $this->quote($key),
            );
        }

        $this->skip("`$table` has neither a `$keyColumn` column nor `attributes_global`");

        return null;
    }

    public function rowExists(string $table, string $predicate): bool
    {
        return $this->query(sprintf('SELECT 1 FROM `%s` WHERE %s LIMIT 1', $table, $predicate))
            ->fetchColumn() !== false;
    }

    // ------------------------------------------------------------------ mutations

    /**
     * Set scoped attributes at GLOBAL scope.
     *
     * Global is the right scope for post-import corrections: an environment-wide fix is not a
     * per-website or per-view decision.
     *
     * A `null` value REMOVES the attribute rather than writing a JSON null. That matters — a read
     * path that treats an absent `value` as "fall back to the default" would be defeated by an
     * explicit null.
     *
     * @param array<string, mixed> $attributes
     */
    public function setGlobalAttributes(string $table, string $key, array $attributes): void
    {
        $predicate = $this->keyPredicate($table, $key);
        if ($predicate === null || ! $this->requireRow($table, $key, $predicate)) {
            return;
        }

        if (! $this->hasColumn($table, 'attributes_global')) {
            $this->skip("`$table` has no `attributes_global` column");

            return;
        }

        $sets    = [];
        $removes = [];
        foreach ($attributes as $attribute => $value) {
            if ($value === null) {
                $removes[] = sprintf("'$.%s'", $attribute);
                continue;
            }

            $sets[] = sprintf("'$.%s', %s", $attribute, $this->jsonLiteral($value));
        }

        // JSON_REMOVE first, then JSON_SET, so an attribute given in both ends up set.
        $expression = '`attributes_global`';
        if ($removes !== []) {
            $expression = sprintf('JSON_REMOVE(%s, %s)', $expression, implode(', ', $removes));
        }
        if ($sets !== []) {
            $expression = sprintf('JSON_SET(%s, %s)', $expression, implode(', ', $sets));
        }
        if ($expression === '`attributes_global`') {
            return;
        }

        $this->statements[] = sprintf(
            'UPDATE `%s` SET `attributes_global` = %s WHERE %s',
            $table,
            $expression,
            $predicate,
        );
    }

    /**
     * Remove one attribute from every WEBSITE and VIEW scope bag on a row, leaving its siblings.
     *
     * Scoped reads resolve view -> website -> global, so a scoped value carried in from the snapshot
     * shadows whatever a script writes at global. That is not hypothetical: a gateway rejected
     * transactions with E00007 because global held the corrected credential while a stale view copy
     * held the old one.
     *
     * The scope CODES are discovered from the row rather than enumerated by the caller — the bags
     * are keyed by website/view code, which differ per project. Siblings are preserved: setting
     * `attributes_view = NULL` would also drop per-view `is_enabled`, which is a real setting.
     */
    public function clearScopedAttribute(string $table, string $key, string $attribute): void
    {
        $predicate = $this->keyPredicate($table, $key);
        if ($predicate === null || ! $this->requireRow($table, $key, $predicate)) {
            return;
        }

        foreach (self::SCOPE_COLUMNS as $column) {
            if (! $this->hasColumn($table, $column)) {
                continue;
            }

            $scopeCodes = $this->scopeCodesHolding($table, $predicate, $column, $attribute);
            if ($scopeCodes === []) {
                continue;
            }

            $paths = [];
            foreach ($scopeCodes as $scopeCode) {
                $paths[] = sprintf('\'$."%s".%s\'', $scopeCode, $attribute);
            }

            $this->statements[] = sprintf(
                'UPDATE `%s` SET `%s` = JSON_REMOVE(`%s`, %s) WHERE %s',
                $table,
                $column,
                $column,
                implode(', ', $paths),
                $predicate,
            );

            $this->logger->info(sprintf(
                'Post-import: dropping `%s`.%s override for "%s" in %s scope(s) %s, so the global '
                . 'value is what resolves.',
                $table,
                $attribute,
                $key,
                $column === 'attributes_view' ? 'view' : 'website',
                implode(', ', $scopeCodes),
            ));
        }
    }

    /**
     * Set keys inside a plain (unscoped) JSON column.
     *
     * JSON_SET touches only the given paths, so unrelated keys in the same document — OAuth
     * credentials sitting next to an endpoint, say — are preserved.
     *
     * @param array<string, mixed> $pathValues JSON path without the leading `$.` => value
     */
    public function setJsonKeys(string $table, string $key, string $column, array $pathValues): void
    {
        $predicate = $this->keyPredicate($table, $key);
        if ($predicate === null || ! $this->requireRow($table, $key, $predicate)) {
            return;
        }

        if (! $this->hasColumn($table, $column)) {
            $this->skip("`$table` has no `$column` column");

            return;
        }

        $sets = [];
        foreach ($pathValues as $path => $value) {
            $sets[] = sprintf("'$.%s', %s", $path, $this->jsonLiteral($value));
        }

        if ($sets === []) {
            return;
        }

        $this->statements[] = sprintf(
            'UPDATE `%s` SET `%s` = JSON_SET(`%s`, %s) WHERE %s',
            $table,
            $column,
            $column,
            implode(', ', $sets),
            $predicate,
        );
    }

    /** Escape hatch for a statement the helpers do not cover. */
    public function addStatement(string $sql): void
    {
        // Trailing whitespace and semicolons come off together, in one pass. Stripping only ';'
        // would leave "UPDATE ... ;" as "UPDATE ... " and toSql() would re-emit the gap; doing it in
        // two steps would still miss "UPDATE ...; ;". toSql() adds the one terminator.
        $this->statements[] = rtrim(trim($sql), " \t\n\r\0\x0B;");
    }

    // ------------------------------------------------------------------ output

    public function skip(string $reason): void
    {
        $this->logger->notice("Post-import: skipping — $reason.");
    }

    /**
     * The collected SQL, or an empty string for a clean skip (which the deployer already logs).
     *
     * No comments are emitted: `DatabaseAdapterInterface::run()` strips `--` and slash-star comments
     * before splitting on `;`, so a comment would be discarded anyway. The reasoning belongs in the
     * script that called these methods, where it stays readable.
     */
    public function toSql(): string
    {
        if ($this->statements === []) {
            return '';
        }

        return implode(";\n", $this->statements) . ';';
    }

    // ------------------------------------------------------------------ internals

    /** Log-and-skip when the target row is absent: never a silent 0-row UPDATE. */
    private function requireRow(string $table, string $key, string $predicate): bool
    {
        if ($this->rowExists($table, $predicate)) {
            return true;
        }

        $this->logger->notice(sprintf(
            'Post-import: skipping — `%s` has no row for "%s" in the snapshot. Post-import runs '
            . 'before the application data installers, so a record they create cannot be addressed '
            . 'here; set it in a deploy step ordered after that step instead.',
            $table,
            $key,
        ));

        return false;
    }

    /**
     * Website/view scope codes on this row whose bag holds `$attribute`.
     *
     * @return list<string>
     */
    private function scopeCodesHolding(
        string $table,
        string $predicate,
        string $column,
        string $attribute,
    ): array {
        $sql  = sprintf('SELECT `%s` FROM `%s` WHERE %s', $column, $table, $predicate);
        $held = [];

        foreach ($this->query($sql)->fetchAll(PDO::FETCH_COLUMN) as $json) {
            if (! is_string($json) || $json === '') {
                continue;
            }

            $decoded = json_decode($json, true);
            if (! is_array($decoded)) {
                continue;
            }

            foreach ($decoded as $scopeCode => $attributes) {
                if (is_array($attributes) && array_key_exists($attribute, $attributes)) {
                    $held[(string) $scopeCode] = true;
                }
            }
        }

        return array_keys($held);
    }

    /** @return list<string> */
    private function columnsFor(string $table): array
    {
        $this->loadSchema();

        return $this->columns[$table] ?? [];
    }

    /**
     * The whole schema in one query: table names AND their columns.
     *
     * Deliberately not `DatabaseAdapterInterface::getTableMetadata()`. That would answer table
     * existence but not columns, so this query is needed regardless — and the MySQL adapter's
     * implementation reads a lower-cased `table_rows` key that MySQL 8 returns as `TABLE_ROWS`,
     * emitting an "Undefined array key" warning for every table in the database.
     */
    private function loadSchema(): void
    {
        if ($this->columns !== null) {
            return;
        }

        $this->columns = [];
        $sql           = 'SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS '
            . 'WHERE TABLE_SCHEMA = ' . $this->quote($this->databaseName)
            . ' ORDER BY TABLE_NAME, ORDINAL_POSITION';

        foreach ($this->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $this->columns[(string) $row['TABLE_NAME']][] = (string) $row['COLUMN_NAME'];
        }
    }

    private function query(string $sql): PDOStatement
    {
        $statement = $this->pdo()->query($sql);
        if ($statement === false) {
            throw new Exception\RuntimeException("Failed to run post-import introspection query: $sql");
        }

        return $statement;
    }

    /**
     * Read-only companion connection, built from the same credentials conductor gives its own
     * database adapter.
     */
    private function pdo(): PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }

        $arguments = $this->config['database']['adapters']['default']['arguments'] ?? null;
        if (! is_array($arguments)) {
            throw new Exception\RuntimeException(
                'Cannot inspect the snapshot schema: database.adapters.default.arguments is missing '
                . 'from the application config.',
            );
        }

        $this->pdo = new PDO(
            sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                (string) ($arguments['host'] ?? 'localhost'),
                (int) ($arguments['port'] ?? 3306),
                $this->databaseName,
            ),
            (string) ($arguments['username'] ?? ''),
            (string) ($arguments['password'] ?? ''),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );

        return $this->pdo;
    }

    private function quote(string $value): string
    {
        return $this->pdo()->quote($value);
    }

    /** A PHP value as a SQL literal that JSON_SET stores with the right JSON type. */
    private function jsonLiteral(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_array($value)) {
            // CAST(... AS JSON) so an array lands as a JSON array/object, not a quoted string.
            return sprintf('CAST(%s AS JSON)', $this->quote(json_encode($value, JSON_THROW_ON_ERROR)));
        }

        return $this->quote((string) $value);
    }
}
