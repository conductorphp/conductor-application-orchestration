<?php

declare(strict_types=1);

namespace ConductorAppOrchestrationTest\Deploy;

use ConductorAppOrchestration\Deploy\ApplicationDatabaseDeployer;
use ConductorAppOrchestration\Deploy\Command\DeployDatabasesCommand;
use ConductorAppOrchestrationTest\BuildsApplicationConfigTrait;
use ConductorCore\Database\DatabaseAdapterInterface;
use ConductorCore\Database\DatabaseAdapterManager;
use ConductorCore\Database\DatabaseImportExportAdapterInterface;
use ConductorCore\Database\DatabaseImportExportAdapterManager;
use ConductorCore\Filesystem\MountManager\MountManager;
use ConductorCore\Shell\Adapter\LocalShellAdapter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Stringable;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\ConsoleOutput;

/**
 * A snapshot restore drops the existing database. It is exported first, so a wrong snapshot or
 * environment does not lose it (CTAP-2579).
 */
final class ApplicationDatabaseDeployerPreRestoreDumpTest extends TestCase
{
    use BuildsApplicationConfigTrait;

    private string $dir;
    /** @var list<string> */
    public array $calls = [];
    /** @var list<array{string, string}> */
    private array $logs = [];
    private bool $exportFails = false;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/pre-restore-dump-test-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        $this->calls = $this->logs = [];
        $this->exportFails = false;
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    #[Test]
    public function existingDatabaseIsDumpedBeforeItIsDropped(): void
    {
        $this->deployer(databaseExists: true)
            ->deployDatabases('local:///snapshots', 'snap', ['app' => []], true, "$this->dir/dumps");

        self::assertSame(['export', 'drop', 'create', 'import'], $this->calls);

        $dumps = glob("$this->dir/dumps/app-*/app.tgz");
        self::assertCount(1, $dumps, 'one dump, in a directory named for the database and time');
        $notice = $this->logged('notice');
        self::assertStringContainsString($dumps[0], $notice, 'the log names the dump');
        self::assertStringContainsString(
            "conductor database:import --adapter=default '{$dumps[0]}' 'app'",
            $notice,
            'the log says how to put it back'
        );
    }

    #[Test]
    public function noDumpDirectoryDropsWithoutDumping(): void
    {
        $this->deployer(databaseExists: true)
            ->deployDatabases('local:///snapshots', 'snap', ['app' => []], true);

        self::assertSame(['drop', 'create', 'import'], $this->calls);
    }

    #[Test]
    public function newDatabaseHasNothingToDump(): void
    {
        $this->deployer(databaseExists: false)
            ->deployDatabases('local:///snapshots', 'snap', ['app' => []], true, "$this->dir/dumps");

        self::assertSame(['create', 'import'], $this->calls);
        self::assertDirectoryDoesNotExist("$this->dir/dumps");
    }

    #[Test]
    public function failedDumpLeavesTheDatabaseInPlace(): void
    {
        $this->exportFails = true;

        try {
            $this->deployer(databaseExists: true)
                ->deployDatabases('local:///snapshots', 'snap', ['app' => []], true, "$this->dir/dumps");
            self::fail('A failed dump must stop the restore.');
        } catch (RuntimeException $e) {
            self::assertSame('export failed', $e->getMessage());
        }

        self::assertSame(['export'], $this->calls, 'nothing dropped');
    }

    #[Test]
    public function aliasIsWhatIsDumped(): void
    {
        $this->deployer(databaseExists: true)
            ->deployDatabases('local:///snapshots', 'snap', ['app' => ['alias' => 'app_qa']], true, "$this->dir/dumps");

        self::assertCount(1, glob("$this->dir/dumps/app_qa-*/app_qa.tgz"));
    }

    #[Test]
    public function stepDumpsByDefaultToTheDefaultDirectory(): void
    {
        self::assertSame(DeployDatabasesCommand::DEFAULT_PRE_RESTORE_DUMP_DIR, $this->stepDumpDir([], false));
    }

