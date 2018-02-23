<?php
/**
 * @author Kirk Madera <kmadera@robofirm.com>
 */

namespace ConductorAppOrchestration\Command;

use ConductorAppOrchestration\ApplicationConfig;
use ConductorAppOrchestration\ApplicationDatabaseInstaller;
use ConductorCore\Filesystem\MountManager\MountManager;
use ConductorCore\MonologConsoleHandlerAwareTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class AppInstallDatabasesCommand extends Command
{
    use MonologConsoleHandlerAwareTrait;

    /**
     * @var ApplicationConfig
     */
    private $applicationConfig;
    /**
     * @var ApplicationDatabaseInstaller
     */
    private $applicationDatabaseInstaller;
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
        ApplicationDatabaseInstaller $applicationDatabaseImportRefresher,
        MountManager $mountManager,
        LoggerInterface $logger = null,
        string $name = null
    ) {
        $this->applicationConfig = $applicationConfig;
        $this->applicationDatabaseInstaller = $applicationDatabaseImportRefresher;
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
        $this->setName('app:install:databases')
            ->setDescription('Install application databases.')
            ->setHelp(
                "This command installs application databases based on configuration."
            )
            ->addOption('branch', null, InputOption::VALUE_REQUIRED, 'The branch to install database into.')
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
                'replace',
                null,
                InputOption::VALUE_NONE,
                'Replace if already installed.'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->applicationConfig->validate();
        $this->injectOutputIntoLogger($output, $this->logger);
        $this->applicationDatabaseInstaller->setLogger($this->logger);
        $replace = $input->getOption('replace');

        $appName = $this->applicationConfig->getAppName();
        $this->logger->info("Installing application \"$appName\" databases.");
        $filesystem = $input->getOption('filesystem') ?? $this->applicationConfig->getDefaultFilesystem();
        $snapshot = $input->getOption('snapshot');
        $branch = $input->getOption('branch');
        $this->applicationDatabaseInstaller->installDatabases($filesystem, $snapshot, $branch, $replace);
        $this->logger->info("<info>Application \"$appName\" databases installed!</info>");
        return 0;
    }

}
