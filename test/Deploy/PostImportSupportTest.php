<?php

namespace ConductorAppOrchestrationTest\Deploy;

use ConductorAppOrchestration\Deploy\PostImportSupport;
use ConductorAppOrchestrationTest\BuildsApplicationConfigTrait;
use ConductorCore\Database\DatabaseAdapterInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

use function addslashes;
use function str_contains;

/**
 * CTAP-1629. The schema-dependent half of PostImportSupport, which only became testable when the
 * class stopped opening its own PDO connection.
 *
 * Before this, the schema came from a connection the class built itself from config, so a test had
 * no seam: a "mock" of the schema was impossible and the only honest check was the live-database
 * script in test/manual. Now the schema arrives through DatabaseAdapterInterface::fetchAll(), so a
 * scripted adapter can present either snapshot shape and the predicate choice is assertable here.
 *
 * The manual script stays as the integration check — that the emitted SQL is valid on real MySQL and
 * MariaDB is not something a double can tell you.
 */
class PostImportSupportTest extends TestCase
{
    use BuildsApplicationConfigTrait;

    /** As a promotion migration leaves it: the natural key is a real column. */
    private const PROMOTED = [
        'payment_method' => ['id', 'code', 'attributes_global', 'attributes_website', 'attributes_view'],
    ];

    /** Before that migration: the key is still a JSON key inside attributes_global. */
    private const PRE_PROMOTION = [
        'payment_method' => ['id', 'attributes_global', 'attributes_website', 'attributes_view'],
    ];

    /**
     * An adapter whose schema and row reads are scripted.
     *
     * @param array<string, list<string>>  $schema     table => columns
     * @param array<string, list<array<string, mixed>>> $rowsBySqlFragment a fragment of the SQL =>
     *     the rows that read returns. Anything unmatched returns no rows, which is what "the
     *     snapshot does not have it" looks like.
     */
    private function adapter(array $schema, array $rowsBySqlFragment = []): DatabaseAdapterInterface
    {
        // A stub, not a mock: this double only scripts return values. PHPUnit notices a mock with no
        // configured expectations. The two tests that assert HOW OFTEN fetchAll is called use a mock.
        $adapter = $this->createStub(DatabaseAdapterInterface::class);

        $adapter->method('fetchAll')->willReturnCallback(
            static function (string $sql, string $database, ?array $parameters = null) use ($schema, $rowsBySqlFragment): array {
                if (str_contains($sql, 'information_schema.COLUMNS')) {
                    $rows = [];
                    foreach ($schema as $table => $columns) {
                        foreach ($columns as $column) {
                            $rows[] = ['TABLE_NAME' => $table, 'COLUMN_NAME' => $column];
                        }
                    }

                    return $rows;
                }

                foreach ($rowsBySqlFragment as $fragment => $rows) {
                    if (str_contains($sql, $fragment)) {
                        return $rows;
                    }
                }

                return [];
            }
        );

        $adapter->method('quote')->willReturnCallback(
            static fn(string $value): string => "'" . addslashes($value) . "'"
        );

        return $adapter;
    }

    private function collectingLogger(): LoggerInterface
    {
        return new class extends AbstractLogger {
            /** @var list<string> */
            public array $lines = [];

            public function log($level, $message, array $context = []): void
            {
                $this->lines[] = strtoupper((string) $level) . ': ' . $message;
            }
        };
    }

    /**
     * @param array<string, list<string>> $schema
     * @param array<string, list<array<string, mixed>>> $rows
     */
    private function support(
        array $schema,
        array $rows = [],
        ?LoggerInterface $logger = null,
        array $naturalKeys = ['payment_method' => 'code'],
    ): PostImportSupport {
        $support = new PostImportSupport(
            $this->adapter($schema, $rows),
            'snapshot_db',
            $this->applicationConfig(),
            $logger ?? $this->collectingLogger(),
        );

        return $support->withNaturalKeys($naturalKeys);
    }

    // ------------------------------------------------------------------ predicate choice

    public function testPredicateUsesThePromotedColumnWhenTheSnapshotHasIt(): void
    {
        $this->assertSame(
            "`code` = 'AUTHORIZE_NET'",
            $this->support(self::PROMOTED)->keyPredicate('payment_method', 'AUTHORIZE_NET'),
        );
    }

    /**
     * The failure this class exists to prevent: on a pre-promotion snapshot a `code = 'X'` predicate
     * dies on an unknown column, and a JSON predicate on a promoted one matches nothing SILENTLY.
     */
    public function testPredicateFallsBackToTheJsonKeyBeforePromotion(): void
    {
        $this->assertSame(
            "JSON_UNQUOTE(JSON_EXTRACT(`attributes_global`, '$.code')) = 'AUTHORIZE_NET'",
            $this->support(self::PRE_PROMOTION)->keyPredicate('payment_method', 'AUTHORIZE_NET'),
        );
    }

