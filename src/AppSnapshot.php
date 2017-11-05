<?php
/**
 * @author Kirk Madera <kmadera@robofirm.com>
 */

namespace DevopsToolAppOrchestration;

use DevopsToolCore\Database\DatabaseImportAdapterInterface;
use DevopsToolCore\Filesystem\FilesystemTransferInterface;
use DevopsToolCore\ShellCommandHelper;
use Exception;
use Monolog\Handler\NullHandler;
use Psr\Log\LoggerInterface;

/**
 * Class App
 *
 * @package App
 */
class AppSnapshot implements FileLayoutAwareInterface
{
    use FileLayoutAwareTrait;

    /**
     * @var FilesystemTransferInterface
     */
    private $assetFilesystemTransfer;
    /**
     * @var FilesystemTransferInterface
     */
    private $databaseFilesystemTransfer;
    /**
     * @var string
     */
    private $workingDir;
    /**
     * @var DatabaseImportAdapterInterface
     */
    protected $databaseAdapter;
    /**
     * @var array
     */
    private $assets;
    /**
     * @var array
     */
    private $assetGroups;
    /**
     * @var array
     */
    private $databases;
    /**
     * @var array
     */
    private $databaseTableGroups;
    /**
     * @var bool
     */
    private $delete;
    /**
     * @var LoggerInterface
     */
    protected $logger;
    /**
     * @var FileLayoutHelper|null
     */
    private $fileLayoutHelper;
    /**
     * @var ShellCommandHelper|null
     */
    private $shellCommandHelper;

    /**
     * AppSnapshot constructor.
     *
     * @param                                           $workingDir
     * @param                                           $appRoot
     * @param                                           $fileLayout
     * @param                                           $branch
     * @param DatabaseImportAdapterInterface|null       $databaseAdapter
     * @param FilesystemTransferInterface|null          $databaseFilesystemTransfer
     * @param array|null                                $databases
     * @param array|null                                $databaseTableGroups
     * @param FilesystemTransferInterface|null          $assetFilesystemTransfer
     * @param array|null                                $assets
     * @param array|null                                $assetGroups
     * @param bool                                      $delete
     * @param LoggerInterface|null                      $logger
     * @param FileLayoutHelper|null                     $fileLayoutHelper
     * @param ShellCommandHelper|null                   $shellCommandHelper
     */
    public function __construct(
        $workingDir,
        $appRoot,
        $fileLayout,
        $branch,
        DatabaseImportAdapterInterface $databaseAdapter = null,
        FilesystemTransferInterface $databaseFilesystemTransfer = null,
        array $databases = null,
        array $databaseTableGroups = null,
        FilesystemTransferInterface $assetFilesystemTransfer = null,
        array $assets = null,
        array $assetGroups = null,
        $delete = false,
        LoggerInterface $logger = null,
        FileLayoutHelper $fileLayoutHelper = null,
        ShellCommandHelper $shellCommandHelper = null
    ) {
        $this->workingDir = $this->prepWorkingDirectory($workingDir);
        $this->appRoot = $appRoot;
        $this->fileLayout = $fileLayout;
        $this->branch = $branch;
        $this->databaseAdapter = $databaseAdapter;
        $this->databaseFilesystemTransfer = $databaseFilesystemTransfer;
        $this->databases = $databases;
        $this->databaseTableGroups = $databaseTableGroups;
        $this->assetFilesystemTransfer = $assetFilesystemTransfer;
        $this->assets = $assets;
        $this->assetGroups = $assetGroups;
        $this->delete = $delete;
        if (is_null($logger)) {
            $logger = new NullHandler();
        }
        $this->logger = $logger;
        if (is_null($fileLayoutHelper)) {
            $fileLayoutHelper = new FileLayoutHelper();
        }
        $this->fileLayoutHelper = $fileLayoutHelper;
        if (is_null($shellCommandHelper)) {
            $shellCommandHelper = new ShellCommandHelper($logger);
        }
        $this->shellCommandHelper = $shellCommandHelper;
        $this->fileLayoutHelper->loadFileLayoutPaths($this);
    }

    /**
     * @param bool $includeDatabases
     * @param bool $includeAssets
     * @param bool $scrub
     *
     * @throws Exception
     */
    public function createSnapshot($includeDatabases = true, $includeAssets = true, $scrub = true)
    {
        try {
            if ($this->delete) {
                $this->deleteExistingSnapshot();
            }
            if ($includeDatabases) {
                $this->uploadDatabases($scrub);
            }
            if ($includeAssets) {
                $this->uploadAssets();
            }
        } catch (Exception $e) {
            throw $e;
        }
    }

    /**
     * Deletes existing snapshot, if any
     */
    private function deleteExistingSnapshot()
    {
        $filesystem = $this->assetFilesystemTransfer->getDestinationFilesystem();
        $dirs = $filesystem->listContents();
        foreach ($dirs as $dir) {
            $filesystem->deleteDir($dir['path']);
        }
    }

