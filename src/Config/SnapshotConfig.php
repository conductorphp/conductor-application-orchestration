<?php
/**
 * @author Kirk Madera <kmadera@robofirm.com>
 */

namespace ConductorAppOrchestration\Config;

use ConductorAppOrchestration\Exception;

class SnapshotConfig
{
    /**
     * @var array
     */
    private $config;

    /**
     * SnapshotConfig constructor.
     *
     * @param array $config
     */
    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * @return array
     */
    public function toArray(): array
    {
        return $this->config;
    }

    /**
     * @todo Validate by schema instead
     *
     * @throws Exception\RuntimeException
     * @throws Exception\DomainException
     */
    public function validate(): void
    {
        // No required fields
        // @todo Add validation
    }

    /**
     * @return array
     */
    public function getPlans(): array
    {
        return $this->config['plans'] ?? [];
    }

    /**
     * @return string
     */
    public function getDefaultPlan(): string
    {
        return $this->config['default_plan'] ?? 'default';
    }

    /**
     * @return array
     */
    public function getAssets(): array
    {
        return $this->config['assets'] ?? [];
    }

    /**
     * @return array
     */
    public function getAssetGroups(): array
    {
        return $this->config['asset_groups'] ?? [];
    }

    /**
     * @return array
     */
    public function getDatabases(): array
    {
        return $this->config['databases'] ?? [];
    }

    /**
     * @return array
     */
    public function getDatabaseTableGroups(): array
    {
        return $this->config['database_table_groups'] ?? [];
    }

    /**
     * @param array $assetGroups
     *
     * @return array
     * @throws Exception\DomainException if asset group not found in config
     */
    public function expandAssetGroups(array $assetGroups): array
    {
        $expandedAssetGroups = [];
        foreach ($assetGroups as $assetGroup) {
            if ('@' == substr($assetGroup, 0, 1)) {
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

                $expandedAssetGroups = array_merge(
                    $expandedAssetGroups,
                    $this->expandAssetGroups($applicationAssetGroups[$group])
                );
            } else {
                $expandedAssetGroups[] = $assetGroup;
            }
        }

        sort($expandedAssetGroups);
        return $expandedAssetGroups;
    }

    /**
     * @param array $databaseTableGroups
     *
     * @return array
     * @throws Exception\DomainException if database table group not found in config
     */
    public function expandDatabaseTableGroups(array $databaseTableGroups): array
    {
        $expandedDatabaseTableGroups = [];
        foreach ($databaseTableGroups as $databaseTableGroup) {
            if ('@' == substr($databaseTableGroup, 0, 1)) {
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

                $expandedDatabaseTableGroups = array_merge(
                    $expandedDatabaseTableGroups,
                    $this->expandDatabaseTableGroups($applicationDatabaseTableGroups[$group])
                );

            } else {
                $expandedDatabaseTableGroups[] = $databaseTableGroup;
            }
        }

        sort($expandedDatabaseTableGroups);
        return $expandedDatabaseTableGroups;
    }

    /**
     * @param string $searchName
     * @param array  $names
     *
     * @return array
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
}
