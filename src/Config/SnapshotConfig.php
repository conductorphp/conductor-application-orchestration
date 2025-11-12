<?php

namespace ConductorAppOrchestration\Config;

use ConductorAppOrchestration\Exception;

class SnapshotConfig
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function toArray(): array
    {
        return $this->config;
    }

    /**
     * @throws Exception\RuntimeException
     * @throws Exception\DomainException
     * @todo Validate by schema instead
     *
     */
    public function validate(): void
    {
        // No required fields
        // @todo Add validation
    }

    public function getPlans(): array
    {
        return $this->config['plans'] ?? [];
    }

    public function getDefaultPlan(): string
    {
        return $this->config['default_plan'] ?? 'default';
    }

    public function getAssets(): array
    {
        return $this->config['assets'] ?? [];
    }

    public function getDatabases(): array
    {
        return $this->config['databases'] ?? [];
    }

    /**
     * @throws Exception\DomainException if asset group not found in config
     */
    public function expandAssetGroups(array $assetGroups): array
    {
        $expandedAssetGroups = [];
        foreach ($assetGroups as $assetGroup) {
            if (str_starts_with($assetGroup, '@')) {
                $group = substr($assetGroup, 1);
                $applicationAssetGroups = $this->getAssetGroups();
                if (!isset($applicationAssetGroups[$group])) {
                    $message = "Could not expand asset group \"$group\".";
                    $similarGroups = $this->findSimilarNames($group, array_keys($applicationAssetGroups));
                    if ($similarGroups) {
                        $message .= "\nDid you mean:\n" . implode("\n", $similarGroups) . "\n";
                    }
                    throw new Exception\DomainException($message);
                }

                $expandedAssetGroups[] = $this->expandAssetGroups($applicationAssetGroups[$group]);
            } else {
                $expandedAssetGroups[] = [$assetGroup];
            }
        }

        $expandedAssetGroups = array_merge(...$expandedAssetGroups);
        sort($expandedAssetGroups);
        return $expandedAssetGroups;
    }

    public function getAssetGroups(): array
    {
        return $this->config['asset_groups'] ?? [];
    }

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

    /**
     * @throws Exception\DomainException if database table group not found in config
     */
    public function expandDatabaseTableGroups(array $databaseTableGroups): array
    {
        $expandedDatabaseTableGroups = [];
        foreach ($databaseTableGroups as $databaseTableGroup) {
            if ('@' === substr($databaseTableGroup, 0, 1)) {
                $group = substr($databaseTableGroup, 1);
                $applicationDatabaseTableGroups = $this->getDatabaseTableGroups();
                if (!isset($applicationDatabaseTableGroups[$group])) {
                    $message = "Could not expand database table group \"$group\".";
                    $similarGroups = $this->findSimilarNames($group, array_keys($applicationDatabaseTableGroups));
                    if ($similarGroups) {
                        $message .= "\nDid you mean:\n" . implode("\n", $similarGroups) . "\n";
                    }
                    throw new Exception\DomainException($message);
                }

                $expandedDatabaseTableGroups[] = $this->expandDatabaseTableGroups($applicationDatabaseTableGroups[$group]);
            } else {
                $expandedDatabaseTableGroups[] = [$databaseTableGroup];
            }
        }

        $expandedDatabaseTableGroups = array_merge(...$expandedDatabaseTableGroups);
        sort($expandedDatabaseTableGroups);
        return $expandedDatabaseTableGroups;
    }

    public function getDatabaseTableGroups(): array
    {
        return $this->config['database_table_groups'] ?? [];
    }
}