    /**
     * @param bool $scrub
     */
    private function uploadDatabases($scrub)
    {
        if ($this->databases) {
            foreach ($this->databases as $database => $databaseInfo) {

                if (isset($databaseInfo['local_database_name'])) {
                    $localDatabaseName = $databaseInfo['local_database_name'];
                } else {
                    if (FileLayoutAwareInterface::FILE_LAYOUT_BRANCH == $this->fileLayout) {
                        $localDatabaseName = $database . '_' . $this->sanitizeDatabaseName($this->branch);
                    } else {
                        $localDatabaseName = $database;
                    }
                }

                $ignoreTables = [];
                if ($scrub && isset($databaseInfo['excludes'])) {
                    $ignoreTables = $this->expandDatabaseTableGroups($databaseInfo['excludes']);
                }

                $this->logger->debug("Dumping database \"$localDatabaseName\" to file...");
                $filename = $this->databaseAdapter->exportToFile(
                    $localDatabaseName,
                    "{$this->workingDir}/$database",
                    $ignoreTables
                );

                $fileBasename = basename($filename);
                $this->logger->debug("Copying database dump \"$fileBasename\" to target file system.");
                $this->databaseFilesystemTransfer->copy($fileBasename, $fileBasename);
                $this->databaseFilesystemTransfer->getSourceFilesystem()->delete($fileBasename);
            }
        }
    }

    /**
     */
    private function uploadAssets()
    {
        if ($this->assets) {
            foreach ($this->assets as $destinationPath => $asset) {
                if (empty($asset['ensure']) || empty($asset['location'])) {
                    throw new Exception(
                        "Asset \"$destinationPath\" must have \"ensure\" and \"location\" properties set."
                    );
                }
                $pathPrefix = $this->fileLayoutHelper->resolvePathPrefix($this, $asset['location']);
                if (!empty($asset['local_path'])) {
                    $sourcePath = $asset['local_path'];
                } else {
                    $sourcePath = $destinationPath;
                }
                if ($pathPrefix) {
                    $sourcePath = "$pathPrefix/$sourcePath";
                }
                $destinationPath = "{$asset['location']}/$destinationPath";
                if (!$this->assetFilesystemTransfer->getSourceFilesystem()->has($sourcePath)) {
                    $this->logger->debug(
                        "Skipping asset \"$sourcePath\" as it does not exist on the source file system."
                    );
                    return;
                }

                if ('file' == $asset['ensure']) {
                    $this->logger->debug("Copying asset \"$sourcePath\" to target file system.");
                    $this->assetFilesystemTransfer->copy($sourcePath, $destinationPath);
                    continue;
                }

                $excludes = !empty($asset['excludes']) ? $asset['excludes'] : [];
                if ($excludes) {
                    $excludes = $this->expandAssetGroups($excludes);
                }

                $includes = !empty($asset['includes']) ? $asset['includes'] : [];
                if ($includes) {
                    $includes = $this->expandAssetGroups($includes);
                }

                $this->logger->debug("Syncing asset \"$sourcePath\" to $destinationPath the target filesystem.");
                $this->assetFilesystemTransfer->sync($sourcePath, $destinationPath, $excludes, $includes);
            }
        }
    }

    /**
     * @param $name
     *
     * @return string
     */
    private function sanitizeDatabaseName($name)
    {
        return strtolower(preg_replace('/[^a-z0-9]/i', '_', $name));
    }

    /**
     * @param array $assetGroups
     *
     * @return array
     */
    private function expandAssetGroups(array $assetGroups)
    {
        $expandedAssetGroups = [];
        foreach ($assetGroups as $assetGroup) {
            if ('@' == substr($assetGroup, 0, 1)) {
                $group = substr($assetGroup, 1);
                if (!isset($this->assetGroups[$group])) {
                    $message = "Could not expand asset group \"$group\".";
                    $similarGroups = $this->findSimilarNames($group, array_keys($this->assetGroups));
                    if ($similarGroups) {
                        $message .= "\nDid you mean:\n" . implode("\n", $similarGroups) . "\n";
                    }
                    throw new Exception($message);
                }

                $expandedAssetGroups = array_merge(
                    $expandedAssetGroups,
                    $this->expandAssetGroups($this->assetGroups[$group])
                );
            } else {
                $expandedAssetGroups[] = $assetGroup;
            }
        }

        sort($expandedAssetGroups);
        return $expandedAssetGroups;
    }

    /**
     * @param array $ignoredTables
     *
     * @return array
     */
    private function expandDatabaseTableGroups(array $ignoredTables)
    {

        $expandedTables = [];
        foreach ($ignoredTables as $ignoredTable) {
            if ('@' == substr($ignoredTable, 0, 1)) {
                $group = substr($ignoredTable, 1);
                if (!isset($this->databaseTableGroups[$group])) {
                    $message = "Could not expand database table group \"$group\".";
                    $similarGroups = $this->findSimilarNames($group, array_keys($this->databaseTableGroups));
                    if ($similarGroups) {
                        $message .= "\nDid you mean:\n" . implode("\n", $similarGroups) . "\n";
                    }
                    throw new Exception($message);
                }

                $expandedTables = array_merge(
                    $expandedTables,
                    $this->expandDatabaseTableGroups($this->databaseTableGroups[$group])
                );

            } else {
                $expandedTables[] = $ignoredTable;
            }
        }

        sort($expandedTables);
        return $expandedTables;
    }

    /**
     * Ensures working directory is writable and prepares a subdirectory within it
     *
     * @param string $workingDir
     *
     * @return string
     * @throws Exception
     */
    private function prepWorkingDirectory($workingDir)
    {
        if (!is_writable($workingDir)) {
            throw new Exception("Working directory \"$workingDir\" is not writable.");
        }
        $workingDir .= "/.devops/app-snapshot";
        if (!is_dir($workingDir)) {
            mkdir($workingDir, 0700, true);
        }
        return $workingDir;
    }

    private function findSimilarNames($searchName, array $names)
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
