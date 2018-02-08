<?php
/**
 * @author Kirk Madera <kmadera@robofirm.com>
 */

namespace DevopsToolAppOrchestration;

use DevopsToolCore\Database\DatabaseAdapterManager;
use DevopsToolCore\Database\DatabaseImportExportAdapterInterface;
use DevopsToolCore\Database\DatabaseImportExportAdapterManager;
use DevopsToolCore\Filesystem\MountManager\MountManager;
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
     * @var ApplicationConfig
     */
    private $applicationConfig;
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
    /**
     * @var FileLayoutHelper
     */
    private $fileLayoutHelper;
    /**
     * @var LoggerInterface
     */
    protected $logger;

    public function __construct(
        ApplicationConfig $applicationConfig,
        MountManager $mountManager,
        DatabaseAdapterManager $databaseAdapterManager,
        DatabaseImportExportAdapterManager $databaseImportAdapterManager,
        FileLayoutHelper $fileLayoutHelper,
        LoggerInterface $logger = null
    ) {
        $this->applicationConfig = $applicationConfig;
        $this->mountManager = $mountManager;
        $this->databaseImportAdapterManager = $databaseImportAdapterManager;
        $this->databaseAdapterManager = $databaseAdapterManager;
        if (is_null($logger)) {
            $logger = new NullLogger();
        }
        $this->fileLayoutHelper = $fileLayoutHelper;
        $this->logger = $logger;
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
     * @param string $sourceFilesystemPrefix
     * @param string $snapshotName
     *
     * @throws Exception\RuntimeException if app skeleton has not yet been installed
     */
    public function refreshDatabases(
        string $sourceFilesystemPrefix,
        string $snapshotName,
        string $branch = null,
        bool $replaceIfExists = false
    ): void {
        $application = $this->applicationConfig;
        $fileLayout = new FileLayout(
            $application->getAppRoot(),
            $application->getFileLayout(),
            $application->getRelativeDocumentRoot()
        );
        $this->fileLayoutHelper->loadFileLayoutPaths($fileLayout);
        if (!$this->fileLayoutHelper->isFileLayoutInstalled($fileLayout)) {
            throw new Exception\RuntimeException(
                "App is not yet installed. Install app skeleton before refreshing databases."
            );
        }

        if ($application->getDatabases()) {
            $this->logger->info('Refreshing databases');
            foreach ($application->getDatabases() as $databaseName => $database) {

                $adapterName = $database['adapter'] ?? $application->getDefaultDatabaseAdapter();
                $databaseAdapter = $this->databaseAdapterManager->getAdapter($adapterName);
                $adapterName = $database['importexport_adapter'] ??
                    $application->getDefaultDatabaseImportExportAdapter();
                $databaseImportExportAdapter = $this->databaseImportAdapterManager->getAdapter($adapterName);

                $filename = "$databaseName." . $databaseImportExportAdapter::getFileExtension();
                if ('branch' == $application->getFileLayout()) {
                    $databaseName .= '_' . $this->sanitizeDatabaseName($branch);
                }

                if ($databaseAdapter->databaseExists($databaseName)) {
                    if (!$databaseAdapter->databaseIsEmpty($databaseName)) {
                        if (!$replaceIfExists) {
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

                $workingDir = getcwd() . '/' . DatabaseImportExportAdapterInterface::DEFAULT_WORKING_DIR;
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

                    foreach ($database['post_import_scripts'] as $scriptFilename) {

                        $scriptFilename = $this->applyStringReplacements(
                            $branchUrl,
                            $branchDatabase,
                            $scriptFilename
                        );

                        $databaseAdapter->run(
                            file_get_contents($scriptFilename),
                            $databaseName
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
     * @param                   $branchUrl
     * @param                   $branchDatabase
     * @param                   $filename
     *
     * @return string Filename
     */
    private function applyStringReplacements(
        $branchUrl,
        $branchDatabase,
        $filename
    ): string {
        $stringReplacements = [];
        if (FileLayout::FILE_LAYOUT_BRANCH == $this->applicationConfig->getFileLayout()) {
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
