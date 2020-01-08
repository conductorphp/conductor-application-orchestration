<?php
/**
 * @author Kirk Madera <kmadera@robofirm.com>
 */

namespace ConductorAppOrchestration\Deploy;

use ConductorAppOrchestration\Exception;
use ConductorAppOrchestration\Config\ApplicationConfig;
use ConductorAppOrchestration\FileLayoutInterface;
use ConductorCore\Database\DatabaseAdapterManager;
use ConductorCore\Database\DatabaseImportExportAdapterInterface;
use ConductorCore\Database\DatabaseImportExportAdapterManager;
use ConductorCore\Filesystem\MountManager\MountManager;
use ConductorCore\Shell\Adapter\LocalShellAdapter;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Helper\HelperSet;
use Symfony\Component\Console\Helper\FormatterHelper;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\ConsoleOutput;

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

    /**
     * @pvar ArgvInput
     */
    protected $input;

    /**
     * @pvar ConsoleOutput
     */
    protected $output;

    /**
     * @pvar QuestionHelper
     */
    protected $questionHelper;

    public function __construct(
        ApplicationConfig $applicationConfig,
        MountManager $mountManager,
        DatabaseAdapterManager $databaseAdapterManager,
        DatabaseImportExportAdapterManager $databaseImportAdapterManager,
        LocalShellAdapter $localShellAdapter,
        LoggerInterface $logger = null,
        ArgvInput $input,
        ConsoleOutput $output,
        QuestionHelper $questionHelper
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
     * @param string      $snapshotPath
     * @param string      $snapshotName
     * @param array       $databases
     *
     * @throws Exception\RuntimeException if app skeleton has not yet been installed
     */
    public function deployDatabases(
        string $snapshotPath,
        string $snapshotName,
        array $databases,
        bool  $force = false
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
            $localDatabaseName = $database['local_database_name'] ?? $databaseName;

            if($databaseAdapter->databaseExists($localDatabaseName)){
                if($force)
                {
                    $this->logger->debug("Dropping database \"$localDatabaseName\".");
                    $databaseAdapter->dropDatabase($localDatabaseName);
                }else{
                    if($this->askDbQuestion($localDatabaseName)&&!$force)
                    {
                        $this->logger->debug("Dropping database \"$localDatabaseName\".");
                        $databaseAdapter->dropDatabase($localDatabaseName);
                    }else{
                        $this->logger->debug("User didn't confirm database dropping \"$localDatabaseName\".");
                        throw new Exception\RuntimeException('User didn\'t confirm database dropping');
                    }
                }
            }
            if (!$databaseAdapter->databaseExists($localDatabaseName)) {
                $this->logger->debug("Creating database \"$localDatabaseName\".");
                $databaseAdapter->createDatabase($localDatabaseName);
            }

            $workingDir = getcwd() . '/' . DatabaseImportExportAdapterInterface::DEFAULT_WORKING_DIR;
            $this->logger->debug("Downloading database script \"$filename\".");
            $this->mountManager->sync(
                "$snapshotPath/$snapshotName/databases/$filename",
                "local://$workingDir/$filename"
            );

            $databaseImportExportAdapter->importFromFile(
                "$workingDir/$filename",
                $localDatabaseName,
                [] // This command does not yet support any options
            );


            // @todo Deal with running environment scripts
            if (!empty($database['post_import_scripts'])) {
                foreach ($database['post_import_scripts'] as $scriptFilename) {
                    $scriptFilename = $this->findScript($scriptFilename);
                    $scriptFilename = $this->applyStringReplacements(
                        $scriptFilename
                    );

                    $this->logger->debug("Running post import script \"$scriptFilename\".");
                    $databaseAdapter->run(
                        file_get_contents($scriptFilename),
                        $localDatabaseName
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
     * @param                   $filename
     *
     * @return string Filename
     */
    private function applyStringReplacements(
        $filename
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

    /**
     * @param string $scriptFilename
     *
     * @return string
     */
    private function findScript(string $scriptFilename): string
    {
        // @todo Make this a setting in config instead. This module shouldn't make assumptions on where it's installed
        $conductorRoot = realpath(__DIR__ . '/../../../../..');
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

    /**
     * @inheritdoc
     */
    private function askDbQuestion($database)
    {
        $helperSet       = new HelperSet([new FormatterHelper()]);
        $this->questionHelper->setHelperSet($helperSet);
        $question        = new ConfirmationQuestion(
            sprintf(
                '<comment>Existing database "%s" will be dropped. Are you sure you want to continue? [y/N]</comment> ',
                $database
            ), false);

        return $this->questionHelper->ask($this->input,$this->output,$question);
    }
}