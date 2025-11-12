<?php

namespace ConductorAppOrchestration\Deploy;

use ConductorAppOrchestration\Config\ApplicationConfig;
use ConductorAppOrchestration\Deploy\PostImportScriptInterface;
use ConductorAppOrchestration\Exception;
use ConductorCore\Database\DatabaseAdapterManager;
use ConductorCore\Database\DatabaseImportExportAdapterInterface;
use ConductorCore\Database\DatabaseImportExportAdapterManager;
use ConductorCore\Filesystem\MountManager\MountManager;
use ConductorCore\Shell\Adapter\LocalShellAdapter;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Helper\FormatterHelper;
use Symfony\Component\Console\Helper\HelperSet;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Question\ConfirmationQuestion;

class ApplicationDatabaseDeployer implements LoggerAwareInterface
{
    private ApplicationConfig $applicationConfig;
    protected MountManager $mountManager;
    protected DatabaseImportExportAdapterManager $databaseImportAdapterManager;
    private DatabaseAdapterManager $databaseAdapterManager;
    private LocalShellAdapter $shellAdapter;
    protected LoggerInterface $logger;
    protected ArgvInput $input;
    protected ConsoleOutput $output;
    protected QuestionHelper $questionHelper;

    public function __construct(
        ApplicationConfig                  $applicationConfig,
        MountManager                       $mountManager,
        DatabaseAdapterManager             $databaseAdapterManager,
        DatabaseImportExportAdapterManager $databaseImportAdapterManager,
        LocalShellAdapter                  $localShellAdapter,
        LoggerInterface                    $logger = null,
        ArgvInput                          $input,
        ConsoleOutput                      $output,
        QuestionHelper                     $questionHelper
    ) {
        $this->applicationConfig = $applicationConfig;
        $this->mountManager = $mountManager;
        $this->databaseImportAdapterManager = $databaseImportAdapterManager;
        $this->databaseAdapterManager = $databaseAdapterManager;
        $this->shellAdapter = $localShellAdapter;
        $this->input = $input;
        $this->output = $output;
        $this->questionHelper = $questionHelper;

        if (is_null($logger)) {
            $logger = new NullLogger();
        }
        $this->logger = $logger;
    }

    /**
     * @throws Exception\RuntimeException if app skeleton has not yet been installed
     */
    public function deployDatabases(
        string $snapshotPath,
        string $snapshotName,
        array  $databases,
        bool   $force = false
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
            $databaseAlias = $database['alias'] ?? $databaseName;

            if ($databaseAdapter->databaseExists($databaseAlias)) {
                if (!$force && !$this->confirmDatabaseDrop($databaseAlias)) {
                    throw new Exception\RuntimeException('Aborted database deployment because user did not confirm dropping existing database.');
                }

                $this->logger->debug("Dropping database \"$databaseAlias\".");
                $databaseAdapter->dropDatabase($databaseAlias);
            }

            $this->logger->debug("Creating database \"$databaseAlias\".");
            $databaseAdapter->createDatabase($databaseAlias);

            $workingDir = getcwd() . '/' . DatabaseImportExportAdapterInterface::DEFAULT_WORKING_DIR;
            $this->logger->debug("Downloading database script \"$filename\".");
            $this->mountManager->sync(
                "$snapshotPath/$snapshotName/databases/$filename",
                "local://$workingDir/$filename"
            );

            $databaseImportExportAdapter->importFromFile(
                "$workingDir/$filename",
                $databaseAlias,
                [] // This command does not yet support any options
            );


            // @todo Deal with running environment scripts
            if (!empty($database['post_import_scripts'])) {
                foreach ($database['post_import_scripts'] as $scriptIdentifier) {
                    $script = null;
                    $scriptName = $scriptIdentifier;

                    // Check if this is a class name (contains backslash)
                    if (str_contains($scriptIdentifier, '\\')) {
                        $this->logger->debug("Instantiating post-import script class \"$scriptIdentifier\".");

                        if (!class_exists($scriptIdentifier)) {
                            throw new Exception\RuntimeException(
                                "Post-import script class \"$scriptIdentifier\" does not exist."
                            );
                        }

                        $script = new $scriptIdentifier();

                        if (!($script instanceof PostImportScriptInterface)) {
                            throw new Exception\RuntimeException(
                                "Post-import script class \"$scriptIdentifier\" must implement " .
                                PostImportScriptInterface::class
                            );
                        }
                    } else {
                        // It's a file path
                        $scriptFilename = $this->findScript($scriptIdentifier);

                        // Check if this is a PHP script that implements PostImportScriptInterface
                        if (str_ends_with($scriptFilename, '.php')) {
                            $this->logger->debug("Executing PHP script \"$scriptFilename\".");

                            // Load the script and check if it returns an object implementing the interface
                            $script = include $scriptFilename;

                            if (!($script instanceof PostImportScriptInterface)) {
                                throw new Exception\RuntimeException(
                                    "PHP post-import script \"$scriptFilename\" must return an object implementing " .
                                    PostImportScriptInterface::class
                                );
                            }
                        } else {
                            // Plain SQL file
                            $scriptFilename = $this->applyStringReplacements(
                                $scriptFilename
                            );
                            $sql = file_get_contents($scriptFilename);
                        }

                        $scriptName = $scriptFilename;
                    }

                    // If we have a script object (class or PHP file), execute it
                    if ($script !== null) {
                        $config = $this->applicationConfig->toArray();
                        $sql = $script->execute(
                            $databaseAdapter,
                            $databaseAlias,
                            $config,
                            $this->logger
                        );
                    }

                    $sql = trim($sql);
                    if ($sql) {
                        $this->logger->debug("Running SQL from post import script \"$scriptName\".");
                        $databaseAdapter->run(
                            $sql,
                            $databaseAlias
                        );
                    } else {
                        $this->logger->debug("Skipping post import script \"$scriptName\" because it generated no SQL.");
                    }
                }
            }
        }
    }

    private function applyStringReplacements(
        string $filename
    ): string {
        $stringReplacements = [];
        if ($stringReplacements) {
            $contents = file_get_contents($filename);
            foreach ($stringReplacements as $search => $replace) {
                $contents = str_replace($search, $replace, $contents);
            }
            // @todo Use of tmpfile ok here? These will be small, managed files. I don't foresee hitting a disk space
            //       issue here
            $tempFile = tmpfile();
            fwrite($tempFile, $contents);
            $metaData = stream_get_meta_data($tempFile);
            $filename = $metaData["uri"];
        }
        return $filename;
    }

    private function findScript(string $scriptFilename): string
    {
        // @todo Make this a setting in config instead. This module shouldn't make assumptions on where it's installed
        $conductorRoot = dirname(__DIR__, 5);
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

    private function confirmDatabaseDrop($database)
    {
        $helperSet = new HelperSet([new FormatterHelper()]);
        $this->questionHelper->setHelperSet($helperSet);
        $question = new ConfirmationQuestion(
            sprintf(
                '<error>Existing database "%s" will be dropped. Are you sure you want to continue? [y/N]</error> ',
                $database
            ), false);

        return $this->questionHelper->ask($this->input, $this->output, $question);
    }
}
