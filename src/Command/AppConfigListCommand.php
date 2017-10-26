<?php
/**
 * @author Kirk Madera <kmadera@robofirm.com>
 */

namespace DevopsToolAppOrchestration\Command;

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
            ->addOption('app', null, InputOption::VALUE_OPTIONAL, 'App id to get configuration data about.');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // outputs multiple lines to the console (adding "\n" at the end of each line)
        $output->writeln(
            [
                'App Configuration: List',
                '============',
                '',
            ]
        );

        $this->parseConfigFile();

        $appIds = $this->getAppIds($input);
        foreach ($appIds as $appId) {
            $repo = $this->getRepo($appId);
            $config = $this->getMergedAppConfig($repo, $appId);
            $appName = $config->getAppName();
            $output->writeln("Application info for $appId ($appName)\n" . print_r($config->getArrayCopy(), true));
        }
        return 0;
    }

}