    public function testPredicateIsNullAndLoggedWhenTheTableIsAbsent(): void
    {
        $logger  = $this->collectingLogger();
        $support = $this->support([], [], $logger);

        $this->assertNull($support->keyPredicate('payment_method', 'AUTHORIZE_NET'));
        $this->assertStringContainsString(
            'table `payment_method` is not in the snapshot',
            implode("\n", $logger->lines),
        );
    }

    public function testPredicateIsNullAndLoggedWhenNoNaturalKeyIsRegistered(): void
    {
        $logger  = $this->collectingLogger();
        $support = $this->support(self::PROMOTED, [], $logger, naturalKeys: []);

        $this->assertNull($support->keyPredicate('payment_method', 'AUTHORIZE_NET'));
        $this->assertStringContainsString('no natural key registered', implode("\n", $logger->lines));
    }

    /** A null predicate must always mean "skip", never a WHERE that matches every row. */
    public function testPredicateIsNullWhenNeitherTheColumnNorTheJsonBagExists(): void
    {
        $logger  = $this->collectingLogger();
        $support = $this->support(['payment_method' => ['id', 'label']], [], $logger);

        $this->assertNull($support->keyPredicate('payment_method', 'AUTHORIZE_NET'));
        $this->assertStringContainsString(
            'has neither a `code` column nor `attributes_global`',
            implode("\n", $logger->lines),
        );
    }

    public function testHasTableAndHasColumnReadTheLiveSchema(): void
    {
        $support = $this->support(self::PROMOTED);

        $this->assertTrue($support->hasTable('payment_method'));
        $this->assertFalse($support->hasTable('no_such_table'));
        $this->assertTrue($support->hasColumn('payment_method', 'code'));
        $this->assertFalse($support->hasColumn('payment_method', 'no_such_column'));
        $this->assertFalse($support->hasColumn('no_such_table', 'code'));
    }

    /** The schema is one query, cached — not one per predicate. */
    public function testTheSchemaIsReadOnlyOnce(): void
    {
        $adapter = $this->createMock(DatabaseAdapterInterface::class);
        $adapter->expects($this->once())
            ->method('fetchAll')
            ->willReturn([['TABLE_NAME' => 'payment_method', 'COLUMN_NAME' => 'code']]);

        $support = new PostImportSupport($adapter, 'snapshot_db', $this->applicationConfig(), $this->collectingLogger());

        $support->hasTable('payment_method');
        $support->hasTable('payment_method');
        $support->hasColumn('payment_method', 'code');
    }

    /** The schema name is BOUND, not concatenated into the query. */
    public function testTheSchemaQueryBindsTheDatabaseName(): void
    {
        $adapter = $this->createMock(DatabaseAdapterInterface::class);
        $adapter->expects($this->once())
            ->method('fetchAll')
            ->with(
                $this->stringContains(':schema'),
                'snapshot_db',
                [':schema' => 'snapshot_db'],
            )
            ->willReturn([]);

        (new PostImportSupport($adapter, 'snapshot_db', $this->applicationConfig(), $this->collectingLogger()))
            ->hasTable('anything');
    }

    // ------------------------------------------------------------------ mutations

    public function testSetGlobalAttributesEmitsJsonSetAgainstTheChosenPredicate(): void
    {
        $support = $this->support(self::PROMOTED, ['SELECT 1 FROM' => [['1' => 1]]]);

        $support->setGlobalAttributes('payment_method', 'AUTHORIZE_NET', ['login_id' => 'QA_LOGIN']);

        $this->assertSame(
            "UPDATE `payment_method` SET `attributes_global` = "
            . "JSON_SET(`attributes_global`, '$.login_id', 'QA_LOGIN') WHERE `code` = 'AUTHORIZE_NET';",
            $support->toSql(),
        );
    }

    /**
     * A null REMOVES the attribute rather than writing a JSON null — a read path treating an absent
     * value as "fall back to the default" would be defeated by an explicit null.
     */
    public function testNullAttributeValueRemovesTheKeyInsteadOfWritingJsonNull(): void
    {
        $support = $this->support(self::PROMOTED, ['SELECT 1 FROM' => [['1' => 1]]]);

        $support->setGlobalAttributes('payment_method', 'AUTHORIZE_NET', ['is_enabled' => null]);

        $sql = $support->toSql();
        $this->assertStringContainsString("JSON_REMOVE(`attributes_global`, '$.is_enabled')", $sql);
        $this->assertStringNotContainsString('null', $sql);
    }

