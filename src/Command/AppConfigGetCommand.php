<?php
/**
 * @author Kirk Madera <kmadera@robofirm.com>
 */

namespace DevopsToolAppOrchestration\Command;

use Exception;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class AppConfigGetCommand extends AbstractCommand
{
    protected function configure()
    {
        $this->setName('app:config:get')
            ->setDescription('Gets a value from application configuration.')
            ->setHelp(
                "This command gets a value from the application configuration built from the application setup repo."
            )
            ->addArgument(
                'key',
                InputArgument::REQUIRED,
                'Configuration key to grab value for. Multiple levels should be separated by period (path.to.key).'
            )
            ->addOption('app', null, InputOption::VALUE_REQUIRED, 'App id to get configuration data about.');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // outputs multiple lines to the console (adding "\n" at the end of each line)
        $output->writeln(
            [
                'App Configuration: Get',
                '============',
                '',
            ]
        );

        $this->parseConfigFile();

        $key = $input->getArgument('key');
        $appId = $this->getAppIds($input)[0];

        $repo = $this->getRepo($appId);
        $config = $this->getMergedAppConfig($repo, $appId);
        $appName = $config->getAppName();
        $value = $config->getArrayCopy();
        $parts = explode('.', $key);
        foreach ($parts as $part) {
            if (!array_key_exists($part, $value)) {
                throw new Exception("Key \"$key\" not found in application $appId ($appName) configuration.");
            }
            $value = $value[$part];
        }
        $output->writeln(
            "Value for key \"$key\" in application $appId ($appName) configuration:\n" . print_r($value, true)
        );
        return 0;
    }

}
