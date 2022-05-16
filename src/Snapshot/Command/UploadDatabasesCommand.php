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
                $this->mountManager->putFile("local://$filename", $targetPath);
            }
        }

        return null;
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
