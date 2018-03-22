<?php
/**
 * @author Kirk Madera <kmadera@robofirm.com>
 */

namespace ConductorAppOrchestration;

use ConductorAppOrchestration\Config\ApplicationConfig;
use ConductorCore\Filesystem\MountManager\MountManager;
use ConductorCore\Shell\Adapter\LocalShellAdapter;
use ConductorCore\Shell\Adapter\ShellAdapterInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Class ApplicationAssetDeployer
 *
 * @package ConductorAppOrchestration
 */
class ApplicationAssetDeployer
{
    /**
     * @var ApplicationConfig
     */
    private $applicationConfig;
    /**
     * @var LocalShellAdapter
     */
    private $localShellAdapter;
    /**
     * @var FileLayoutHelper
     */
    private $fileLayoutHelper;
    /**
     * @var MountManager
     */
    protected $mountManager;
    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * ApplicationAssetDeployer constructor.
     *
     * @param ApplicationConfig    $applicationConfig
     * @param LocalShellAdapter    $localShellAdapter
     * @param FileLayoutHelper     $fileLayoutHelper
     * @param MountManager         $mountManager
     * @param LoggerInterface|null $logger
     */
    public function __construct(
        ApplicationConfig $applicationConfig,
        LocalShellAdapter $localShellAdapter,
        FileLayoutHelper $fileLayoutHelper,
        MountManager $mountManager,
        LoggerInterface $logger = null
    ) {
        $this->applicationConfig = $applicationConfig;
        $this->localShellAdapter = $localShellAdapter;
        $this->fileLayoutHelper = $fileLayoutHelper;
        $this->mountManager = $mountManager;
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
        $this->logger = $logger;
    }

    /**
     * @param string $sourceFilesystemPrefix
     * @param string $snapshotName
     * @param array  $syncOptions
     *
     * @throws Exception\RuntimeException if app skeleton has not yet been installed
     * @throws Exception\RuntimeException if there is an asset configuration error
     */
    public function deployAssets(
        string $sourceFilesystemPrefix,
        string $snapshotName,
        array $syncOptions = []
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
                "Application skeleton is not yet installed. Run app:install or app:install:skeleton first."
            );
        }

        $assetConfig = $application->getAssetConfig();
        if ($assetConfig->getPreInstallCommands()) {
            $this->logger->info('Running asset pre-installation commands.');
            $this->runCommands($assetConfig->getPreInstallCommands());
        }

        if ($assetConfig->getAssets()) {
            $this->logger->info('Installing assets');
            foreach ($assetConfig->getAssets() as $sourcePath => $asset) {
                if (empty($asset['ensure']) || empty($asset['location'])) {
                    throw new Exception\RuntimeException(
                        "Asset \"$sourcePath\" must have \"ensure\" and \"location\" properties set."
                    );
                }

                if (!empty($asset['pre_install_commands'])) {
                    $this->logger->info("Running asset \"$sourcePath\" pre-installation commands.");
                    $this->runCommands($asset['pre_install_commands']);
                }

                if (!empty($asset['local_path'])) {
                    $destinationPath = $asset['local_path'];
                } else {
                    $destinationPath = $sourcePath;
                }

                $pathPrefix = $this->fileLayoutHelper->resolvePathPrefix($application, $asset['location']);
                $sourcePath = "snapshots/$snapshotName/assets/{$asset['location']}/$sourcePath";
                if ($pathPrefix) {
                    $destinationPath = "$pathPrefix/$destinationPath";
                };
                $destinationPath = $application->getAppRoot() . '/' . $destinationPath;

                $this->mountManager->sync(
                    "$sourceFilesystemPrefix://$sourcePath",
                    "local://$destinationPath",
                    $syncOptions
                );

                if (!empty($asset['post_install_commands'])) {
                    $this->logger->info("Running asset \"$sourcePath\" post-installation commands.");
                    $this->runCommands($asset['post_install_commands']);
                }
            }
        } else {
            $this->logger->info('No assets specified in configuration.');
        }

        if ($assetConfig->getPostInstallCommands()) {
            $this->logger->info('Running asset post-installation commands.');
            $this->runCommands($assetConfig->getPostInstallCommands());
        }
    }

    /**
     * @param array             $commands
     */
    private function runCommands(array $commands): void
    {
        // Sort by priority
        uasort($commands, function ($a, $b) {
            $priorityA = $a['priority'] ?? 0;
            $priorityB = $b['priority'] ?? 0;
            return ($priorityA > $priorityB) ? -1 : 1;
        });

        foreach ($commands as $command) {
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
