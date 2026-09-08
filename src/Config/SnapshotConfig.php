<?php

declare(strict_types=1);

namespace ConductorAppOrchestration\Config;

use ConductorAppOrchestration\Exception;
use ConductorCore\Config\ParsesConfigTrait;
use ConductorCore\Config\Schema\SchemaBuilder;
use ConductorCore\Config\Schema\SchemaInterface;

use function array_keys;
use function array_merge;
use function implode;
use function sort;
use function stripos;
use function str_starts_with;
use function substr;

/**
 * The `snapshot` section: plans, and the asset/database groups a plan can reference by `@name`.
 */
final readonly class SnapshotConfig
{
    use ParsesConfigTrait;

    public const CONFIG_KEY = 'application_orchestration.application.snapshot';

    /** @var array<string, mixed> */
    public array $plans;

    public string $defaultPlan;

    /** @var array<string, mixed> */
    public array $assets;

    /** @var array<string, mixed> */
    public array $databases;

    /** @var array<string, list<string>> */
    public array $assetGroups;

    /** @var array<string, list<string>> */
    public array $databaseTableGroups;

    /** @param array<string, mixed>|null $config */
    public function __construct(?array $config)
    {
        $parsed = $this->parseConfig($config, $this->schema(), self::CONFIG_KEY);

        $this->plans               = $parsed['plans'] ?? [];
        $this->defaultPlan         = $parsed['default_plan'] ?? 'default';
        $this->assets              = $parsed['assets'] ?? [];
        $this->databases           = $parsed['databases'] ?? [];
        $this->assetGroups         = $parsed['asset_groups'] ?? [];
        $this->databaseTableGroups = $parsed['database_table_groups'] ?? [];
    }

    private function schema(): SchemaInterface
    {
        $sb = new SchemaBuilder();

        // Group members are plain strings — either a literal name or a nested `@group` reference,
        // which expandAssetGroups() resolves recursively.
        $group = $sb->collection($sb->string()->notEmpty());

        return $sb->map([
            'plans'                 => $sb->collection($sb->raw())->default([]),
            'default_plan'          => $sb->string()->notEmpty()->default('default'),
            'assets'                => $sb->collection($sb->raw())->default([]),
            'databases'             => $sb->collection($sb->raw())->default([]),
            'asset_groups'          => $sb->collection($group)->default([]),
            'database_table_groups' => $sb->collection($group)->default([]),
        ]);
    }

    /** @return array<string, mixed> */
    public function getPlans(): array
    {
        return $this->plans;
    }

    public function getDefaultPlan(): string
    {
        return $this->defaultPlan;
    }

    /** @return array<string, mixed> */
    public function getAssets(): array
    {
        return $this->assets;
    }

    /** @return array<string, mixed> */
    public function getDatabases(): array
    {
        return $this->databases;
    }

    /** @return array<string, list<string>> */
    public function getAssetGroups(): array
    {
        return $this->assetGroups;
    }

    /** @return array<string, list<string>> */
    public function getDatabaseTableGroups(): array
    {
        return $this->databaseTableGroups;
    }

    /**
     * @param list<string> $assetGroups
     * @return list<string>
     * @throws Exception\DomainException if an asset group is not defined in config
     */
    public function expandAssetGroups(array $assetGroups): array
    {
        return $this->expand($assetGroups, $this->assetGroups, 'asset group');
    }

    /**
     * @param list<string> $databaseTableGroups
     * @return list<string>
     * @throws Exception\DomainException if a database table group is not defined in config
     */
    public function expandDatabaseTableGroups(array $databaseTableGroups): array
    {
        return $this->expand($databaseTableGroups, $this->databaseTableGroups, 'database table group');
    }

    /**
     * Resolve `@name` references against the defined groups, recursively.
     *
     * One implementation for both kinds: the two were duplicated character for character apart from
     * which group list they read and the noun in the error.
     *
     * @param list<string>                $names
     * @param array<string, list<string>> $groups
     * @return list<string>
     * @throws Exception\DomainException
     */
    private function expand(array $names, array $groups, string $noun): array
    {
        $expanded = [];
        foreach ($names as $name) {
            if (! str_starts_with((string) $name, '@')) {
                $expanded[] = [$name];
                continue;
            }

            $group = substr((string) $name, 1);
            if (! isset($groups[$group])) {
                $message       = "Could not expand $noun \"$group\".";
                $similarGroups = $this->findSimilarNames($group, array_keys($groups));
                if ($similarGroups) {
                    $message .= "\nDid you mean:\n" . implode("\n", $similarGroups) . "\n";
                }

                throw new Exception\DomainException($message);
            }

            $expanded[] = $this->expand($groups[$group], $groups, $noun);
        }

        $expanded = $expanded === [] ? [] : array_merge(...$expanded);
        sort($expanded);

        return $expanded;
    }

    /**
     * @param list<string> $names
     * @return list<string>
     */
    private function findSimilarNames(string $searchName, array $names): array
    {
        $similarNames = [];
        foreach ($names as $name) {
            if (false !== stripos($name, $searchName)) {
                $similarNames[] = $name;
            }
        }

        return $similarNames;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'plans'                 => $this->plans,
            'default_plan'          => $this->defaultPlan,
            'assets'                => $this->assets,
            'databases'             => $this->databases,
            'asset_groups'          => $this->assetGroups,
            'database_table_groups' => $this->databaseTableGroups,
        ];
    }
}
