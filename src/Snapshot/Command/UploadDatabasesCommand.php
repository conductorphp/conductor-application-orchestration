<?php

namespace ConductorAppOrchestration\Snapshot\Command;

use ConductorAppOrchestration\Config\ApplicationConfig;
use ConductorAppOrchestration\Config\ApplicationConfigAwareInterface;
use ConductorAppOrchestration\Exception;
use ConductorAppOrchestration\FileLayoutAwareInterface;
use ConductorAppOrchestration\FileLayoutHelper;
use ConductorAppOrchestration\FileLayoutHelperAwareInterface;
use ConductorCore\Database\DatabaseImportExportAdapterManager;
use ConductorCore\Database\DatabaseImportExportAdapterManagerAwareInterface;
use ConductorCore\Filesystem\MountManager\MountManager;
use ConductorCore\Filesystem\MountManager\MountManagerAwareInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Class UploadDatabasesCommand
 *
 * @package ConductorAppOrchestration\Snapshot\Command
 */
class UploadDatabasesCommand
    implements SnapshotCommandInterface, ApplicationConfigAwareInterface, MountManagerAwareInterface,
               FileLayoutHelperAwareInterface, DatabaseImportExportAdapterManagerAwareInterface, LoggerAwareInterface
{
    /**
     * @var ApplicationConfig
     */
    private $applicationConfig;
    /**
     * @var MountManager
     */
    private $mountManager;
    /**
     * @var FileLayoutHelper
     */
    private $fileLayoutHelper;
    /**
     * @var DatabaseImportExportAdapterManager
     */
    private $databaseImportExportAdapterManager;

    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct()
    {
        $this->logger = new NullLogger();
    }

    /**
     * @inheritdoc
     */
    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * @inheritdoc
     */
    public function run(
        string $snapshotName,
        string $snapshotPath,
        string $branch = null,
        bool $includeDatabases = true,
        bool $includeAssets = true,
        array $assetSyncConfig = [],
        array $options = null
    ): ?string {
        if (!$includeDatabases) {
            return null;
        }

        if (!isset($this->applicationConfig)) {
            throw new Exception\RuntimeException('$this->applicationConfig must be set.');
        }

        if (!isset($this->mountManager)) {
            throw new Exception\RuntimeException('$this->mountManager must be set.');
        }

        if (!isset($this->databaseImportExportAdapterManager)) {
            throw new Exception\RuntimeException('$this->databaseImportExportAdapterManager must be set.');
        }

        if (!empty($options['databases'])) {
            foreach ($options['databases'] as $databaseName => $database) {
                $adapterName = $database['importexport_adapter'] ??
                    $this->applicationConfig->getDefaultDatabaseImportExportAdapter();
                $databaseImportExportAdapter = $this->databaseImportExportAdapterManager->getAdapter($adapterName);

                if (isset($databaseInfo['local_database_name'])) {
                    $localDatabaseName = $database['local_database_name'];
                } else {
                    if (FileLayoutAwareInterface::FILE_LAYOUT_BRANCH == $this->applicationConfig->getFileLayout()) {
                        $localDatabaseName = $databaseName . '_' . $this->sanitizeDatabaseName($branch);
                    } else {
                        $localDatabaseName = $databaseName;
                    }
                }

                // @todo Add ability to alter database in more ways than excluding data, E.g. Remove all but two stores.
                $exportOptions = [];
                if (!empty($database['excludes'])) {
                    $exportOptions['ignore_tables'] = $this->expandDatabaseTableGroups($database['excludes']);
                }

                $filename = $databaseImportExportAdapter->exportToFile(
                    $localDatabaseName,
                    getcwd(),
                    $exportOptions
                );
                $targetPath = "$snapshotPath/$snapshotName/databases/$databaseName."
                    . $databaseImportExportAdapter::getFileExtension();
                $this->mountManager->putFile("local://$filename", $targetPath);
            }
        }

        return null;
    }

    /**
     * @param string $name
     *
     * @return string
     */
    private function sanitizeDatabaseName(string $name): string
    {
        return strtolower(preg_replace('/[^a-z0-9]/i', '_', $name));
    }

    /**
     * @param array $databaseTableGroups
     *
     * @return array
     * @throws Exception\DomainException if database table group not found in config
     */
    private function expandDatabaseTableGroups(array $databaseTableGroups): array
    {
        $expandedDatabaseTableGroups = [];
        foreach ($databaseTableGroups as $databaseTableGroup) {
            if ('@' == substr($databaseTableGroup, 0, 1)) {
                $group = substr($databaseTableGroup, 1);
                $applicationDatabaseTableGroups = $this->applicationConfig->getSnapshotConfig()->getDatabaseTableGroups(
                );
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

    public function setApplicationConfig(ApplicationConfig $applicationConfig): void
    {
        $this->applicationConfig = $applicationConfig;
    }

    public function setMountManager(MountManager $mountManager): void
    {
        $this->mountManager = $mountManager;
    }

    /**
     * @param FileLayoutHelper $fileLayoutHelper
     */
    public function setFileLayoutHelper(FileLayoutHelper $fileLayoutHelper): void
    {
        $this->fileLayoutHelper = $fileLayoutHelper;
    }

    public function setDatabaseImportExportAdapterManager(
        DatabaseImportExportAdapterManager $databaseImportExportAdapterManager
    ) {
        $this->databaseImportExportAdapterManager = $databaseImportExportAdapterManager;
    }
}
