<?php

namespace ConductorAppOrchestrationTest\Config;

use ConductorAppOrchestration\Config\ApplicationConfig;
use ConductorAppOrchestration\FileLayoutInterface;
use ConductorAppOrchestrationTest\BuildsApplicationConfigTrait;
use ConductorCore\Exception\InvalidConfigException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

/**
 * CTAP-1630. ApplicationConfig as a schema-validated, readonly object.
 *
 * Two of these cover live bugs the conversion removed rather than new behavior: file modes were
 * declared `string` while `mkdir()` needs an int, and the deprecation warning in `getDatabases()`
 * dereferenced a logger the factory never set.
 */
class ApplicationConfigTest extends TestCase
{
    use BuildsApplicationConfigTrait;

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

    // ------------------------------------------------------------------ validation

    /** Every missing key at once, so a config is fixed in one pass rather than one per deploy. */
    public function testEveryMissingRequiredKeyIsReportedTogether(): void
    {
        try {
            new ApplicationConfig(['app_name' => 'Test App']);
            $this->fail('Expected InvalidConfigException');
        } catch (InvalidConfigException $exception) {
            $message = $exception->getMessage();
            $this->assertStringContainsString('3 problems', $message);
            $this->assertStringContainsString('app_root: is required', $message);
            $this->assertStringContainsString('repo_url: is required', $message);
            $this->assertStringContainsString('environment: is required', $message);
        }
    }

    public function testTheConfigPathIsNamedInTheError(): void
    {
        $this->expectExceptionMessageMatches('/application_orchestration\.application/');

        new ApplicationConfig([]);
    }

    /** The old validate() threw `Invalid file layout "x".` without saying what was valid. */
    public function testAnUnknownFileLayoutErrorListsTheValidOptions(): void
    {
        $this->expectExceptionMessageMatches("/must be one of: 'default', 'blue_green'/");

        $this->applicationConfig(['file_layout' => 'blugreen']);
    }

    // ------------------------------------------------------------------ file modes

    /**
     * `getDefaultDirMode()` declared `: string` while every caller passes it to `mkdir()`, which
     * takes an int. It worked only because the file has no `declare(strict_types=1)` and PHP coerced
     * it twice — out to a string and back to an int. It is typed `int` now.
     */
    #[DataProvider('fileModeConfigs')]
    public function testFileModesAreIntsWhicheverWayConfigSpellsThem(mixed $given, int $expected): void
    {
        $config = $this->applicationConfig(['default_dir_mode' => $given, 'default_file_mode' => $given]);

        $this->assertSame($expected, $config->getDefaultDirMode());
        $this->assertSame($expected, $config->getDefaultFileMode());
        $this->assertSame($expected, $config->defaultDirMode);
    }

    /** @return iterable<string, array{mixed, int}> */
    public static function fileModeConfigs(): iterable
    {
        yield 'octal int literal' => [0750, 488];
        yield 'octal string'      => ['0750', 488];
    }

    public function testFileModesFallBackToTheDocumentedDefaults(): void
    {
        $config = $this->applicationConfig();

        $this->assertSame(0750, $config->defaultDirMode);
        $this->assertSame(0640, $config->defaultFileMode);
    }

    // ------------------------------------------------------------------ transformations

    public function testTrailingSlashesAreTrimmedFromPaths(): void
    {
        $config = $this->applicationConfig([
            'app_root'               => '/app/apps/middleware/',
            'relative_document_root' => 'public/',
        ]);

        $this->assertSame('/app/apps/middleware', $config->appRoot);
        $this->assertSame('public', $config->relativeDocumentRoot);
    }

    public function testNumericStringsAreAcceptedWhereConfigCarriesThem(): void
    {
        // YAML and env vars produce strings; the schema coerces the ones that are unambiguous.
        $config = $this->applicationConfig(['ssh_defaults' => ['port' => '2222']]);

        $this->assertSame(['port' => '2222'], $config->sshDefaults);
    }

    // ------------------------------------------------------------------ nothing silently dropped

    /**
     * `environment_vars` is read by DatabaseReplacementScript and PostImportSupport but was never
     * declared in conductor's defaults — it survived only because the old `toArray()` returned the
     * raw config array. Reconstructing from typed properties would have dropped it.
     */
    public function testEnvironmentVarsSurviveAndAreExposed(): void
    {
        $config = $this->applicationConfig([
            'environment_vars' => ['FRONTEND_DOMAIN' => 'shop.qa.test'],
        ]);

        $this->assertSame(['FRONTEND_DOMAIN' => 'shop.qa.test'], $config->environmentVars);
        $this->assertSame(['FRONTEND_DOMAIN' => 'shop.qa.test'], $config->getEnvironmentVars());
        $this->assertSame(['FRONTEND_DOMAIN' => 'shop.qa.test'], $config->toArray()['environment_vars']);
    }

