<?php
/**
 * @author Kirk Madera <kmadera@robofirm.com>
 */

namespace DevopsToolAppOrchestration\Command;

use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class AppListCommand extends AbstractCommand
{
    protected function configure()
    {
        $this->setName('app:list')
            ->setDescription('Lists configured apps.')
            ->setHelp("This command lists all configured applications.");
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $outputTable = new Table($output);
        $outputTable
            ->setHeaders(['Application Code', 'Name', 'Platform']);
        foreach ($this->applicationConfig as $code => $application) {
            $outputTable->addRow([$code, $application->getAppName(), $application->getPlatform()]);
        }
        $outputTable->render();
        return 0;
    }

}