    /** Removes run before sets, so an attribute given in both ends up set. */
    public function testRemovesAreNestedInsideSetsSoAnAttributeInBothEndsUpSet(): void
    {
        $support = $this->support(self::PROMOTED, ['SELECT 1 FROM' => [['1' => 1]]]);

        $support->setGlobalAttributes('payment_method', 'AUTHORIZE_NET', [
            'gone' => null,
            'kept' => 'value',
        ]);

        $this->assertStringContainsString(
            "JSON_SET(JSON_REMOVE(`attributes_global`, '$.gone'), '$.kept', 'value')",
            $support->toSql(),
        );
    }

    /** Never a silent 0-row UPDATE: an absent row logs why and emits nothing. */
    public function testAnAbsentRowLogsAndEmitsNoSql(): void
    {
        $logger  = $this->collectingLogger();
        $support = $this->support(self::PROMOTED, [], $logger);

        $support->setGlobalAttributes('payment_method', 'NO_SUCH_METHOD', ['login_id' => 'x']);

        $this->assertSame('', $support->toSql());
        $this->assertStringContainsString(
            'has no row for "NO_SUCH_METHOD" in the snapshot',
            implode("\n", $logger->lines),
        );
    }

    /**
     * Scoped reads resolve view -> website -> global, so a stale scoped copy shadows a corrected
     * global value — the AUTHORIZE_NET E00007 incident. The scope CODES come from the data, and
     * siblings must survive: `attributes_view = NULL` would drop per-view `is_enabled` too.
     */
    public function testClearScopedAttributeDiscoversScopeCodesFromTheRow(): void
    {
        $support = $this->support(self::PROMOTED, [
            'SELECT 1 FROM'              => [['1' => 1]],
            'SELECT `attributes_view`'    => [['attributes_view' => '{"default":{"login_id":"STALE","title":"Card"}}']],
            'SELECT `attributes_website`' => [['attributes_website' => '{"base":{"login_id":"STALE"}}']],
        ]);

        $support->clearScopedAttribute('payment_method', 'AUTHORIZE_NET', 'login_id');

        $sql = $support->toSql();
        $this->assertStringContainsString(
            'UPDATE `payment_method` SET `attributes_view` = '
            . 'JSON_REMOVE(`attributes_view`, \'$."default".login_id\')',
            $sql,
        );
        $this->assertStringContainsString(
            'UPDATE `payment_method` SET `attributes_website` = '
            . 'JSON_REMOVE(`attributes_website`, \'$."base".login_id\')',
            $sql,
        );
        // Only the named attribute — the sibling title is never mentioned.
        $this->assertStringNotContainsString('title', $sql);
    }

    public function testClearScopedAttributeEmitsNothingWhenNoScopeHoldsTheAttribute(): void
    {
        $support = $this->support(self::PROMOTED, [
            'SELECT 1 FROM'           => [['1' => 1]],
            'SELECT `attributes_view`' => [['attributes_view' => '{"default":{"title":"Card"}}']],
        ]);

        $support->clearScopedAttribute('payment_method', 'AUTHORIZE_NET', 'login_id');

        $this->assertSame('', $support->toSql());
    }

    public function testSetJsonKeysTouchesOnlyTheGivenPaths(): void
    {
        $support = $this->support(
            ['connector' => ['id', 'code', 'settings']],
            ['SELECT 1 FROM' => [['1' => 1]]],
            naturalKeys: ['connector' => 'code'],
        );

        $support->setJsonKeys('connector', 'magento', 'settings', [
            'connection.rest.endpoint' => 'https://qa.example/rest',
        ]);

        $this->assertSame(
            "UPDATE `connector` SET `settings` = JSON_SET(`settings`, "
            . "'$.connection.rest.endpoint', 'https://qa.example/rest') WHERE `code` = 'magento';",
            $support->toSql(),
        );
    }

    public function testSetJsonKeysSkipsAndLogsWhenTheColumnIsAbsent(): void
    {
        $logger  = $this->collectingLogger();
        $support = $this->support(
            ['connector' => ['id', 'code']],
            ['SELECT 1 FROM' => [['1' => 1]]],
            $logger,
            naturalKeys: ['connector' => 'code'],
        );

        $support->setJsonKeys('connector', 'magento', 'settings', ['endpoint' => 'x']);

        $this->assertSame('', $support->toSql());
        $this->assertStringContainsString('has no `settings` column', implode("\n", $logger->lines));
    }

    /** Values reach SQL through the adapter's quote(), not a hand-rolled escape. */
    public function testValuesAreQuotedThroughTheAdapter(): void
    {
        $support = $this->support(self::PROMOTED, ['SELECT 1 FROM' => [['1' => 1]]]);

        $support->setGlobalAttributes('payment_method', "O'Brien", ['login_id' => "it's"]);

        $sql = $support->toSql();
        $this->assertStringContainsString("'it\\'s'", $sql);
        $this->assertStringContainsString("`code` = 'O\\'Brien'", $sql);
    }
}
