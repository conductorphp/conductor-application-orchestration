<?php
/**
 * @author Kirk Madera <kmadera@robofirm.com>
 */

namespace DevopsToolAppOrchestration\Command;

use DevopsToolAppOrchestration\ApplicationAssetRefresher;
use DevopsToolAppOrchestration\ApplicationConfig;
use DevopsToolCore\Filesystem\MountManager\MountManager;
use DevopsToolCore\MonologConsoleHandlerAwareTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class AppRefreshAssetsCommand extends Command
{
    use MonologConsoleHandlerAwareTrait;

    /**
     * @var ApplicationConfig
     */
    private $applicationConfig;
    /**
     * @var ApplicationAssetRefresher
     */
    private $applicationAssetRefresher;
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
        ApplicationAssetRefresher $applicationAssetRefresher,
        MountManager $mountManager,
        LoggerInterface $logger = null,
        string $name = null
    ) {
        $this->applicationConfig = $applicationConfig;
        $this->applicationAssetRefresher = $applicationAssetRefresher;
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
        $this->setName('app:refresh-assets')
            ->setDescription('Refresh application assets.')
            ->setHelp(
                "This command refreshes application assets based on configuration."
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
        $this->injectOutputIntoLogger($output, $this->logger);
        $this->applicationAssetRefresher->setLogger($this->logger);
        $syncConfig = [
            'delete'     => $input->getOption('delete'),
            'batch_size' => $input->getOption('batch-size'),
        ];

        $appName = $this->applicationConfig->getAppName();
        $this->logger->info("Refreshing application \"$appName\" assets.");
        $filesystem = $input->getOption('filesystem') ?? $this->applicationConfig->getDefaultFilesystem();
        $snapshot = $input->getOption('snapshot');
        $this->applicationAssetRefresher->refreshAssets($filesystem, $snapshot, $syncConfig);
        $this->logger->info("<info>Application \"$appName\" assets refreshed!</info>");
        return 0;
    }

}
