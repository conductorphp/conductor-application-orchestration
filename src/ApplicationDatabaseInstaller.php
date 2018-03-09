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
use ConductorCore\Shell\Adapter\ShellAdapterInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Class ApplicationDatabaseInstaller
 *
 * @package ConductorAppOrchestration
 */
class ApplicationDatabaseInstaller
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
    private $localShellAdapter;
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
        $this->localShellAdapter = $localShellAdapter;
        if (is_null($logger)) {
            $logger = new NullLogger();
        }
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
    public function installDatabases(
        string $sourceFilesystemPrefix,
        string $snapshotName,
        string $branch = null,
        bool $replace = false
    ): void {
        $application = $this->applicationConfig;
        $databaseConfig = $this->applicationConfig->getDatabaseConfig();

        if ($databaseConfig->getPreInstallCommands()) {
            $this->logger->info('Running database pre-installation commands.');
            $this->runCommands($databaseConfig->getPreInstallCommands());
        }

        if ($databaseConfig->getDatabases()) {
            $this->logger->info('Installing databases');
            foreach ($databaseConfig->getDatabases() as $databaseName => $database) {

                // @todo Only run if actually replacing the db or db doesn't exist
                if (!empty($database['pre_install_commands'])) {
                    $this->logger->info("Running database \"$databaseName\" pre-installation commands.");
                    $this->runCommands($database['pre_install_commands']);
                }

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

                if (!empty($database['post_install_commands'])) {
                    $this->logger->info("Running database \"$databaseName\" post-installation commands.");
                    $this->runCommands($database['post_install_commands']);
                }
            }
        } else {
            $this->logger->info('No databases specified in configuration.');
        }

        if ($databaseConfig->getPostInstallCommands()) {
            $this->logger->info('Running database post-installation commands.');
            $this->runCommands($databaseConfig->getPostInstallCommands());
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

    /**
     * @param array $commands
     */
    private function runCommands(array $commands): void
    {
        // Sort by priority
        uasort($commands, function ($a, $b) {
            $priorityA = $a['priority'] ?? 0;
            $priorityB = $b['priority'] ?? 0;
            return ($priorityA > $priorityB) ? -1 : 1;
        });

        foreach ($commands as $name => $command) {
            $this->logger->debug("Running command \"$name\".");
            if (is_string($command)) {
                $command = [
                    'command' => $command,
                ];
            }

            if (is_callable($command['command'])) {
                call_user_func_array($command['command'], $command['arguments'] ?? []);
                continue;
            }

            $output = $this->localShellAdapter->runShellCommand(
                $command['command'],
                $command['working_directory'] ?? $this->applicationConfig->getCodePath(),
                $command['environment_variables'] ?? null,
                $command['run_priority'] ?? ShellAdapterInterface::PRIORITY_NORMAL,
                $command['options'] ?? null
            );
            if (false !== strpos(trim($output), "\n")) {
                $output = "\n$output";
            }
            $this->logger->debug('Command output: ' . $output);
        }
    }
}
