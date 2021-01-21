<?php
/**
 * @author Kirk Madera <kirk.madera@rmgmedia.com>
 */

namespace ConductorAppOrchestration\Console;

use ConductorAppOrchestration\Config\ApplicationConfig;
use ConductorAppOrchestration\Maintenance\MaintenanceStrategyInterface;
use ConductorCore\MonologConsoleHandlerAwareTrait;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class AppMaintenanceCommand extends Command
{
    use MonologConsoleHandlerAwareTrait;

    /**
     * @var ApplicationConfig
     */
    private $applicationConfig;
    /**
     * @var MaintenanceStrategyInterface
     */
    private $maintenanceStrategy;
    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * AppMaintenanceCommand constructor.
     *
     * @param ApplicationConfig            $applicationConfig
     * @param MaintenanceStrategyInterface $maintenanceStrategy
     * @param LoggerInterface|null         $logger
     * @param string|null                  $name
     */
    public function __construct(
        ApplicationConfig $applicationConfig,
        MaintenanceStrategyInterface $maintenanceStrategy,
        LoggerInterface $logger = null,
        string $name = null
    ) {
        $this->applicationConfig = $applicationConfig;
        $this->maintenanceStrategy = $maintenanceStrategy;
        if (is_null($logger)) {
            $logger = new NullLogger();
        }
        $this->logger = $logger;
        parent::__construct($name);
    }

    protected function configure()
    {
        $this->setName('app:maintenance')
            ->setDescription('Manage maintenance mode.')
            ->setHelp(
                "This command manages maintenance mode for the application."
            )
            ->addArgument('action', InputArgument::OPTIONAL, 'Action to take. May use: status, enable, or disable');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->applicationConfig->validate();
        $this->injectOutputIntoLogger($output, $this->logger);
        if ($this->maintenanceStrategy instanceof LoggerAwareInterface) {
            $this->maintenanceStrategy->setLogger($this->logger);
        }

        $action = $input->getArgument('action') ?? 'status';

        $appName = $this->applicationConfig->getAppName();
        if ('enable' == $action) {
            $output->writeln("Enabling maintenance mode for app \"$appName\".");
            $this->maintenanceStrategy->enable();
            $output->writeln("Maintenance mode <info>enabled</info> for app \"$appName\".");
        } elseif ('disable' == $action) {
            $output->writeln("Disabling maintenance mode for app \"$appName\".");
            $this->maintenanceStrategy->disable();
            $output->writeln("Maintenance mode <error>disabled</error> for app \"$appName\".");
        } else {
            $output->writeln("Checking if maintenance mode is enabled for app \"$appName\".");
            $status = $this->maintenanceStrategy->isEnabled();
            $statusText = $status ? 'enabled' : 'disabled';
            $output->writeln("Maintenance mode is $statusText for app \"$appName\".");
        }
        return 0;
    }

}
