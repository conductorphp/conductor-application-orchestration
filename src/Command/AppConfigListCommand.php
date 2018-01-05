<?php
/**
 * @author Kirk Madera <kmadera@robofirm.com>
 */

namespace DevopsToolAppOrchestration\Command;

use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class AppConfigListCommand extends AbstractCommand
{
    protected function configure()
    {
        $this->setName('app:config:list')
            ->setDescription('Output application configuration.')
            ->setHelp("This command outputs application configuration built from the application setup repo.")
            ->addOption(
                'app',
                null,
                InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY,
                'Application code to get configuration data about.'
            )
            ->addOption('all', null, InputOption::VALUE_NONE, 'Show configuration for all applications.');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $applications = $this->getApplications($input);
        $outputTable = new Table($output);
        $outputTable
            ->setHeaders(['Application Code', 'Config']);
        $i = 0;
        foreach ($applications as $code => $application) {
            if ($i > 0) {
                $outputTable->addRow(new TableSeparator());
            }
            $application = $application->getArrayCopy();
            ksort($application);
            $outputTable->addRow([$code, print_r($application, true)]);
            $i++;
        }
        $outputTable->render();
        return 0;
    }

}
