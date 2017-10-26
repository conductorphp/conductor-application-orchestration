<?php
/**
 * @author Kirk Madera <kmadera@robofirm.com>
 */

namespace DevopsToolAppOrchestration;

use DevopsToolCore\Database\ImportExportAdapter\DatabaseImportExportAdapterInterface;
use Exception;
use Monolog\Handler\NullHandler;
use Psr\Log\LoggerInterface;
use DevopsToolCore\Filesystem\FilesystemTransferInterface;
use DevopsToolCore\Database\DatabaseAdapter;
use DevopsToolCore\ShellCommandHelper;

/**
 * Class App
 *
 * @package App
 */
class AppRefreshDatabases implements FileLayoutAwareInterface
{
    use FileLayoutAwareTrait;

    /**
     * @var FilesystemTransferInterface
     */
    private $filesystemTransfer;
    /**
     * @var string
     */
    private $workingDir;
    /**
     * @var AppSetupRepository
     */
    private $repo;
    /**
     * @var DatabaseAdapter
     */
    private $databaseAdapter;
    /**
     * @var DatabaseImportExportAdapterInterface
     */
    private $importDatabaseAdapter;
    /**
     * @var LoggerInterface
     */
    private $logger;
    /**
     * @var FileLayoutHelper
     */
    private $fileLayoutHelper;
    /**
     * @var array
     */
    private $databases;
    /**
     * @var string
     */
    private $appName;
    /**
     * @var ShellCommandHelper
     */
    private $shellCommandHelper;


    /**
     * AppRefreshDatabase constructor.
     *
     * @param FilesystemTransferInterface $filesystemTransfer ,
     * @param string $workingDir                              ,
     * @param AppSetupRepository $repo
     * @param DatabaseAdapter $databaseAdapter
     * @param DatabaseImportExportAdapterInterface $importDatabaseAdapter
     * @param array $databases
     * @param string $appRoot
     * @param string $appName
     * @param string $fileLayout
     * @param string $branch
     * @param FileLayoutHelper|null $fileLayoutHelper
     * @param LoggerInterface|null $logger
     */
    public function __construct(
        FilesystemTransferInterface $filesystemTransfer,
        $workingDir,
        AppSetupRepository $repo,
        DatabaseAdapter $databaseAdapter,
        DatabaseImportExportAdapterInterface $importDatabaseAdapter,
        array $databases,
        $appRoot,
        $appName,
        $fileLayout,
        $branch,
        FileLayoutHelper $fileLayoutHelper = null,
        LoggerInterface $logger = null,
        ShellCommandHelper $shellCommandHelper = null
    ) {
        $this->filesystemTransfer = $filesystemTransfer;
        $this->workingDir = "$workingDir/.devops/app-refresh-databases";
        $this->repo = $repo;
        $this->databaseAdapter = $databaseAdapter;
        $this->importDatabaseAdapter = $importDatabaseAdapter;
        $this->databases = $databases;
        $this->appRoot = $appRoot;
        $this->appName = $appName;
        $this->fileLayout = $fileLayout;
        $this->branch = $branch;
        if (is_null($fileLayoutHelper)) {
            $fileLayoutHelper = new FileLayoutHelper();
        }

        $this->fileLayoutHelper = $fileLayoutHelper;
        if (is_null($logger)) {
            $logger = new NullHandler();
        }
        $this->logger = $logger;
        if (is_null($shellCommandHelper)) {
            $shellCommandHelper = new ShellCommandHelper($logger);
        }
        $this->shellCommandHelper = $shellCommandHelper;
        $fileLayoutHelper->loadFileLayoutPaths($this);
    }

    /**
     * @param bool $replace
     *
     * @throws Exception
     */
    public function refreshDatabases($replace = true)
    {
        $appName = $this->appName;
        if ($this->databases) {
            $this->logger->info('Refreshing databases...');
            foreach ($this->databases as $database => $databaseInfo) {
                $importDatabaseAdapter = $this->importDatabaseAdapter;
                $filename = "$database." . $importDatabaseAdapter::getFileExtension();

                if ('branch' == $this->fileLayout) {
                    $database .= '_' . $this->sanitizeDatabaseName($this->branch);
                }

                if ($this->databaseAdapter->databaseExists($database)) {
                    if (!$this->databaseAdapter->databaseIsEmpty($database)) {
                        if (!$replace) {
                            $this->logger->debug("Database \"$database\" exists and is not empty. Skipping.");
                            continue;
                        }
                        $this->logger->debug("Dropped and re-created database \"$database\".");
                        $this->databaseAdapter->dropDatabase($database);
                        $this->databaseAdapter->createDatabase($database);
                    } else {
                        $this->logger->debug("Using existing empty database \"$database\".");
                    }
                } else {
                    $this->logger->debug("Created database \"$database\".");
                    $this->databaseAdapter->createDatabase($database);
                }

                $this->logger->debug("Downloading database script \"$filename\".");
                $this->filesystemTransfer->copy($filename, $filename);

                $this->logger->debug("Running database script \"$filename\" on database \"$database\"...");
                $this->importDatabaseAdapter->importFromFile(
                    $database,
                    "{$this->workingDir}/$filename"
                );
                $this->filesystemTransfer->getDestinationFilesystem()->delete($filename);

                if (!empty($databaseInfo['post_import_scripts'])) {
                    $branchUrl = $branchDatabase = '';
                    if (FileLayout::FILE_LAYOUT_BRANCH == $this->fileLayout) {
                        $branchUrl = $this->sanitizeBranchForUrl($this->branch);
                        $branchDatabase = $this->sanitizeBranchForDatabase($this->branch);
                    }
                    foreach ($databaseInfo['post_import_scripts'] as $scriptFilename) {
                        $scriptContents = $this->repo->getFileContentsInHierarchy("database_scripts/$scriptFilename");
                        if (false === $scriptContents) {
                            throw new Exception(
                                "Database script \"$scriptFilename\" not found in the \"$appName\" app setup repository."
                            );
                        }
                        file_put_contents("{$this->workingDir}/$scriptFilename", $scriptContents);

                        $stringReplacements = [];
                        if (FileLayout::FILE_LAYOUT_BRANCH == $this->fileLayout) {
                            $stringReplacements['{{branch}}'] = $branchUrl;
                            $stringReplacements['{{branch_database}}'] = $branchDatabase;
                        }

                        $this->logger->debug(
                            "Running database script \"$scriptFilename\" on database \"$database\"..."
                        );
                        $this->databaseAdapter->runSqlFile(
                            $database,
                            "{$this->workingDir}/$scriptFilename",
                            $stringReplacements
                        );
                        unlink("{$this->workingDir}/$scriptFilename");
                    }
                }
            }
        } else {
            $this->logger->info('No databases to refresh.');
        }
    }

    /**
     * @param $name
     *
     * @return string
     */
    protected function sanitizeDatabaseName($name)
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