    #[Test]
    public function stepUsesTheConfiguredDirectory(): void
    {
        self::assertSame('/data/dumps', $this->stepDumpDir(['pre_restore_dump_dir' => '/data/dumps'], false));
    }

    #[Test]
    public function stepOptionTurnsTheDumpOff(): void
    {
        self::assertNull($this->stepDumpDir(['pre_restore_dump' => false], false));
    }

    #[Test]
    public function allowFullRollbackTurnsItBackOn(): void
    {
        self::assertSame(
            DeployDatabasesCommand::DEFAULT_PRE_RESTORE_DUMP_DIR,
            $this->stepDumpDir(['pre_restore_dump' => false], true)
        );
    }

    /**
     * What DeployDatabasesCommand hands the deployer as the dump directory.
     */
    private function stepDumpDir(array $options, bool $allowFullRollback): ?string
    {
        $deployer = $this->createMock(ApplicationDatabaseDeployer::class);
        $captured = 'not called';
        $deployer->expects(self::once())
            ->method('deployDatabases')
            ->willReturnCallback(function (...$args) use (&$captured): void {
                $captured = $args[4];
            });

        $command = new DeployDatabasesCommand();
        $command->setApplicationDatabaseDeployer($deployer);
        $command->setApplicationConfig($this->applicationConfig(['databases' => ['app' => []]]));
        $command->run('/app', snapshotName: 'snap', snapshotPath: 'local:///snapshots',
            allowFullRollback: $allowFullRollback, options: $options);

        return $captured;
    }

    private function deployer(bool $databaseExists): ApplicationDatabaseDeployer
    {
        $database = $this->createStub(DatabaseAdapterInterface::class);
        $database->method('databaseExists')->willReturn($databaseExists);
        $database->method('dropDatabase')->willReturnCallback(function (): void {
            $this->calls[] = 'drop';
        });
        $database->method('createDatabase')->willReturnCallback(function (): void {
            $this->calls[] = 'create';
        });
        $databaseManager = $this->createStub(DatabaseAdapterManager::class);
        $databaseManager->method('getAdapter')->willReturn($database);

        $importExport = new class ($this) implements DatabaseImportExportAdapterInterface {
            public function __construct(private readonly ApplicationDatabaseDeployerPreRestoreDumpTest $test)
            {
            }

            public function exportToFile(string $database, string $path, array $options = []): string
            {
                return $this->test->export($database, $path);
            }

            public function importFromFile(string $filename, string $database, array $options = []): void
            {
                $this->test->calls[] = 'import';
            }

            public static function getFileExtension(): string
            {
                return 'tgz';
            }

            public function setLogger(LoggerInterface $logger): void
            {
            }

            public function assertIsUsable(): void
            {
            }
        };
        $importExportManager = $this->createStub(DatabaseImportExportAdapterManager::class);
        $importExportManager->method('getAdapter')->willReturn($importExport);

        $deployer = new ApplicationDatabaseDeployer(
            $this->applicationConfig(),
            $this->createStub(MountManager::class),
            $databaseManager,
            $importExportManager,
            $this->createStub(LocalShellAdapter::class),
            new ArgvInput([]),
            new ConsoleOutput(),
            new QuestionHelper()
        );
        $deployer->setLogger(new class ($this->logs) extends AbstractLogger {
            public function __construct(private array &$logs)
            {
            }

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->logs[] = [(string) $level, (string) $message];
            }
        });

        return $deployer;
    }

    public function export(string $database, string $path): string
    {
        $this->calls[] = 'export';
        if ($this->exportFails) {
            throw new RuntimeException('export failed');
        }
        file_put_contents("$path/$database.tgz", 'dump');
        return "$path/$database.tgz";
    }

    private function logged(string $level): string
    {
        return implode("\n", array_map(
            static fn (array $log): string => $log[1],
            array_filter($this->logs, static fn (array $log): bool => $log[0] === $level)
        ));
    }
}
