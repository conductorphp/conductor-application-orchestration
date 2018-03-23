<?php

namespace ConductorAppOrchestration\Snapshot\Command;

use ConductorAppOrchestration\Config\ApplicationConfig;
use ConductorAppOrchestration\Config\ApplicationConfigAwareInterface;
use ConductorAppOrchestration\Exception;
use ConductorAppOrchestration\FileLayoutInterface;
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
               DatabaseImportExportAdapterManagerAwareInterface, LoggerAwareInterface
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
    public function run(
        string $snapshotName,
        string $snapshotPath,
        string $branch = null,
        bool $includeDatabases = true,
        bool $includeAssets = true,
        array $assetSyncConfig = [],
        array $options = null
    ): ?string
    {
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
            $snapshotConfig = $this->applicationConfig->getSnapshotConfig();
            foreach ($options['databases'] as $databaseName => $database) {
                $adapterName = $database['importexport_adapter'] ??
                    $this->applicationConfig->getDefaultDatabaseImportExportAdapter();
                $databaseImportExportAdapter = $this->databaseImportExportAdapterManager->getAdapter($adapterName);

                if (isset($databaseInfo['local_database_name'])) {
                    $localDatabaseName = $database['local_database_name'];
                } else {
                    if (FileLayoutInterface::STRATEGY_BRANCH == $this->applicationConfig->getFileLayoutStrategy()) {
                        $localDatabaseName = $databaseName . '_' . $this->sanitizeDatabaseName($branch);
                    } else {
                        $localDatabaseName = $databaseName;
                    }
                }

                // @todo Add ability to alter database in more ways than excluding data, E.g. Remove all but two stores.
                $exportOptions = [];
                if (!empty($database['excludes'])) {
                    $exportOptions['ignore_tables'] = $snapshotConfig->expandDatabaseTableGroups($database['excludes']);
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
     * @inheritdoc
     */
    public function setApplicationConfig(ApplicationConfig $applicationConfig): void
    {
        $this->applicationConfig = $applicationConfig;
    }

    /**
     * @inheritdoc
     */
    public function setMountManager(MountManager $mountManager): void
    {
        $this->mountManager = $mountManager;
    }

    /**
     * @inheritdoc
     */
    public function setDatabaseImportExportAdapterManager(
        DatabaseImportExportAdapterManager $databaseImportExportAdapterManager
    ) {
        $this->databaseImportExportAdapterManager = $databaseImportExportAdapterManager;
    }

    /**
     * @inheritdoc
     */
    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }
}
