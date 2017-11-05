<?php
/**
 * @author Kirk Madera <kmadera@robofirm.com>
 */

namespace DevopsToolAppOrchestration\Command;

use DevopsToolCore\MonologConsoleHandlerAwareTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class AppConfigListCommand extends AbstractCommand
{
    use MonologConsoleHandlerAwareTrait;
    /**
     * @var LoggerInterface
     */
    private $logger;
    /**
     * AppListCommand constructor.
     *
     * @param LoggerInterface $logger
     * @param null                 $name
     */
    public function __construct(
        LoggerInterface $logger = null,
        $name = null
    ) {
        if (is_null($logger)) {
            $logger = new NullLogger();
        }
        $this->logger = $logger;
        parent::__construct($name);
    }

    protected function configure()
    {
        $this->setName('app:config:list')
            ->setDescription('Output application configuration.')
            ->setHelp("This command outputs application configuration built from the application setup repo.")
            ->addOption('app', null, InputOption::VALUE_OPTIONAL, 'App id to get configuration data about.');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->injectOutputIntoLogger($output, $this->logger);
        $applications = $this->config['applications'];
        if ($app = $input->getOption('app')) {
            $applications = [$app => $applications[$app]];
        }

        $outputTable = new Table($output);
        $outputTable
            ->setHeaders(['Application Name', 'Config']);
        $i = 0;
        foreach ($applications as $code => $application) {
            if ($i > 0) {
                $outputTable->addRow(new TableSeparator());
            }
            ksort($application);
            $outputTable->addRow([$application['name'], print_r($application, true)]);
            $i++;
        }
        $outputTable->render();
        return 0;
    }

}
