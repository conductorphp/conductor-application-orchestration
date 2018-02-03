<?php
/**
 * @author Kirk Madera <kmadera@robofirm.com>
 */

namespace DevopsToolAppOrchestration;

use DevopsToolCore\Database\DatabaseImportExportAdapterManager;
use DevopsToolCore\Filesystem\MountManager\MountManager;
use Exception;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Class App
 *
 * @package App
 */
class ApplicationSnapshotTaker
{
    /**
     * @var DatabaseImportExportAdapterManager
     */
    private $databaseImportExportAdapterManager;
    /**
     * @var MountManager
     */
    private $mountManager;
    /**
     * @var FileLayoutHelper
     */
    private $fileLayoutHelper;
    /**
     * @var string
     */
    private $workingDirectory;
    /**
     * @var LoggerInterface
     */
    protected $logger;

    public function __construct(
        DatabaseImportExportAdapterManager $databaseImportExportAdapterManager,
        MountManager $mountManager,
        FileLayoutHelper $fileLayoutHelper,
        string $workingDirectory = '/tmp/.conductor-snapshot',
        LoggerInterface $logger = null
    ) {
        $this->databaseImportExportAdapterManager = $databaseImportExportAdapterManager;
        $this->mountManager = $mountManager;
        $this->fileLayoutHelper = $fileLayoutHelper;
        $this->workingDirectory = $workingDirectory;
        if (is_null($logger)) {
            $logger = new NullLogger();
        }
        $this->logger = $logger;
    }

    public function takeSnapshot(
        ApplicationConfig $application,
        string $filesystem,
        string $snapshotName,
        string $branch = null,
        bool $includeDatabases = true,
        bool $includeAssets = true,
        bool $scrub = true,
        bool $delete = true,
        array $assetSyncConfig = []
    ) {

        if ($delete) {
            $this->deleteExistingSnapshot($filesystem, $snapshotName);
        }

        $this->prepWorkingDirectory();

        if ($includeDatabases) {
            $this->uploadDatabases($application, $filesystem, $snapshotName, $branch, $scrub);
        }

        if ($includeAssets) {
            $this->uploadAssets($application, $filesystem, $snapshotName, $assetSyncConfig);
        }
    }

    /**
     * @param string $filesystem
     * @param string $snapshotName
     */
    private function deleteExistingSnapshot(string $filesystem, string $snapshotName): void
    {
        $this->logger->info('Deleting existing snapshot if exists.');
        $this->mountManager->deleteDir("$filesystem://snapshots/$snapshotName");
    }

    /**
     * @param ApplicationConfig $application
     * @param string            $filesystem
     * @param string            $snapshotName
     * @param string            $branch
     * @param bool              $scrub
     */
    private function uploadDatabases(
        ApplicationConfig $application,
        string $filesystem,
        string $snapshotName,
        string $branch = null,
        bool $scrub
    ): void {
        foreach ($application->getDatabases() as $databaseName => $database) {
            $adapterName = $database['importexport_adapter'] ?? $application->getDefaultDatabaseImportExportAdapter();
            $databaseImportExportAdapter = $this->databaseImportExportAdapterManager->getAdapter($adapterName);

            if (isset($databaseInfo['local_database_name'])) {
                $localDatabaseName = $database['local_database_name'];
            } else {
                if (FileLayoutAwareInterface::FILE_LAYOUT_BRANCH == $application->getFileLayout()) {
                    $localDatabaseName = $databaseName . '_' . $this->sanitizeDatabaseName($branch);
                } else {
                    $localDatabaseName = $databaseName;
                }
            }

            $exportOptions = [];
            if ($scrub && !empty($database['excludes'])) {
                $exportOptions['ignore_tables'] = $this->expandDatabaseTableGroups($application, $database['excludes']);
            }

            $filename = $databaseImportExportAdapter->exportToFile(
                $localDatabaseName,
                $this->workingDirectory,
                $exportOptions
            );
            $targetPath = "$filesystem://snapshots/$snapshotName/databases/$databaseName."
                . $databaseImportExportAdapter::getFileExtension();
            $this->mountManager->putFile("local://$filename", $targetPath);
        }
    }

