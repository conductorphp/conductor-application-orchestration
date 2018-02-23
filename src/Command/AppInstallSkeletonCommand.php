<?php
/**
 * @author Kirk Madera <kmadera@robofirm.com>
 */

namespace ConductorAppOrchestration\Command;

use ConductorAppOrchestration\ApplicationConfig;
use ConductorAppOrchestration\ApplicationSkeletonInstaller;
use ConductorCore\MonologConsoleHandlerAwareTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class AppInstallSkeletonCommand extends Command
{
    use MonologConsoleHandlerAwareTrait;

    /**
     * @var ApplicationConfig
     */
    private $applicationConfig;
    /**
     * @var ApplicationSkeletonInstaller
     */
    private $applicationSkeletonInstaller;
    /**
     * @var LoggerInterface
     */
    private $logger;


    public function __construct(
        ApplicationConfig $applicationConfig,
        ApplicationSkeletonInstaller $applicationSkeletonInstaller,
        LoggerInterface $logger = null,
        string $name = null
    ) {
        $this->applicationConfig = $applicationConfig;
        $this->applicationSkeletonInstaller = $applicationSkeletonInstaller;
        if (is_null($logger)) {
            $logger = new NullLogger();
        }
        $this->logger = $logger;
        parent::__construct($name);
    }

    protected function configure()
    {
        $this->setName('app:install:skeleton')
            ->setDescription('Install application skeleton.')
            ->setHelp("This command installs an application skeleton based on configuration.")
            ->addOption('branch', null, InputOption::VALUE_OPTIONAL, 'The branch to install skeleton on.')
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
        $this->applicationSkeletonInstaller->setLogger($this->logger);
        $replace = $input->getOption('replace');

        $appName = $this->applicationConfig->getAppName();
        $this->logger->info("Installing application \"$appName\" skeleton.");
        $branch = $input->getOption('branch') ?? $this->applicationConfig->getDefaultBranch();

        $this->applicationSkeletonInstaller->prepareFileLayout();
        $this->applicationSkeletonInstaller->installAppFiles($branch, $replace);
        $this->logger->info("<info>Application \"$appName\" skeleton installed!</info>");
        return 0;
    }

}
