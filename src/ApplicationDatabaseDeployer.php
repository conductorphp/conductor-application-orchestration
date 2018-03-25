<?php
/**
 * @author Kirk Madera <kmadera@robofirm.com>
 */

namespace ConductorAppOrchestration;

use ConductorAppOrchestration\Config\ApplicationConfig;
use ConductorCore\Database\DatabaseAdapterManager;
use ConductorCore\Database\DatabaseImportExportAdapterInterface;
use ConductorCore\Database\DatabaseImportExportAdapterManager;
use ConductorCore\Filesystem\MountManager\MountManager;
use ConductorCore\Shell\Adapter\LocalShellAdapter;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Class ApplicationDatabaseDeployer
 *
 * @package ConductorAppOrchestration
 */
class ApplicationDatabaseDeployer implements LoggerAwareInterface
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
     * @var LocalShellAdapter
     */
    private $shellAdapter;
    /**
     * @var LoggerInterface
     */
    protected $logger;

    public function __construct(
        ApplicationConfig $applicationConfig,
        MountManager $mountManager,
        DatabaseAdapterManager $databaseAdapterManager,
        DatabaseImportExportAdapterManager $databaseImportAdapterManager,
        LocalShellAdapter $localShellAdapter,
        LoggerInterface $logger = null
    ) {
        $this->applicationConfig = $applicationConfig;
        $this->mountManager = $mountManager;
        $this->databaseImportAdapterManager = $databaseImportAdapterManager;
        $this->databaseAdapterManager = $databaseAdapterManager;
        $this->shellAdapter = $localShellAdapter;
        if (is_null($logger)) {
            $logger = new NullLogger();
        }
        $this->logger = $logger;
    }

    /**
     * @param string      $snapshotPath
     * @param string      $snapshotName
     * @param array       $databases
     * @param string|null $branch
     *
     * @throws Exception\RuntimeException if app skeleton has not yet been installed
     */
    public function deployDatabases(
        string $snapshotPath,
        string $snapshotName,
        array $databases,
        string $branch = null
    ): void {

        if (!$databases) {
            throw new Exception\RuntimeException('No database given for deployment.');
        }

        $application = $this->applicationConfig;
        $this->logger->info('Installing databases');
        foreach ($databases as $databaseName => $database) {

            $adapterName = $database['adapter'] ?? $application->getDefaultDatabaseAdapter();
            $databaseAdapter = $this->databaseAdapterManager->getAdapter($adapterName);
            $adapterName = $database['importexport_adapter'] ??
                $application->getDefaultDatabaseImportExportAdapter();
            $databaseImportExportAdapter = $this->databaseImportAdapterManager->getAdapter($adapterName);

            $filename = "$databaseName." . $databaseImportExportAdapter::getFileExtension();
            if ('branch' == $application->getFileLayoutStrategy()) {
                $databaseName .= '_' . $this->sanitizeDatabaseName($branch);
            }

            if ($databaseAdapter->databaseExists($databaseName)) {
                if (!$databaseAdapter->databaseIsEmpty($databaseName)) {
                    $this->logger->notice("Database \"$databaseName\" exists and is not empty. Skipped.");
                    continue;
                }
            } else {
                $this->logger->debug("Created database \"$databaseName\".");
                $databaseAdapter->createDatabase($databaseName);
            }

            $workingDir = getcwd() . '/' . DatabaseImportExportAdapterInterface::DEFAULT_WORKING_DIR;
            $this->logger->debug("Downloading database script \"$filename\".");
            $this->mountManager->sync(
                "$snapshotPath/$snapshotName/databases/$filename",
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
                if (FileLayoutInterface::STRATEGY_BRANCH == $application->getFileLayoutStrategy()) {
                    $branchUrl = $this->sanitizeBranchForUrl($branch);
                    $branchDatabase = $this->sanitizeBranchForDatabase($branch);
                }

                foreach ($database['post_import_scripts'] as $scriptFilename) {
                    $scriptFilename = $this->findScript($scriptFilename);
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
        if (FileLayoutInterface::STRATEGY_BRANCH == $this->applicationConfig->getFileLayoutStrategy()) {
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

    /**
     * @param string $scriptFilename
     *
     * @return string
     */
    private function findScript(string $scriptFilename): string
    {
        $conductorRoot = realpath(__DIR__ . '/../../../..');
        $configRoot = "$conductorRoot/config/app";
        $environment = $this->applicationConfig->getCurrentEnvironment();

        $environmentPath = "$configRoot/environments/$environment/files";
        if (file_exists("$environmentPath/$scriptFilename")) {
            return "$environmentPath/$scriptFilename";
        }

        $globalPath = "$configRoot/files";
        if (file_exists("$globalPath/$scriptFilename")) {
            return "$globalPath/$scriptFilename";
        }

        throw new Exception\RuntimeException(
            "Script \"$scriptFilename\" not found in \"$environmentPath\" or \"$globalPath\"."
        );
    }

    /**
     * @inheritdoc
     */
    public function setLogger(LoggerInterface $logger): void
    {
        $this->mountManager->setLogger($logger);
        $this->databaseImportAdapterManager->setLogger($logger);
        $this->databaseAdapterManager->setLogger($logger);
        if ($this->shellAdapter instanceof LoggerAwareInterface) {
            $this->shellAdapter->setLogger($logger);
        }
        $this->logger = $logger;
    }
}
