<?php
/**
 * @author Kirk Madera <kmadera@robofirm.com>
 */

namespace ConductorAppOrchestration\Command;

use ConductorAppOrchestration\ApplicationCodeInstaller;
use ConductorAppOrchestration\ApplicationConfig;
use ConductorCore\MonologConsoleHandlerAwareTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class AppInstallCodeCommand extends Command
{
    use MonologConsoleHandlerAwareTrait;

    /**
     * @var ApplicationConfig
     */
    private $applicationConfig;
    /**
     * @var ApplicationCodeInstaller
     */
    private $applicationCodeInstaller;

    /**
     * @var LoggerInterface
     */
    private $logger;


    public function __construct(
        ApplicationConfig $applicationConfig,
        ApplicationCodeInstaller $applicationCodeInstaller,
        LoggerInterface $logger = null,
        string $name = null
    ) {
        $this->applicationConfig = $applicationConfig;
        $this->applicationCodeInstaller = $applicationCodeInstaller;
        if (is_null($logger)) {
            $logger = new NullLogger();
        }
        $this->logger = $logger;
        parent::__construct($name);
    }

    protected function configure()
    {
        $this->setName('app:install:code')
            ->setDescription('Install application code.')
            ->setHelp("This command installs application code based on configuration.")
            ->addOption('branch', null, InputOption::VALUE_OPTIONAL, 'The code branch to install.')
            ->addOption(
                'replace',
                null,
                InputOption::VALUE_NONE,
                'If code is already installed, this does a git checkout and pulls the latest of the branch.'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->applicationConfig->validate();
        $this->injectOutputIntoLogger($output, $this->logger);
        $this->applicationCodeInstaller->setLogger($this->logger);
        $branch = $input->getOption('branch') ?? $this->applicationConfig->getDefaultBranch();
        $replace = $input->getOption('replace');

        $appName = $this->applicationConfig->getAppName();
        $this->logger->info("Installing application \"$appName\" code.");
        $this->applicationCodeInstaller->installCode($branch, $replace);
        $this->logger->info("<info>Application \"$appName\" code installed!</info>");
        return 0;
    }


}
