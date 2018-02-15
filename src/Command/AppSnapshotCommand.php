<?php
/**
 * @author Kirk Madera <kmadera@robofirm.com>
 */

namespace ConductorAppOrchestration\Command;

use ConductorAppOrchestration\ApplicationConfig;
use ConductorAppOrchestration\ApplicationSnapshotTaker;
use ConductorCore\Filesystem\MountManager\MountManager;
use ConductorCore\MonologConsoleHandlerAwareTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class AppSnapshotCommand extends Command
{
    use MonologConsoleHandlerAwareTrait;

    /**
     * @var ApplicationConfig
     */
    private $applicationConfig;
    /**
     * @var ApplicationSnapshotTaker
     */
    private $applicationSnapshotTaker;
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
        ApplicationSnapshotTaker $applicationSnapshotTaker,
        MountManager $mountManager,
        LoggerInterface $logger = null,
        string $name = null
    ) {
        $this->applicationConfig = $applicationConfig;
        $this->applicationSnapshotTaker = $applicationSnapshotTaker;
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
        $this->setName('app:snapshot')
            ->setDescription('Creates application snapshot.')
            ->setHelp(
                "This command creates an application snapshot intended for testing purposes on lower environments."
            )
            ->addArgument('name', InputArgument::OPTIONAL, 'Snapshot name. Defaults to environment name.')
            ->addOption(
                'branch',
                null,
                InputArgument::OPTIONAL,
                'The branch to take snapshot from. Only relevant when using branch file layout.'
            )
            ->addOption(
                'filesystem',
                null,
                InputOption::VALUE_OPTIONAL,
                sprintf(
                    'The filesystem to push snapshot to. <comment>Configured filesystems: %s. [default: application default filesystem]</comment>.',
                    implode(', ', $filesystemPrefixes)
                )
            )
            ->addOption('no-databases', null, InputOption::VALUE_NONE, 'Do not include databases in snapshot.')
            ->addOption('no-assets', null, InputOption::VALUE_NONE, 'Do not include assets in snapshot.')
            ->addOption(
                'no-scrub',
                null,
                InputOption::VALUE_NONE,
                'Do not scrub the database or assets. Use this if you need to get an exact copy of production down to a test environment.'
            )
            ->addOption(
                'delete',
                null,
                InputOption::VALUE_NONE,
                'Delete any existing snapshot by this name first before pushing.'
            )
            ->addOption(
                'asset-batch-size',
                null,
                InputOption::VALUE_REQUIRED,
                'Batch size for asset sync.'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->applicationConfig->validate();
        $this->injectOutputIntoLogger($output, $this->logger);
        $this->applicationSnapshotTaker->setLogger($this->logger);

        $branch = $input->getOption('branch');
        $noAssets = $input->getOption('no-assets');
        $noDatabases = $input->getOption('no-databases');
        $noScrub = $input->getOption('no-scrub');
        $delete = $input->getOption('delete');
        $assetSyncConfig = [
            'batch_size' => $input->getOption('asset-batch-size'),
        ];

        $appName = $this->applicationConfig->getAppName();
        $snapshotName = $input->getArgument('name') ??
            $this->applicationConfig->getCurrentEnvironment() . (!$noScrub ? '-scrubbed' : '');
        $filesystem = $input->getOption('filesystem') ?? $this->applicationConfig->getDefaultFilesystem();
        $this->logger->info(
            "Creating snapshot \"$snapshotName\" from application \"$appName\" and pushing to filesystem \"$filesystem\"."
        );
        $this->applicationSnapshotTaker->takeSnapshot(
            $filesystem,
            $snapshotName,
            $branch,
            !$noDatabases,
            !$noAssets,
            !$noScrub,
            $delete,
            $assetSyncConfig
        );
        $this->logger->info("<info>Application \"$appName\" snapshot completed!</info>");
        return 0;
    }


}
