<?php

namespace ConductorAppOrchestrationTest\Deploy;

use ConductorAppOrchestration\Deploy\PostImportScript;
use ConductorAppOrchestration\Deploy\PostImportSupport;
use ConductorAppOrchestrationTest\BuildsApplicationConfigTrait;
use ConductorCore\Exception\InvalidConfigException;
use ConductorCore\Database\DatabaseAdapterInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

/**
 * CTAP-1628. Covers the surface of PostImportSupport and PostImportScript that needs no database.
 *
 * PostImportSupport opens its companion PDO connection LAZILY — nothing but a schema read or a
 * mutation touches it — so statement collection, config accessors and the PostImportScript wiring
 * are all testable here. The schema-dependent half (keyPredicate() picking a promoted column
 * over an `attributes_global` JSON key, per-scope clearing discovering its scope codes, the
 * log-and-skip paths) needs a real snapshot to mean anything: a mocked information_schema would
 * assert only that the mock was configured. That half is exercised by
 * `test/manual/verify-post-import-support.php` against live MySQL and MariaDB.
 */
class PostImportScriptTest extends TestCase
{
    use BuildsApplicationConfigTrait;

    /** @param array<string, mixed> $config Overrides merged into a valid application config. */
    private function support(array $config = [], ?LoggerInterface $logger = null): PostImportSupport
    {
        return new PostImportSupport(
            $this->createStub(DatabaseAdapterInterface::class),
            'some_database',
            $this->applicationConfig($config),
            $logger ?? $this->collectingLogger(),
        );
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

    public function testNoStatementsProducesAnEmptyStringRatherThanAStraySemicolon(): void
    {
        // The deployer treats '' as a clean skip. A lone ';' would reach the adapter as SQL.
        $this->assertSame('', $this->support()->toSql());
    }

    public function testStatementsAreJoinedAndTerminated(): void
    {
        $support = $this->support();
        $support->addStatement('UPDATE a SET b = 1 WHERE c = 2');
        $support->addStatement('UPDATE d SET e = 3 WHERE f = 4');

        $this->assertSame(
            "UPDATE a SET b = 1 WHERE c = 2;\nUPDATE d SET e = 3 WHERE f = 4;",
            $support->toSql(),
        );
    }

    /**
     * A caller writing SQL by hand naturally ends it with a semicolon. toSql() appends its own, and
     * `run()` splits on ';' — so an unnormalized trailing semicolon yields an empty final statement.
     */
    #[DataProvider('untidyStatements')]
    public function testTrailingSemicolonsAndWhitespaceAreNormalized(string $given): void
    {
        $support = $this->support();
        $support->addStatement($given);

        $this->assertSame('UPDATE a SET b = 1;', $support->toSql());
    }

    /** @return iterable<string, array{string}> */
    public static function untidyStatements(): iterable
    {
        yield 'bare'                  => ['UPDATE a SET b = 1'];
        yield 'trailing semicolon'    => ['UPDATE a SET b = 1;'];
        yield 'trailing whitespace'   => ["UPDATE a SET b = 1  \n"];
        yield 'whitespace then colon' => ["  UPDATE a SET b = 1 ;"];
        yield 'repeated semicolons'   => ['UPDATE a SET b = 1;;;'];
        yield 'semicolons and spaces' => ["UPDATE a SET b = 1; ;\n"];
    }

    public function testEmittedSqlCarriesNoComments(): void
    {
        // DatabaseAdapterInterface::run() strips '--' and slash-star comments before splitting on
        // ';', so anything explanatory in the SQL is discarded — it belongs in the calling script.
        $support = $this->support();
        $support->addStatement('UPDATE a SET b = 1');

        $sql = $support->toSql();
        $this->assertStringNotContainsString('--', $sql);
        $this->assertStringNotContainsString('/*', $sql);
    }

    /**
     * The environment now comes from a validated property, so there is no `'unknown'` sentinel.
     *
     * The old reader looked up `$config['current_environment']` and fell back to `'unknown'` when it
     * was missing, non-string or empty. Nothing in conductor ever WROTE that key — the config carries
     * `environment` — so the fallback fired every time and every script saw `'unknown'` (CTAP-1630).
     */
    public function testEnvironmentIsTheConfiguredEnvironment(): void
    {
        $this->assertSame('uat', $this->support(['environment' => 'uat'])->environment());
    }

    /** The cases the old fallback existed for are now impossible: config validation rejects them. */
    #[DataProvider('invalidEnvironments')]
    public function testAnUnusableEnvironmentIsRejectedAtConstructionInsteadOfDefaulted(mixed $environment): void
    {
        $this->expectException(InvalidConfigException::class);

        $this->applicationConfig(['environment' => $environment]);
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidEnvironments(): iterable
    {
        yield 'null'       => [null];
        yield 'empty'      => [''];
        yield 'not string' => [['qa']];
    }

    public function testEnvironmentVarsAreTheConfiguredVars(): void
    {
        $this->assertSame(
            ['BASE_URL' => 'https://qa.test'],
            $this->support(['environment_vars' => ['BASE_URL' => 'https://qa.test']])->environmentVars(),
        );
    }

    public function testEnvironmentVarsDefaultToAnEmptyArray(): void
    {
        $this->assertSame([], $this->support()->environmentVars());
    }

    /** A non-array `environment_vars` is a config error now, not something to shrug off at read time. */
    public function testNonArrayEnvironmentVarsIsRejectedAtConstruction(): void
    {
        $this->expectException(InvalidConfigException::class);

        $this->applicationConfig(['environment_vars' => 'BASE_URL=x']);
    }

    public function testSkipIsLoggedAsANoticeWithTheReason(): void
    {
        $logger  = $this->collectingLogger();
        $support = $this->support([], $logger);

        $support->skip('table `foo` is not in the snapshot');

        $this->assertSame(
            ['NOTICE: Post-import: skipping — table `foo` is not in the snapshot.'],
            $logger->lines,
        );
    }

    public function testDatabaseNameAndAdapterAreExposedForTheEscapeHatch(): void
    {
        $adapter = $this->createStub(DatabaseAdapterInterface::class);
        $logger  = $this->collectingLogger();
        $support = new PostImportSupport($adapter, 'snapshot_db', $this->applicationConfig(), $logger);

        $this->assertSame('snapshot_db', $support->databaseName());
        $this->assertSame($adapter, $support->databaseAdapter());
        $this->assertSame($logger, $support->logger());
    }

    public function testScriptReturnsTheCollectedSql(): void
    {
        $script = new PostImportScript(
            ['system_setting' => 'path'],
            static function (PostImportSupport $support): void {
                $support->addStatement('UPDATE system_setting SET x = 1');
            },
        );

        $this->assertSame(
            'UPDATE system_setting SET x = 1;',
            $script->execute(
                $this->createStub(DatabaseAdapterInterface::class),
                'some_database',
                $this->applicationConfig(),
                $this->collectingLogger(),
            ),
        );
    }

    public function testScriptThatAppliesNothingReturnsAnEmptyString(): void
    {
        $script = new PostImportScript([], static function (PostImportSupport $support): void {
        });

        $this->assertSame(
            '',
            $script->execute(
                $this->createStub(DatabaseAdapterInterface::class),
                'some_database',
                $this->applicationConfig(),
                $this->collectingLogger(),
            ),
        );
    }

    /**
     * `$apply` is typed `callable`, not `Closure`, so a script needing private helpers can pass an
     * invokable object and keep them together — the composition alternative to a base class. If the
     * constructor ever narrows to Closure this breaks, which is the point of asserting it.
     */
    public function testApplyAcceptsAnInvokableObjectNotJustAClosure(): void
    {
        $action = new class {
            public function __invoke(PostImportSupport $support): void
            {
                $this->addBoth($support);
            }

            private function addBoth(PostImportSupport $support): void
            {
                $support->addStatement('UPDATE a SET b = 1');
                $support->addStatement('UPDATE c SET d = 2');
            }
        };

        $script = new PostImportScript(['a' => 'code'], $action);

        $this->assertSame(
            "UPDATE a SET b = 1;\nUPDATE c SET d = 2;",
            $script->execute(
                $this->createStub(DatabaseAdapterInterface::class),
                'some_database',
                $this->applicationConfig(),
                $this->collectingLogger(),
            ),
        );
    }

    /** A first-class callable from a plain method is a callable too, and must work the same way. */
    public function testApplyAcceptsAFirstClassCallableFromAMethod(): void
    {
        $script = new PostImportScript([], $this->addOneStatement(...));

        $this->assertSame(
            'UPDATE from_method SET x = 1;',
            $script->execute(
                $this->createStub(DatabaseAdapterInterface::class),
                'some_database',
                $this->applicationConfig(),
                $this->collectingLogger(),
            ),
        );
    }

    private function addOneStatement(PostImportSupport $support): void
    {
        $support->addStatement('UPDATE from_method SET x = 1');
    }

    /**
     * A script gets the environment through the support object, so the config the deployer passes to
     * execute() has to reach it — otherwise every script would read 'unknown'.
     */
    public function testConfigPassedToExecuteReachesTheSupportObject(): void
    {
        $script = new PostImportScript([], static function (PostImportSupport $support): void {
            $support->addStatement("SELECT '{$support->environment()}', '{$support->databaseName()}'");
        });

        $this->assertSame(
            "SELECT 'qa', 'snapshot_db';",
            $script->execute(
                $this->createStub(DatabaseAdapterInterface::class),
                'snapshot_db',
                $this->applicationConfig(['environment' => 'qa']),
                $this->collectingLogger(),
            ),
        );
    }
}
