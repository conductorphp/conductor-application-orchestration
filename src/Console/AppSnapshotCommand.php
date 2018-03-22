<?php
/**
 * @author Kirk Madera <kmadera@robofirm.com>
 */

namespace ConductorAppOrchestration\Console;

use ConductorAppOrchestration\Snapshot\ApplicationSnapshotTaker;
use ConductorAppOrchestration\Config\ApplicationConfig;
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

    /**
     * AppSnapshotCommand constructor.
     *
     * @param ApplicationConfig        $applicationConfig
     * @param ApplicationSnapshotTaker $applicationSnapshotTaker
     * @param MountManager             $mountManager
     * @param LoggerInterface|null     $logger
     * @param string|null              $name
     */
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
            ->setDescription('Create application asset/database snapshot.')
            ->setHelp(
                "This command creates an application snapshot of databases and assets."
            )
            // @todo Remove default snapshot-name to force people to specify a snapshot name?
            ->addArgument(
                'snapshot-name',
                InputArgument::OPTIONAL,
                'Snapshot name.',
                $this->applicationConfig->getCurrentEnvironment()
            )
            ->addOption(
                'plan',
                null,
                InputOption::VALUE_REQUIRED,
                'Snapshot plan to run.',
                $this->applicationConfig->getDefaultSnapshotPlan()
            )
            ->addOption(
                'snapshot-path',
                null,
                InputOption::VALUE_OPTIONAL,
                sprintf(
                    'The filesystem to push the snapshot to. <comment>[allowed: %s]</comment>,',
                    implode(', ', $filesystemPrefixes)
                ),
                $this->applicationConfig->getDefaultFilesystem() . '://snapshots'
            )
            ->addOption(
                'branch',
                null,
                InputOption::VALUE_REQUIRED,
                'Branch to snapshot db from. Only relevant with branch file layout.'
            )
            // @todo Allow for more granular setting of which databases/assets should be in the snapshot?
            ->addOption('databases', null, InputOption::VALUE_NONE, 'Include databases in snapshot. True if neither databases or assets specified.')
            ->addOption('assets', null, InputOption::VALUE_NONE, 'Include assets in snapshot. True if neither databases or assets specified.')
            ->addOption(
                'asset-batch-size',
                null,
                InputOption::VALUE_REQUIRED,
                'Batch size for asset sync.',
                100
            )
            ->addOption(
                'replace',
                null,
                InputOption::VALUE_NONE,
                'Replace snapshot, if exists.'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->applicationConfig->validate();
        $this->injectOutputIntoLogger($output, $this->logger);
        $this->applicationSnapshotTaker->setLogger($this->logger);

        $appName = $this->applicationConfig->getAppName();
        $snapshotName = $input->getArgument('snapshot-name') ?? $this->applicationConfig->getCurrentEnvironment();
        $snapshotPlan = $input->getOption('plan') ?? $this->applicationConfig->getDefaultSnapshotPlan();
        $snapshotPath = $input->getOption('snapshot-path');
        $branch = $input->getOption('branch');
        $includeDatabases = $input->getOption('databases');
        $includeAssets = $input->getOption('assets');
        if (!($includeDatabases || $includeAssets)) {
            $includeDatabases = $includeAssets = true;
        }
        $assetSyncConfig = [
            'batch_size' => $input->getOption('asset-batch-size'),
        ];
        $replace = $input->getOption('replace');

        $this->logger->info(
            "Creating snapshot \"$snapshotName\" and saving to \"$snapshotPath/$snapshotName\"."
        );
        $this->applicationSnapshotTaker->takeSnapshot(
            $snapshotPlan,
            $snapshotName,
            $snapshotPath,
            $branch,
            $includeDatabases,
            $includeAssets,
            $replace,
            $assetSyncConfig
        );
        $this->logger->info("<info>Application \"$appName\" snapshot completed!</info>");
        return 0;
    }


}
