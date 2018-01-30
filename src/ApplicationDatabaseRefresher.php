<?php
/**
 * @author Kirk Madera <kmadera@robofirm.com>
 */

namespace DevopsToolAppOrchestration;

use DevopsToolCore\Database\DatabaseAdapterManager;
use DevopsToolCore\Database\DatabaseImportExportAdapterInterface;
use DevopsToolCore\Database\DatabaseImportExportAdapterManager;
use DevopsToolCore\Filesystem\MountManager\MountManager;
use DevopsToolAppOrchestration\Exception;
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
    /**
     * @var DatabaseAdapterManager
     */
    private $databaseAdapterManager;

    public function __construct(
        MountManager $mountManager,
        DatabaseAdapterManager $databaseAdapterManager,
        DatabaseImportExportAdapterManager $databaseImportAdapterManager,
        LoggerInterface $logger = null
    ) {
        $this->mountManager = $mountManager;
        if (is_null($logger)) {
            $logger = new NullLogger();
        }
        $this->logger = $logger;
        $this->databaseImportAdapterManager = $databaseImportAdapterManager;
        $this->databaseAdapterManager = $databaseAdapterManager;
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
     */
    public function refreshDatabases(
        ApplicationConfig $application,
        string $sourceFilesystemPrefix,
        string $snapshotName,
        string $branch = null
    ): void {

        // @todo Add flag for whether to replace or not? Or should it just always warn and then we have a force flag to skip the warning?
        $replace = false;

        if ($application->getDatabases()) {
            $this->logger->info('Refreshing databases');
            foreach ($application->getDatabases() as $databaseName => $database) {

                $adapterName = $database['adapter'] ?? $application->getDefaultDatabaseAdapter();
                $databaseAdapter = $this->databaseAdapterManager->getAdapter($adapterName);
                $adapterName = $database['importexport_adapter'] ?? $application->getDefaultDatabaseImportExportAdapter();
                $databaseImportExportAdapter = $this->databaseImportAdapterManager->getAdapter($adapterName);

                $filename = "$databaseName." . $databaseImportExportAdapter::getFileExtension();
                if ('branch' == $application->getFileLayout()) {
                    $databaseName .= '_' . $this->sanitizeDatabaseName($branch);
                }

                if ($databaseAdapter->databaseExists($databaseName)) {
                    if (!$databaseAdapter->databaseIsEmpty($databaseName)) {
                        if (!$replace) {
                            $this->logger->debug("Database \"$databaseName\" exists and is not empty. Skipping.");
                            continue;
                        }
                        $this->logger->debug("Dropped and re-created database \"$databaseName\".");
                        $databaseAdapter->dropDatabase($databaseName);
                        $databaseAdapter->createDatabase($databaseName);
                    } else {
                        $this->logger->debug("Using existing empty database \"$databaseName\".");
                    }
                } else {
                    $this->logger->debug("Created database \"$databaseName\".");
                    $databaseAdapter->createDatabase($databaseName);
                }

                // @todo Make working dir configurable
                $workingDir = $application->getAppRoot() . '/'
                    . DatabaseImportExportAdapterInterface::DEFAULT_WORKING_DIR;
                $this->logger->debug("Downloading database script \"$filename\".");
                $this->mountManager->sync(
                    "$sourceFilesystemPrefix://snapshots/$snapshotName/databases/$filename",
                    "local://$workingDir/$filename"
                );

                $databaseImportExportAdapter->importFromFile(
                    "$workingDir/$filename",
                    $databaseName,
                    [] // This command does not yet support any options
                );

                // @todo Deal with running environment scripts
                if (!empty($database['post_import_scripts'])) {
                    $branchUrl = $branchDatabase = '';
                    if (FileLayout::FILE_LAYOUT_BRANCH == $application->getFileLayout()) {
                        $branchUrl = $this->sanitizeBranchForUrl($branch);
                        $branchDatabase = $this->sanitizeBranchForDatabase($branch);
                    }

                    $environment = $application->getCurrentEnvironment();
                    $configRoot = $application->getConfigRoot();
                    foreach ($database['post_import_scripts'] as $scriptFilename) {

                        $filename = "$configRoot/environments/$environment/files/$scriptFilename";
                        if (!file_exists($filename)) {
                            $filename = "$configRoot/files/$scriptFilename";
                            if (!file_exists($filename)) {
                                throw new Exception\RuntimeException("Database $databaseName post_import_scripts \"$scriptFilename\" not found in config");
                            }
                        };

                        $filename = $this->applyStringReplacements(
                            $application,
                            $branchUrl,
                            $branchDatabase,
                            $filename
                        );

                        $databaseImportExportAdapter->importFromFile(
                            $filename,
                            $databaseName,
                            [] // This command does not yet support any options
                        );
                    }
                }
            }
        } else {
            $this->logger->info('No databases specified in configuration.');
        }

        // @todo Remove working directory contents after finishing?
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

    /**
     * @param ApplicationConfig $application
     * @param                   $branchUrl
     * @param                   $branchDatabase
     * @param                   $filename
     *
     * @return string Filename
     */
    private function applyStringReplacements(
        ApplicationConfig $application,
        $branchUrl,
        $branchDatabase,
        $filename
    ): string {
        $stringReplacements = [];
        if (FileLayout::FILE_LAYOUT_BRANCH == $application->getFileLayout()) {
            $stringReplacements['{{branch}}'] = $branchUrl;
            $stringReplacements['{{branch_database}}'] = $branchDatabase;
        }
        if ($stringReplacements) {
            $contents = file_get_contents($filename);
            foreach ($stringReplacements as $search => $replace) {
                $contents = str_replace($search, $replace, $contents);
            }
            // @todo Use of tmpfile ok here? These will be small, managed files. I don't foresee hitting a disk space
            //       issue here
            $tempFile = tmpfile();
            fwrite($tempFile, $contents);
            $filename = (new \SplFileInfo($tempFile))->getPathname();
        }
        return $filename;
    }
}