    /** A key a platform-support package contributes must not vanish from `app:config:show`. */
    public function testUndeclaredKeysArePreservedInToArray(): void
    {
        $config = $this->applicationConfig(['platforms' => ['magento2' => ['x' => 1]]]);

        $this->assertSame(['magento2' => ['x' => 1]], $config->toArray()['platforms']);
    }

    public function testToArrayRoundTripsIntoAValidConfig(): void
    {
        $original = $this->applicationConfig([
            'environment_vars' => ['A' => 'b'],
            'default_dir_mode' => '0700',
        ]);

        $rebuilt = new ApplicationConfig($original->toArray());

        $this->assertSame($original->toArray(), $rebuilt->toArray());
        $this->assertSame(0700, $rebuilt->defaultDirMode);
    }

    // ------------------------------------------------------------------ deprecated snapshot keys

    /**
     * The null dereference. This warning used to be emitted from inside `getDatabases()` against
     * `$this->logger`, which the factory never set and nothing guarded — so a snapshot config using
     * a deprecated key was a fatal error rather than a warning.
     */
    public function testDeprecatedSnapshotDatabaseKeysDoNotRequireALogger(): void
    {
        $config = $this->applicationConfig([
            'snapshot' => ['databases' => ['main' => ['local_database_name' => 'main_local']]],
        ]);

        $this->assertSame('main_local', $config->getDatabases()['main']['alias']);
    }

    public function testDeprecatedSnapshotDatabaseKeysAreWarnedAboutOnce(): void
    {
        $logger = $this->collectingLogger();

        $config = $this->applicationConfig([
            'snapshot' => ['databases' => ['main' => ['local_database_name' => 'main_local']]],
        ], $logger);

        // Read it repeatedly: the warning belongs to construction, not to every read.
        $config->getDatabases();
        $config->getDatabases();

        $this->assertCount(1, $logger->lines);
        $this->assertStringContainsString('"local_database_name" in snapshot configuration is deprecated', $logger->lines[0]);
    }

    public function testAnExplicitTopLevelDatabaseValueWinsOverTheDeprecatedSnapshotOne(): void
    {
        $config = $this->applicationConfig([
            'databases' => ['main' => ['alias' => 'chosen']],
            'snapshot'  => ['databases' => ['main' => ['local_database_name' => 'deprecated']]],
        ]);

        $this->assertSame('chosen', $config->getDatabases()['main']['alias']);
    }

    // ------------------------------------------------------------------ derived paths

    public function testDefaultLayoutPathsAllResolveToAppRoot(): void
    {
        $config = $this->applicationConfig(['app_root' => '/app']);

        $this->assertSame('/app', $config->getCodePath());
        $this->assertSame('/app', $config->getCurrentPath());
        $this->assertSame('/app', $config->getLocalPath());
        $this->assertSame('/app', $config->getSharedPath());
        $this->assertSame('/app', $config->getPreviousPath());
    }

    public function testBlueGreenLayoutPathsEachGetTheirOwnDirectory(): void
    {
        $config = $this->applicationConfig([
            'app_root'    => '/app',
            'file_layout' => FileLayoutInterface::STRATEGY_BLUE_GREEN,
        ]);

        $this->assertSame('/app/current', $config->getCodePath());
        $this->assertSame('/app/builds/42', $config->getCodePath('42'));
        $this->assertSame('/app/current', $config->getCurrentPath());
        $this->assertSame('/app/local', $config->getLocalPath());
        $this->assertSame('/app/shared', $config->getSharedPath());
        $this->assertSame('/app/previous', $config->getPreviousPath());
    }

    public function testAnExplicitSharedPathOverridesTheDerivedOne(): void
    {
        $config = $this->applicationConfig([
            'app_root'    => '/app',
            'shared_path' => '/mnt/shared',
            'file_layout' => FileLayoutInterface::STRATEGY_BLUE_GREEN,
        ]);

        $this->assertSame('/mnt/shared', $config->getSharedPath());
    }

    public function testDocumentRootAppendsTheRelativeRoot(): void
    {
        $config = $this->applicationConfig([
            'app_root'               => '/app',
            'relative_document_root' => 'public',
        ]);

        $this->assertSame('/app/public', $config->getDocumentRoot());
    }

    public function testAnUnknownPathTypeIsRejected(): void
    {
        $this->expectExceptionMessage('Invalid path type "nope" given.');

        $this->applicationConfig()->getPath('nope');
    }

    // ------------------------------------------------------------------ immutability

    public function testTheConfigIsReadonly(): void
    {
        $config = $this->applicationConfig();

        $this->expectException(\Error::class);

        /** @phpstan-ignore-next-line writing to a readonly property is the point of the test */
        $config->appName = 'changed';
    }
}
