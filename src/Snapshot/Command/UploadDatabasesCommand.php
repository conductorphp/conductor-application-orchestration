<?php

namespace ConductorAppOrchestration\Snapshot\Command;

use ConductorAppOrchestration\Config\ApplicationConfig;
use ConductorAppOrchestration\Config\ApplicationConfigAwareInterface;
use ConductorAppOrchestration\Exception;
use ConductorCore\Database\DatabaseImportExportAdapterManager;
use ConductorCore\Database\DatabaseImportExportAdapterManagerAwareInterface;
use ConductorCore\Filesystem\MountManager\MountManager;
use ConductorCore\Filesystem\MountManager\MountManagerAwareInterface;
use League\Flysystem\FilesystemException;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class UploadDatabasesCommand
    implements SnapshotCommandInterface, ApplicationConfigAwareInterface, MountManagerAwareInterface,
    DatabaseImportExportAdapterManagerAwareInterface, LoggerAwareInterface
{
    private ApplicationConfig $applicationConfig;
    private MountManager $mountManager;
    private DatabaseImportExportAdapterManager $databaseImportExportAdapterManager;
    private LoggerInterface $logger;

    public function __construct()
    {
        $this->logger = new NullLogger();
    }

    public function run(
        string $snapshotName,
        string $snapshotPath,
        bool   $includeDatabases = true,
        bool   $includeAssets = true,
        array  $assetSyncConfig = [],
        ?array  $options = null
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
            $snapshotConfig = $this->applicationConfig->getSnapshotConfig();
            $databases = $this->applicationConfig->getDatabases();
            foreach ($options['databases'] as $databaseName => $database) {
                if (isset($databases[$databaseName])) {
                    $database = array_replace_recursive($databases[$databaseName], $database);
                }

                $adapterName = $database['importexport_adapter'] ??
                    $this->applicationConfig->getDefaultDatabaseImportExportAdapter();
                $databaseImportExportAdapter = $this->databaseImportExportAdapterManager->getAdapter($adapterName);

                $databaseAlias = $database['alias'] ?? $databaseName;

                // @todo Add ability to alter database in more ways than excluding data, E.g. Remove all but two stores.
                $exportOptions = [];
                if (!empty($database['excludes'])) {
                    $exportOptions['ignore_tables'] = $snapshotConfig->expandDatabaseTableGroups($database['excludes']);
                }

                $filename = $databaseImportExportAdapter->exportToFile(
                    $databaseAlias,
                    getcwd(),
                    $exportOptions
                );
                $targetPath = "$snapshotPath/$snapshotName/databases/$databaseName."
                    . $databaseImportExportAdapter::getFileExtension();
                $this->logger->debug("Copying to \"$targetPath\".");
                $this->mountManager->copy("local://$filename", $targetPath);
            }
        }

        return null;
    }

    public function setApplicationConfig(ApplicationConfig $applicationConfig): void
    {
        $this->applicationConfig = $applicationConfig;
    }

    public function setMountManager(MountManager $mountManager): void
    {
        $this->mountManager = $mountManager;
    }

    public function setDatabaseImportExportAdapterManager(
        DatabaseImportExportAdapterManager $databaseImportExportAdapterManager
    ): void {
        $this->databaseImportExportAdapterManager = $databaseImportExportAdapterManager;
    }

    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }
}