    /**
     * @param ApplicationConfig $application
     * @param string            $filesystem
     * @param string            $snapshotName
     * @param array             $syncOptions
     */
    private function uploadAssets(
        ApplicationConfig $application,
        string $filesystem,
        string $snapshotName,
        array $syncOptions
    ): void {
        foreach ($application->getAssets() as $assetPath => $asset) {
            $pathPrefix = $this->fileLayoutHelper->resolvePathPrefix($application, $asset['location']);
            $sourcePath = $asset['local_path'] ?? $assetPath;
            if ($pathPrefix) {
                $sourcePath = "$pathPrefix/$sourcePath";
            }
            $sourcePath = $application->getAppRoot() . '/' . $sourcePath;
            $targetPath = "$filesystem://snapshots/$snapshotName/assets/{$asset['location']}/$assetPath";
            $this->logger->debug("Syncing asset \"$sourcePath\" to \"$targetPath\".");

            if (!empty($asset['excludes'])) {
                $syncOptions['excludes'] = array_merge(
                    $syncOptions['excludes'] ?? [],
                    $this->expandAssetGroups($application, $asset['excludes'])
                );
            }

            if (!empty($asset['includes'])) {
                $syncOptions['includes'] = array_merge(
                    $syncOptions['includes'] ?? [],
                    $this->expandAssetGroups($application, $asset['includes'])
                );
            }

            $this->mountManager->sync("local://{$sourcePath}", $targetPath, $syncOptions);
        }

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
     * @param ApplicationConfig $application
     * @param array             $assetGroups
     *
     * @return array
     * @throws Exception
     */
    private function expandAssetGroups(ApplicationConfig $application, array $assetGroups): array
    {
        $expandedAssetGroups = [];
        foreach ($assetGroups as $assetGroup) {
            if ('@' == substr($assetGroup, 0, 1)) {
                $group = substr($assetGroup, 1);
                $applicationAssetGroups = $application->getAssetGroups();
                if (!isset($applicationAssetGroups[$group])) {
                    $message = "Could not expand asset group \"$group\".";
                    $similarGroups = $this->findSimilarNames($group, array_keys($applicationAssetGroups));
                    if ($similarGroups) {
                        $message .= "\nDid you mean:\n" . implode("\n", $similarGroups) . "\n";
                    }
                    throw new Exception($message);
                }

                $expandedAssetGroups = array_merge(
                    $expandedAssetGroups,
                    $this->expandAssetGroups($application, $applicationAssetGroups[$group])
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
     */
    private function expandDatabaseTableGroups(ApplicationConfig $application, array $databaseTableGroups): array
    {
        $expandedDatabaseTableGroups = [];
        foreach ($databaseTableGroups as $databaseTableGroup) {
            if ('@' == substr($databaseTableGroup, 0, 1)) {
                $group = substr($databaseTableGroup, 1);
                $applicationDatabaseTableGroups = $application->getDatabaseTableGroups();
                if (!isset($applicationDatabaseTableGroups[$group])) {
                    $message = "Could not expand database table group \"$group\".";
                    $similarGroups = $this->findSimilarNames($group, array_keys($applicationDatabaseTableGroups));
                    if ($similarGroups) {
                        $message .= "\nDid you mean:\n" . implode("\n", $similarGroups) . "\n";
                    }
                    throw new Exception($message);
                }

                $expandedDatabaseTableGroups = array_merge(
                    $expandedDatabaseTableGroups,
                    $this->expandDatabaseTableGroups($application, $applicationDatabaseTableGroups[$group])
                );

            } else {
                $expandedDatabaseTableGroups[] = $databaseTableGroup;
            }
        }

        sort($expandedDatabaseTableGroups);
        return $expandedDatabaseTableGroups;
    }

    /**
     * @throws Exception
     */
    private function prepWorkingDirectory(): void
    {
        if (!file_exists($this->workingDirectory)) {
            mkdir($this->workingDirectory);
        }

        if (!is_dir($this->workingDirectory) && is_writable($this->workingDirectory)) {
            throw new Exception("Working directory \"{$this->workingDirectory}\" is not writable.");
        }
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

    /**
     * @param LoggerInterface $logger
     */
    public function setLogger(LoggerInterface $logger): void
    {
        $this->databaseImportExportAdapterManager->setLogger($logger);
        $this->mountManager->setLogger($logger);
        $this->logger = $logger;
    }
}
