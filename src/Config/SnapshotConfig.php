<?php

declare(strict_types=1);

namespace ConductorAppOrchestration\Config;

use ArrayObject;
use ConductorAppOrchestration\Exception;
use ConductorCore\Config\ParsesConfigTrait;
use ConductorCore\Config\Schema\SchemaBuilder;
use ConductorCore\Config\Schema\SchemaInterface;
use Psr\Log\LoggerInterface;

use function array_keys;
use function array_merge;
use function array_unique;
use function array_values;
use function implode;
use function sort;
use function sprintf;
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

    /**
     * Groups a platform package keeps for compatibility but wants plans to stop using, each with the
     * message telling them what to use instead (CTAP-2161).
     *
     * @var array<string, string>
     */
    public array $deprecatedAssetGroups;

    /** @var array<string, string> */
    public array $deprecatedDatabaseTableGroups;

    /** The deprecated groups already warned about, so each is reported once per run. */
    private ArrayObject $warnedDeprecatedGroups;

    /** @param array<string, mixed>|null $config */
    public function __construct(?array $config, private ?LoggerInterface $logger = null)
    {
        $parsed = $this->parseConfig($config, $this->schema(), self::CONFIG_KEY);

        $this->plans               = $parsed['plans'] ?? [];
        $this->defaultPlan         = $parsed['default_plan'] ?? 'default';
        $this->assets              = $parsed['assets'] ?? [];
        $this->databases           = $parsed['databases'] ?? [];
        $this->assetGroups         = $parsed['asset_groups'] ?? [];
        $this->databaseTableGroups = $parsed['database_table_groups'] ?? [];
        $this->deprecatedAssetGroups         = $parsed['deprecated_asset_groups'] ?? [];
        $this->deprecatedDatabaseTableGroups = $parsed['deprecated_database_table_groups'] ?? [];
        $this->warnedDeprecatedGroups        = new ArrayObject();
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
            // Group name => what to use instead. Older versions ignore these keys, which is what lets
            // a platform package ship them without requiring this version.
            'deprecated_asset_groups'          => $sb->collection($sb->string()->notEmpty())->default([]),
            'deprecated_database_table_groups' => $sb->collection($sb->string()->notEmpty())->default([]),
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
        $this->warnAboutDeprecatedGroups($assetGroups, $this->deprecatedAssetGroups, 'asset group');

        return $this->expand($assetGroups, $this->assetGroups, 'asset group');
    }

    /**
     * @param list<string> $databaseTableGroups
     * @return list<string>
     * @throws Exception\DomainException if a database table group is not defined in config
     */
    public function expandDatabaseTableGroups(array $databaseTableGroups): array
    {
        $this->warnAboutDeprecatedGroups(
            $databaseTableGroups,
            $this->deprecatedDatabaseTableGroups,
            'database table group'
        );

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

        // Groups may overlap (a table can be both scratch and personal data), so a name can arrive
        // more than once
        $expanded = $expanded === [] ? [] : array_values(array_unique(array_merge(...$expanded)));
        sort($expanded);

        return $expanded;
    }

    /**
     * Warns about the deprecated groups a plan names itself. A group referenced only from inside
     * another group is not the plan's choice, so it is not reported.
     *
     * @param list<string>          $names
     * @param array<string, string> $deprecated
     */
    private function warnAboutDeprecatedGroups(array $names, array $deprecated, string $noun): void
    {
        foreach ($names as $name) {
            $group = substr((string) $name, 1);
            if (! str_starts_with((string) $name, '@') || ! isset($deprecated[$group])) {
                continue;
            }

            $key = "$noun:$group";
            if (isset($this->warnedDeprecatedGroups[$key])) {
                continue;
            }

            $this->warnedDeprecatedGroups[$key] = true;
            $this->logger?->warning(sprintf('The %s "@%s" is deprecated. %s', $noun, $group, $deprecated[$group]));
        }
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
            'deprecated_asset_groups'          => $this->deprecatedAssetGroups,
            'deprecated_database_table_groups' => $this->deprecatedDatabaseTableGroups,
        ];
    }
}
