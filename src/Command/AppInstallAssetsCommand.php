<?php
/**
 * @author Kirk Madera <kmadera@robofirm.com>
 */

namespace ConductorAppOrchestration\Command;

use ConductorAppOrchestration\ApplicationAssetInstaller;
use ConductorAppOrchestration\ApplicationConfig;
use ConductorCore\Filesystem\MountManager\MountManager;
use ConductorCore\MonologConsoleHandlerAwareTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class AppInstallAssetsCommand extends Command
{
    use MonologConsoleHandlerAwareTrait;

    /**
     * @var ApplicationConfig
     */
    private $applicationConfig;
    /**
     * @var ApplicationAssetInstaller
     */
    private $applicationAssetInstaller;
    /**
     * @var MountManager
     */
    private $mountManager;
    /**
     * @var LoggerInterface
     */
    private $logger;


    public function __construct(
        ApplicationConfig $applicationConfig,
        ApplicationAssetInstaller $applicationAssetInstaller,
        MountManager $mountManager,
        LoggerInterface $logger = null,
        string $name = null
    ) {
        $this->applicationConfig = $applicationConfig;
        $this->applicationAssetInstaller = $applicationAssetInstaller;
        $this->mountManager = $mountManager;
        if (is_null($logger)) {
            $logger = new NullLogger();
        }
        $this->logger = $logger;
        parent::__construct($name);
    }

    protected function configure()
    {
        $filesystemPrefixes = $this->mountManager->getFilesystemPrefixes();
        $this->setName('app:install:assets')
            ->setDescription('Install application assets.')
            ->setHelp(
                "This command installs application assets based on configuration."
            )
            ->addOption(
                'filesystem',
                null,
                InputOption::VALUE_OPTIONAL,
                sprintf(
                    'The filesystem to pull snapshot from. <comment>Configured filesystems: %s. [default: application default filesystem]</comment>.',
                    implode(', ', $filesystemPrefixes)
                )
            )
            ->addOption(
                'snapshot',
                null,
                InputOption::VALUE_REQUIRED,
                'The snapshot to pull assets from.',
                'production-scrubbed'
            )
            ->addOption(
                'delete',
                null,
                InputOption::VALUE_NONE,
                'Delete local assets which are not present in snapshot.'
            )
            ->addOption(
                'batch-size',
                null,
                InputOption::VALUE_REQUIRED,
                'Batch size for asset sync.'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->applicationConfig->validate();
        $this->injectOutputIntoLogger($output, $this->logger);
        $this->applicationAssetInstaller->setLogger($this->logger);
        $syncConfig = [
            'delete'     => $input->getOption('delete'),
            'batch_size' => $input->getOption('batch-size'),
        ];

        $appName = $this->applicationConfig->getAppName();
        $this->logger->info("Installing application \"$appName\" assets.");
        $filesystem = $input->getOption('filesystem') ?? $this->applicationConfig->getDefaultFilesystem();
        $snapshot = $input->getOption('snapshot');
        $this->applicationAssetInstaller->installAssets($filesystem, $snapshot, $syncConfig);
        $this->logger->info("<info>Application \"$appName\" assets installed!</info>");
        return 0;
    }

}
