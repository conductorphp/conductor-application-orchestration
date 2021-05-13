<?php
/**
 * @author Kirk Madera <kmadera@robofirm.com>
 */

namespace ConductorAppOrchestration\Console;

use ConductorAppOrchestration\Config\ApplicationConfig;
use ConductorAppOrchestration\Snapshot\ApplicationSnapshotTaker;
use ConductorCore\Filesystem\MountManager\MountManager;
use ConductorCore\MonologConsoleHandlerAwareTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use FilesystemIterator;

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
     * @param ApplicationConfig $applicationConfig
     * @param ApplicationSnapshotTaker $applicationSnapshotTaker
     * @param MountManager $mountManager
     * @param LoggerInterface|null $logger
     * @param string|null $name
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
                $this->applicationConfig->getSnapshotConfig()->getDefaultPlan()
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
            // @todo Allow for more granular setting of which databases/assets should be in the snapshot?
            ->addOption(
                'databases',
                null,
                InputOption::VALUE_NONE,
                'Include databases in snapshot. True if neither databases or assets specified.'
            )
            ->addOption(
                'assets',
                null,
                InputOption::VALUE_NONE,
                'Include assets in snapshot. True if neither databases or assets specified.'
            )
            ->addOption(
                'asset-batch-size',
                null,
                InputOption::VALUE_REQUIRED,
                'Batch size for asset sync.',
                20
            )
            ->addOption(
                'replace',
                null,
                InputOption::VALUE_NONE,
                'Replace snapshot, if exists.'
            )
            ->addOption(
                'working-dir',
                null,
                InputOption::VALUE_REQUIRED,
                'The local working directory to use during snapshot process.',
                '/tmp/.conductor/snapshot'
            )
            ->addOption(
                'force',
                null,
                InputOption::VALUE_NONE,
                'Bypass confirmation text when prompted.'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $workingDir = $input->getOption('working-dir');
        $forceSnapshot =  $input->getOption('force');

        // Confirm continue if working directory is not empty since it will be cleared
        if (!$forceSnapshot && is_dir($workingDir) && (new FilesystemIterator($workingDir))->valid()) {
            $helper = $this->getHelper('question');
            $question = new ConfirmationQuestion(
                sprintf(
                    '<comment>All contents of working directory "%s" will be deleted. Are you sure you want to continue? [y/N]</comment> ',
                    $workingDir
                ), false
            );

            if (!$helper->ask($input, $output, $question)) {
                return;
            }
        }

        $this->applicationConfig->validate();
        $this->injectOutputIntoLogger($output, $this->logger);
        $this->applicationSnapshotTaker->setLogger($this->logger);
        $this->applicationSnapshotTaker->setPlanPath($workingDir);

        $appName = $this->applicationConfig->getAppName();
        $snapshotName = $input->getArgument('snapshot-name') ?? $this->applicationConfig->getCurrentEnvironment();
        $snapshotPlan = $input->getOption('plan') ?? $this->applicationConfig->getSnapshotConfig()->getDefaultPlan();
        $snapshotPath = $input->getOption('snapshot-path');
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
            $includeDatabases,
            $includeAssets,
            $replace,
            $assetSyncConfig
        );
        $this->logger->info("<info>Application \"$appName\" snapshot completed!</info>");
        return 0;
    }


}
