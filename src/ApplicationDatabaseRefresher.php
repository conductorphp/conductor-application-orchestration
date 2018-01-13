<?php
/**
 * @author Kirk Madera <kmadera@robofirm.com>
 */

namespace DevopsToolAppOrchestration;

use DevopsToolCore\Database\DatabaseImportExportAdapterInterface;
use DevopsToolCore\Database\DatabaseImportExportAdapterManager;
use DevopsToolCore\Filesystem\MountManager\MountManager;
use Exception;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Class ApplicationDatabaseRefresher
 *
 * @package DevopsToolAppOrchestration
 */
class ApplicationDatabaseRefresher
{
    /**
     * @var LoggerInterface
     */
    protected $logger;
    /**
     * @var MountManager
     */
    protected $mountManager;
    /**
     * @var DatabaseImportExportAdapterManager
     */
    protected $databaseImportAdapterManager;

    public function __construct(
        MountManager $mountManager,
        DatabaseImportExportAdapterManager $databaseImportAdapterManager,
        LoggerInterface $logger = null
    ) {
        $this->mountManager = $mountManager;
        if (is_null($logger)) {
            $logger = new NullLogger();
        }
        $this->logger = $logger;
        $this->databaseImportAdapterManager = $databaseImportAdapterManager;
    }

    /**
     * @param LoggerInterface $logger
     *
     * @return void
     */
    public function setLogger(LoggerInterface $logger): void
    {
        $this->mountManager->setLogger($logger);
        $this->databaseImportAdapterManager->setLogger($logger);
        $this->logger = $logger;
    }

    /**
     * @param ApplicationConfig $application
     * @param string            $sourceFilesystemPrefix
     * @param string            $snapshotName
     *
     * @throws Exception
     */
    public function refreshDatabases(
        ApplicationConfig $application,
        string $sourceFilesystemPrefix,
        string $snapshotName,
        string $branch = null
    ): void {

        if ($application->getDatabases()) {
            $this->logger->info('Refreshing databases');
            foreach ($application->getDatabases() as $databaseName => $database) {

                $databaseAdapterName = $database['adapter'] ?? $application->getDefaultDatabaseImportExportAdapter();
                $databaseImportExportAdapter = $this->databaseImportAdapterManager->getAdapter($databaseAdapterName);

                $filename = "$databaseName." . $databaseImportExportAdapter::getFileExtension();
                if ('branch' == $application->getFileLayout()) {
                    $databaseName .= '_' . $this->sanitizeDatabaseName($branch);
                }

                // @todo Deal with dropping/creating database
//                if ($this->databaseImportAdapterManager->databaseExists($database)) {
//                    if (!$this->databaseAdapter->databaseIsEmpty($database)) {
//                        if (!$replace) {
//                            $this->logger->debug("Database \"$database\" exists and is not empty. Skipping.");
//                            continue;
//                        }
//                        $this->logger->debug("Dropped and re-created database \"$database\".");
//                        $this->databaseAdapter->dropDatabase($database);
//                        $this->databaseAdapter->createDatabase($database);
//                    } else {
//                        $this->logger->debug("Using existing empty database \"$database\".");
//                    }
//                } else {
//                    $this->logger->debug("Created database \"$database\".");
//                    $this->databaseAdapter->createDatabase($database);
//                }


                // @todo Make working dir configurable
                $workingDir = $application->getAppRoot() . '/' . DatabaseImportExportAdapterInterface::DEFAULT_WORKING_DIR;
                $this->logger->debug("Downloading database script \"$filename\".");
                $this->mountManager->sync("$sourceFilesystemPrefix://snapshots/$snapshotName/databases/$filename", "local://$workingDir/$filename");

                $databaseImportExportAdapter->importFromFile(
                    "$workingDir/$filename",
                    $databaseName,
                    [] // This command does not yet support any options
                );

                // @todo Deal with running environment scripts
//                $this->logger->debug("Running database script \"$filename\" on database \"$database\"...");
//                $this->importDatabaseAdapter->importFromFile(
//                    $database,
//                    "{$this->workingDir}/$filename"
//                );
//                $this->filesystemTransfer->getDestinationFilesystem()->delete($filename);
//
//                if (!empty($databaseInfo['post_import_scripts'])) {
//                    $branchUrl = $branchDatabase = '';
//                    if (FileLayout::FILE_LAYOUT_BRANCH == $this->fileLayout) {
//                        $branchUrl = $this->sanitizeBranchForUrl($this->branch);
//                        $branchDatabase = $this->sanitizeBranchForDatabase($this->branch);
//                    }
//                    foreach ($databaseInfo['post_import_scripts'] as $scriptFilename) {
//                        $scriptContents = $this->repo->getFileContentsInHierarchy("database_scripts/$scriptFilename");
//                        if (false === $scriptContents) {
//                            throw new Exception(
//                                "Database script \"$scriptFilename\" not found in the \"$appName\" app setup repository."
//                            );
//                        }
//                        file_put_contents("{$this->workingDir}/$scriptFilename", $scriptContents);
//
//                        $stringReplacements = [];
//                        if (FileLayout::FILE_LAYOUT_BRANCH == $this->fileLayout) {
//                            $stringReplacements['{{branch}}'] = $branchUrl;
//                            $stringReplacements['{{branch_database}}'] = $branchDatabase;
//                        }
//
//                        $this->logger->debug(
//                            "Running database script \"$scriptFilename\" on database \"$database\"..."
//                        );
//                        $this->databaseAdapter->runSqlFile(
//                            $database,
//                            "{$this->workingDir}/$scriptFilename",
//                            $stringReplacements
//                        );
//                        unlink("{$this->workingDir}/$scriptFilename");
//                    }
//                }
            }
        } else {
            $this->logger->info('No databases specified in configuration.');
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
     * @param $branch
     *
     * @return string
     */
    private function sanitizeBranchForUrl($branch)
    {
        return strtolower(preg_replace('/[^a-z0-9\.-]/i', '-', $branch));
    }

    /**
     * @param $branch
     *
     * @return string
     */
    private function sanitizeBranchForDatabase($branch)
    {
        return strtolower(preg_replace('/[^a-z0-9\.-]/i', '_', $branch));
    }
}
