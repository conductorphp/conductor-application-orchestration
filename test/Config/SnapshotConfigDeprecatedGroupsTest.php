<?php

namespace ConductorAppOrchestrationTest\Config;

use ConductorAppOrchestration\Config\SnapshotConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Stringable;

/**
 * CTAP-2161. A platform package can mark groups deprecated, with what to use instead. A plan that
 * names one gets a warning; the group still expands exactly as before.
 */
class SnapshotConfigDeprecatedGroupsTest extends TestCase
{
    /** @var AbstractLogger&object{records: list<array{0: string, 1: string}>} */
    private $logger;

    public function setUp(): void
    {
        $this->logger = new class extends AbstractLogger {
            /** @var list<array{0: string, 1: string}> */
            public array $records = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->records[] = [$level, (string) $message];
            }
        };
    }

    public function testADeprecatedAssetGroupStillExpandsAndWarnsOnce(): void
    {
        $config = $this->config();

        $this->assertSame(['/css', '/tmp'], $config->expandAssetGroups(['@core']));
        $this->assertSame(['/css', '/tmp'], $config->expandAssetGroups(['@core']));

        $this->assertSame(
            [[LogLevel::WARNING, 'The asset group "@core" is deprecated. Use @generated and @scratch.']],
            $this->logger->records
        );
    }

    public function testADeprecatedTableGroupWarnsWithItsOwnNoun(): void
    {
        $this->config()->expandDatabaseTableGroups(['@customers', 'my_table']);

        $this->assertSame(
            [[LogLevel::WARNING, 'The database table group "@customers" is deprecated. Use @personal_data.']],
            $this->logger->records
        );
    }

    /** @core is deprecated and contains deprecated groups; only what the plan names is reported. */
    public function testOnlyGroupsThePlanNamesAreReported(): void
    {
        $this->config()->expandDatabaseTableGroups(['@core']);

        $this->assertSame(
            [[LogLevel::WARNING, 'The database table group "@core" is deprecated. Use the nature groups.']],
            $this->logger->records
        );
    }

    public function testCurrentGroupsDoNotWarn(): void
    {
        $this->config()->expandAssetGroups(['@generated', '@scratch']);

        $this->assertSame([], $this->logger->records);
    }

    /** Groups overlap, so the expansion of several can repeat a name; it is listed once. */
    public function testOverlappingGroupsExpandWithoutDuplicates(): void
    {
        $this->assertSame(
            ['admin_user', 'customer_entity', 'oauth_token'],
            $this->config()->expandDatabaseTableGroups(['@personal_data', '@environment'])
        );
    }

    public function testWithoutALoggerNothingIsReported(): void
    {
        $config = new SnapshotConfig($this->snapshot());

        $this->assertSame(['/css', '/tmp'], $config->expandAssetGroups(['@core']));
    }

    private function config(): SnapshotConfig
    {
        return new SnapshotConfig($this->snapshot(), $this->logger);
    }

    private function snapshot(): array
    {
        return [
            'asset_groups' => [
                'core' => ['@generated', '@scratch'],
                'generated' => ['/css'],
                'scratch' => ['/tmp'],
            ],
            'database_table_groups' => [
                'core' => ['@customers'],
                'customers' => ['customer_entity'],
                'personal_data' => ['customer_entity', 'admin_user'],
                'environment' => ['oauth_token', 'admin_user'],
            ],
            'deprecated_asset_groups' => [
                'core' => 'Use @generated and @scratch.',
            ],
            'deprecated_database_table_groups' => [
                'core' => 'Use the nature groups.',
                'customers' => 'Use @personal_data.',
            ],
        ];
    }
}
