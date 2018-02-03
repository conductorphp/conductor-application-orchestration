<?php
/**
 * @author Kirk Madera <kmadera@robofirm.com>
 */

namespace DevopsToolAppOrchestration\Command;

use DevopsToolAppOrchestration\MaintenanceStrategy\MaintenanceStrategyInterface;
use DevopsToolCore\MonologConsoleHandlerAwareTrait;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class AppMaintenanceCommand extends AbstractCommand
{
    use MonologConsoleHandlerAwareTrait;

    /**
     * @var MaintenanceStrategyInterface
     */
    private $maintenanceStrategy;
    /**
     * @var LoggerInterface
     */
    private $logger;


    public function __construct(
        MaintenanceStrategyInterface $maintenanceStrategy,
        LoggerInterface $logger = null,
        string $name = null
    ) {
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
                "This command can check if maintenance mode is enabled or enable/disable maintenance mode for an application."
            )
            ->addArgument('action', InputArgument::REQUIRED, 'Action to take. May use: status, enable, or disable')
            ->addOption(
                'app',
                null,
                InputOption::VALUE_OPTIONAL,
                'Application code if you want to pull repo_url and environment from configuration'
            )
            ->addOption(
                'branch',
                null,
                InputArgument::OPTIONAL,
                'The branch instance to manage maintenance state for. Only relevant when using the \"branch\" file layout.'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->injectOutputIntoLogger($output, $this->logger);
        if ($this->maintenanceStrategy instanceof LoggerAwareInterface) {
            $this->maintenanceStrategy->setLogger($this->logger);
        }
        $applications = $this->getApplications($input);

        $action = $input->getArgument('action');
        $branch = $input->getOption('branch');

        foreach ($applications as $code => $application) {
            $appName = $application->getAppName();
            if ('enable' == $action) {
                $output->writeln("Enabling maintenance mode for app \"$appName\".");
                $this->maintenanceStrategy->enable($application, $branch);
                $output->writeln("Maintenance mode <info>enabled</info> for app \"$appName\".");
            } elseif ('disable' == $action) {
                $output->writeln("Disabling maintenance mode for app \"$appName\".");
                $this->maintenanceStrategy->disable($application, $branch);
                $output->writeln("Maintenance mode <error>disabled</error> for app \"$appName\".");
            } else {
                $output->writeln("Checking if maintenance mode is enabled for app \"$appName\".");
                $status = $this->maintenanceStrategy->isEnabled($application, $branch);
                $statusText = $status ? 'enabled' : 'disabled';
                $output->writeln("Maintenance mode is $statusText for app \"$appName\".");
            }
        }
        return 0;
    }

}
