<?php
/**
 * @author Kirk Madera <kmadera@robofirm.com>
 */

namespace ConductorAppOrchestration\Snapshot;

use ConductorAppOrchestration\Config\ApplicationConfig;
use ConductorAppOrchestration\FileLayoutHelper;
use ConductorCore\Database\DatabaseImportExportAdapterManager;
use ConductorCore\Filesystem\MountManager\MountManager;
use ConductorAppOrchestration\Exception;
use ConductorCore\Shell\Adapter\ShellAdapterInterface;
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
     * @var ApplicationConfig
     */
    private $applicationConfig;
    /**
     * @var DatabaseImportExportAdapterManager
     */
    private $databaseImportExportAdapterManager;
    /**
     * @var MountManager
     */
    private $mountManager;
    /**
     * @var ShellAdapterInterface
     */
    private $localShellAdapter;
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
        ApplicationConfig $applicationConfig,
        DatabaseImportExportAdapterManager $databaseImportExportAdapterManager,
        MountManager $mountManager,
        ShellAdapterInterface $localShellAdapter,
        FileLayoutHelper $fileLayoutHelper,
        string $workingDirectory = '/tmp/.conductor-snapshot',
        LoggerInterface $logger = null
    ) {
        $this->applicationConfig = $applicationConfig;
        $this->databaseImportExportAdapterManager = $databaseImportExportAdapterManager;
        $this->mountManager = $mountManager;
        $this->localShellAdapter = $localShellAdapter;
        $this->fileLayoutHelper = $fileLayoutHelper;
        $this->workingDirectory = $workingDirectory;
        if (is_null($logger)) {
            $logger = new NullLogger();
        }
        $this->logger = $logger;
    }

    public function takeSnapshot(
        string $snapshotPlan,
        string $snapshotName,
        string $snapshotPath,
        string $branch = null,
        bool $includeDatabases = true,
        bool $includeAssets = true,
        bool $append = false,
        array $assetSyncConfig = []
    ) {
        $snapshotConfig = $this->applicationConfig->getSnapshotConfig();
        $plans = $snapshotConfig->getPlans();
        if (!isset($plans[$snapshotPlan])) {
            throw new Exception\DomainException("Snapshot plan \"$snapshotPlan\" not found in configuration.");
        }
        $snapshotPlan = $plans[$snapshotPlan];

        if (!empty($snapshotPlan['pre_snapshot_commands'])) {
            $this->logger->info('Running pre-snapshot commands.');
            $this->runCommands($snapshotPlan['pre_snapshot_commands']);
        }

        if (!$append) {
            $this->deleteExistingSnapshot($snapshotPath, $snapshotName);
        }

        $this->prepWorkingDirectory();

        if ($includeDatabases) {
            $this->uploadDatabases($snapshotPlan, $snapshotName, $snapshotPath, $branch);
        }

        if ($includeAssets) {
            $this->uploadAssets($snapshotPlan, $snapshotName, $snapshotPath, $assetSyncConfig);
        }

        if (!empty($snapshotPlan['post_snapshot_commands'])) {
            $this->logger->info('Running post-snapshot commands.');
            $this->runCommands($snapshotPlan['post_snapshot_commands']);
        }
    }

    /**
     * @param string $snapshotPath
     * @param string $snapshotName
     */
    private function deleteExistingSnapshot(string $snapshotPath, string $snapshotName): void
    {
        $this->logger->info('Deleting existing snapshot if exists.');
        $this->mountManager->deleteDir("$snapshotPath/$snapshotName");
    }

    /**
     * @param array $snapshotPlan
     * @param string $snapshotPath
     * @param string $snapshotName
     * @param string $branch
     * @param bool   $scrub
     */
    private function uploadDatabases(
        array $snapshotPlan,
        string $snapshotName,
        string $snapshotPath,
        string $branch = null
    ): void {

        if (!empty($snapshotPlan['databases'])) {
            foreach ($snapshotPlan['databases'] as $databaseName => $database) {
                $adapterName = $database['importexport_adapter'] ??
                    $this->applicationConfig->getDefaultDatabaseImportExportAdapter();
                $databaseImportExportAdapter = $this->databaseImportExportAdapterManager->getAdapter($adapterName);

                if (isset($databaseInfo['local_database_name'])) {
                    $localDatabaseName = $database['local_database_name'];
                } else {
                    if (FileLayoutAwareInterface::FILE_LAYOUT_BRANCH == $this->applicationConfig->getFileLayout()) {
                        $localDatabaseName = $databaseName . '_' . $this->sanitizeDatabaseName($branch);
                    } else {
                        $localDatabaseName = $databaseName;
                    }
                }

                // @todo Add ability to alter database in more ways than excluding data, E.g. Remove all but two stores.
                $exportOptions = [];
                if (!empty($database['excludes'])) {
                    $exportOptions['ignore_tables'] = $this->expandDatabaseTableGroups($database['excludes']);
                }

                $filename = $databaseImportExportAdapter->exportToFile(
                    $localDatabaseName,
                    $this->workingDirectory,
                    $exportOptions
                );
                $targetPath = "$snapshotPath/$snapshotName/databases/$databaseName."
                    . $databaseImportExportAdapter::getFileExtension();
                $this->mountManager->putFile("local://$filename", $targetPath);
            }
        }
    }

    /**
     * @param array $snapshotPlan
     * @param string $snapshotName
     * @param string $snapshotPath
     * @param array  $syncOptions
     */
    private function uploadAssets(
        array $snapshotPlan,
        string $snapshotName,
        string $snapshotPath,
        array $syncOptions
    ): void {
        if (!empty($snapshotPlan['assets'])) {
            foreach ($snapshotPlan['assets'] as $assetPath => $asset) {
                $pathPrefix = $this->fileLayoutHelper->resolvePathPrefix($this->applicationConfig, $asset['location']);
                $sourcePath = $asset['local_path'] ?? $assetPath;
                if ($pathPrefix) {
                    $sourcePath = "$pathPrefix/$sourcePath";
                }
                $sourcePath = $this->applicationConfig->getAppRoot() . '/' . $sourcePath;
                $targetPath = "$snapshotPath/$snapshotName/assets/{$asset['location']}/$assetPath";
                $this->logger->debug("Syncing asset \"$sourcePath\" to \"$targetPath\".");

                if (!empty($asset['excludes'])) {
                    $syncOptions['excludes'] = array_merge(
                        $syncOptions['excludes'] ?? [],
                        $this->expandAssetGroups($asset['excludes'])
                    );
                }

                if (!empty($asset['includes'])) {
                    $syncOptions['includes'] = array_merge(
                        $syncOptions['includes'] ?? [],
                        $this->expandAssetGroups($asset['includes'])
                    );
                }

                $this->mountManager->sync("local://{$sourcePath}", $targetPath, $syncOptions);
            }
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
     * @param array $assetGroups
     *
     * @return array
     * @throws Exception\DomainException if asset group not found in config
     */
    private function expandAssetGroups(array $assetGroups): array
    {
        $expandedAssetGroups = [];
        foreach ($assetGroups as $assetGroup) {
            if ('@' == substr($assetGroup, 0, 1)) {
                $group = substr($assetGroup, 1);
                $applicationAssetGroups = $this->applicationConfig->getSnapshotConfig()->getAssetGroups();
                if (!isset($applicationAssetGroups[$group])) {
                    $message = "Could not expand asset group \"$group\".";
                    $similarGroups = $this->findSimilarNames($group, array_keys($applicationAssetGroups));
                    if ($similarGroups) {
                        $message .= "\nDid you mean:\n" . implode("\n", $similarGroups) . "\n";
                    }
                    throw new Exception\DomainException($message);
                }

                $expandedAssetGroups = array_merge(
                    $expandedAssetGroups,
                    $this->expandAssetGroups($applicationAssetGroups[$group])
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
     * @throws Exception\DomainException if database table group not found in config
     */
    private function expandDatabaseTableGroups(array $databaseTableGroups): array
    {
        $expandedDatabaseTableGroups = [];
        foreach ($databaseTableGroups as $databaseTableGroup) {
            if ('@' == substr($databaseTableGroup, 0, 1)) {
                $group = substr($databaseTableGroup, 1);
                $applicationDatabaseTableGroups = $this->applicationConfig->getSnapshotConfig()->getDatabaseTableGroups();
                if (!isset($applicationDatabaseTableGroups[$group])) {
                    $message = "Could not expand database table group \"$group\".";
                    $similarGroups = $this->findSimilarNames($group, array_keys($applicationDatabaseTableGroups));
                    if ($similarGroups) {
                        $message .= "\nDid you mean:\n" . implode("\n", $similarGroups) . "\n";
                    }
                    throw new Exception\DomainException($message);
                }

                $expandedDatabaseTableGroups = array_merge(
                    $expandedDatabaseTableGroups,
                    $this->expandDatabaseTableGroups($applicationDatabaseTableGroups[$group])
                );

            } else {
                $expandedDatabaseTableGroups[] = $databaseTableGroup;
            }
        }

        sort($expandedDatabaseTableGroups);
        return $expandedDatabaseTableGroups;
    }

    /**
     * @throws Exception\RuntimeException if working dir is not writable
     */
    private function prepWorkingDirectory(): void
    {
        if (!file_exists($this->workingDirectory)) {
            mkdir($this->workingDirectory);
        }

        if (!is_dir($this->workingDirectory) && is_writable($this->workingDirectory)) {
            throw new Exception\RuntimeException("Working directory \"{$this->workingDirectory}\" is not writable.");
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

    /**
     * @param array $commands
     */
    private function runCommands(array $commands): void
    {
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
                $command['priority'] ?? ShellAdapterInterface::PRIORITY_NORMAL,
                $command['options'] ?? null
            );
            if (false !== strpos(trim($output), "\n")) {
                $output = "\n$output";
            }
            $this->logger->debug('Command output: ' . $output);
        }
    }
}
