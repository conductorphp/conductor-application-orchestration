<?php

namespace ConductorAppOrchestrationTest\Deploy;

use ConductorAppOrchestration\Deploy\DatabaseReplacementScript;
use ConductorAppOrchestrationTest\BuildsApplicationConfigTrait;
use ConductorCore\Database\DatabaseAdapterInterface;
use ConductorCore\Exception\InvalidConfigException;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

use function addslashes;
use function array_fill_keys;
use function in_array;
use function str_contains;

/**
 * CTAP-1629. Covers the column check that this class advertised but did not perform.
 *
 * Its docblock has always claimed "Validates tables and columns exist before generating SQL", while
 * columnExists() returned true unconditionally with a comment explaining that the adapter interface
 * offered no way to look. So a replacement naming a dropped column produced an UPDATE that failed at
 * run time, in the middle of a deploy, rather than being skipped with a reason — the opposite of what
 * the surrounding table check does. Now that the adapter can return rows, the claim is true.
 */
class DatabaseReplacementScriptTest extends TestCase
{
    use BuildsApplicationConfigTrait;

    private const DATABASE = 'snapshot_db';

    /** @param list<string> $existingColumns `table.column` entries the snapshot has */
    private function adapter(array $existingTables, array $existingColumns): DatabaseAdapterInterface
    {
        $adapter = $this->createStub(DatabaseAdapterInterface::class);

        $adapter->method('getTableMetadata')->willReturn(
            array_fill_keys($existingTables, ['rows' => 1, 'size' => 1])
        );

        $adapter->method('fetchAll')->willReturnCallback(
            static function (string $sql, string $database, ?array $parameters = null) use ($existingColumns): array {
                if (! str_contains($sql, 'information_schema.COLUMNS')) {
                    return [];
                }

                $target = ($parameters[':table'] ?? '') . '.' . ($parameters[':column'] ?? '');

                return in_array($target, $existingColumns, true) ? [['1' => 1]] : [];
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
     * Config overrides for one `base_url` replacement against the given targets.
     *
     * @param list<string> $targets
     * @return array<string, mixed>
     */
    private function config(array $targets): array
    {
        return [
            'environment' => 'qa',
            'deploy'      => [
                'databases' => [
                    self::DATABASE => [
                        'replacements' => [
                            'base_url' => [
                                'from'    => 'https://prod.example',
                                'to'      => 'https://qa.example',
                                'targets' => $targets,
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /** @param array<string, mixed> $config */
    private function runScript(DatabaseAdapterInterface $adapter, array $config, LoggerInterface $logger): string
    {
        return (new DatabaseReplacementScript())
            ->execute($adapter, self::DATABASE, $this->applicationConfig($config), $logger);
    }

    public function testAReplacementAgainstAnExistingColumnIsGenerated(): void
    {
        $sql = $this->runScript(
            $this->adapter(['cms_page'], ['cms_page.content']),
            $this->config(['cms_page.content']),
            $this->collectingLogger(),
        );

        $this->assertStringContainsString('UPDATE `cms_page` SET `content` = REPLACE(', $sql);
        $this->assertStringNotContainsString('SKIPPED', $sql);
    }

    /**
     * The behavior change. Before CTAP-1629 this generated an UPDATE naming a column that is not
     * there, which the adapter then failed on mid-deploy.
     */
    public function testAReplacementAgainstAMissingColumnIsSkippedNotGenerated(): void
    {
        $logger = $this->collectingLogger();

        $sql = $this->runScript(
            $this->adapter(['cms_page'], []),
            $this->config(['cms_page.dropped_column']),
            $logger,
        );

        $this->assertStringContainsString('-- SKIPPED: Column does not exist', $sql);
        $this->assertStringNotContainsString('UPDATE `cms_page`', $sql);
    }

    /** The table check still short-circuits before the column check. */
    public function testAReplacementAgainstAMissingTableIsSkipped(): void
    {
        $sql = $this->runScript(
            $this->adapter([], []),
            $this->config(['no_such_table.content']),
            $this->collectingLogger(),
        );

        $this->assertStringContainsString('-- SKIPPED: Table does not exist', $sql);
        $this->assertStringNotContainsString('UPDATE `no_such_table`', $sql);
    }

    /** One good target and one bad one: the good one still lands. */
    public function testAMissingColumnDoesNotSuppressTheOtherTargets(): void
    {
        $sql = $this->runScript(
            $this->adapter(['cms_page', 'cms_block'], ['cms_page.content']),
            $this->config(['cms_page.content', 'cms_block.gone']),
            $this->collectingLogger(),
        );

        $this->assertStringContainsString('UPDATE `cms_page` SET `content` = REPLACE(', $sql);
        $this->assertStringContainsString('-- SKIPPED: Column does not exist', $sql);
        $this->assertStringNotContainsString('UPDATE `cms_block`', $sql);
    }

    /** Values go through the adapter's quote(), not `"'" . addslashes($v) . "'"`. */
    public function testValuesAreQuotedThroughTheAdapter(): void
    {
        $config = $this->config(['cms_page.content']);
        $config['deploy']['databases'][self::DATABASE]['replacements']['base_url']['to'] = "it's";

        $sql = $this->runScript(
            $this->adapter(['cms_page'], ['cms_page.content']),
            $config,
            $this->collectingLogger(),
        );

        $this->assertStringContainsString("it\\'s", $sql);
    }
}
