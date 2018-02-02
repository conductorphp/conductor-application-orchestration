<?php
/**
 * @author Kirk Madera <kmadera@robofirm.com>
 */

namespace DevopsToolAppOrchestration\Command;

use DevopsToolAppOrchestration\ApplicationDatabaseRefresher;
use DevopsToolCore\Database\DatabaseImportExportAdapterManager;
use DevopsToolCore\Filesystem\MountManager\MountManager;
use DevopsToolCore\MonologConsoleHandlerAwareTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class AppRefreshDatabasesCommand extends AbstractCommand
{
    use MonologConsoleHandlerAwareTrait;

    /**
     * @var ApplicationDatabaseRefresher
     */
    private $applicationDatabaseRefresher;
    /**
     * @var MountManager
     */
    private $mountManager;
    /**
     * @var LoggerInterface
     */
    private $logger;


    public function __construct(
        ApplicationDatabaseRefresher $applicationDatabaseImportRefresher,
        MountManager $mountManager,
        LoggerInterface $logger = null,
        string $name = null
    ) {
        $this->applicationDatabaseRefresher = $applicationDatabaseImportRefresher;
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
        $this->setName('app:refresh-databases')
            ->setDescription('Refresh application databases.')
            ->setHelp(
                "This command refreshes application databases based on configuration."
            )
            ->addOption(
                'app',
                null,
                InputOption::VALUE_OPTIONAL,
                'Application code.May be left empty if only one app exists in configuration or specifying --all.'
            )
            ->addOption('all', null, InputOption::VALUE_NONE, 'Refresh assets for all apps in configuration')
            ->addOption('branch', null, InputOption::VALUE_REQUIRED, 'The branch to install database into.')
            ->addOption(
                'filesystem',
                null,
                InputOption::VALUE_OPTIONAL,
                sprintf('The filesystem to pull snapshot from. <comment>Configured filesystems: %s. [default: application default filesystem]</comment>.',
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
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->injectOutputIntoLogger($output, $this->logger);
        $this->applicationDatabaseRefresher->setLogger($this->logger);
        $applications = $this->getApplications($input);

        foreach ($applications as $code => $application) {
            $this->logger->info("Refreshing application \"$code\" databases...");
            $filesystem = $input->getOption('filesystem') ?? $application->getDefaultFilesystem();
            $snapshot = $input->getOption('snapshot');
            $branch = $input->getOption('branch');
            $this->applicationDatabaseRefresher->refreshDatabases($application, $filesystem, $snapshot, $branch);
            $this->logger->info("<info>Application \"$code\" databases refreshed!</info>");
        }
        return 0;
    }

}
