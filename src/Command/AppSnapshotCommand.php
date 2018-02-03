<?php
/**
 * @author Kirk Madera <kmadera@robofirm.com>
 */

namespace DevopsToolAppOrchestration\Command;

use DevopsToolAppOrchestration\ApplicationSnapshotTaker;
use DevopsToolAppOrchestration\Exception;
use DevopsToolCore\Filesystem\MountManager\MountManager;
use DevopsToolCore\MonologConsoleHandlerAwareTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class AppSnapshotCommand extends AbstractCommand
{
    use MonologConsoleHandlerAwareTrait;

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
        ApplicationSnapshotTaker $applicationSnapshotTaker,
        MountManager $mountManager,
        LoggerInterface $logger = null,
        string $name = null
    ) {
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
                'app',
                null,
                InputOption::VALUE_OPTIONAL,
                'Application code if you want to pull repo_url and environment from configuration'
            )
            ->addOption('all', null, InputOption::VALUE_NONE, 'Create a snapshot for all apps in configuration.')
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
        $this->injectOutputIntoLogger($output, $this->logger);
        $this->applicationSnapshotTaker->setLogger($this->logger);
        $applications = $this->getApplications($input);

        $branch = $input->getOption('branch');
        $noAssets = $input->getOption('no-assets');
        $noDatabases = $input->getOption('no-databases');
        $noScrub = $input->getOption('no-scrub');
        $delete = $input->getOption('delete');
        $assetSyncConfig = [
            'batch_size' => $input->getOption('asset-batch-size'),
        ];

        foreach ($applications as $code => $application) {
            $snapshotName = $input->getArgument('name') ??
            $application->getCurrentEnvironment() . (!$noScrub ? '-scrubbed' : '');
            $filesystem = $input->getOption('filesystem') ?? $application->getDefaultFilesystem();
            $this->logger->info("Creating snapshot \"$snapshotName\" from application \"$code\" and pushing to filesystem \"$filesystem\".");
            $this->applicationSnapshotTaker->takeSnapshot(
                $application,
                $filesystem,
                $snapshotName,
                $branch,
                !$noDatabases,
                !$noAssets,
                !$noScrub,
                $delete,
                $assetSyncConfig
            );
            $this->logger->info("<info>Application \"$code\" snapshot completed!</info>");
        }
        return 0;
    }


}
