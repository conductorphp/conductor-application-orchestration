<?php
/**
 * @author Kirk Madera <kmadera@robofirm.com>
 */

namespace DevopsToolAppOrchestration\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class AppListCommand extends AbstractCommand
{
    protected function configure()
    {
        $this->setName('app:list')
            ->setDescription('Lists configured apps.')
            ->setHelp("This command lists all apps configured in ~/.devops/app-setup.yaml.");
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // outputs multiple lines to the console (adding "\n" at the end of each line)
        $output->writeln(
            [
                'App List',
                '============',
                '',
            ]
        );

        $this->parseConfigFile();

        $appIds = $this->getAppIds($input);
        $message = "Configured applications:\n";
        foreach ($appIds as $appId) {
            $repo = $this->getRepo($appId);
            $config = $this->getMergedAppConfig($repo, $appId);
            $appName = $config->getAppName();
            $message .= "$appId ($appName)\n";
        }

        $output->writeln($message);
        return 0;
    }

}
