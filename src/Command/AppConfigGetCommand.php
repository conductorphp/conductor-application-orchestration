<?php
/**
 * @author Kirk Madera <kmadera@robofirm.com>
 */

namespace DevopsToolAppOrchestration\Command;

use Symfony\Component\Console\Helper\Table;
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
                'Configuration key to grab value for. Multiple levels should be separated by a forward slash (path/to/key).'
            )
            ->addOption(
                'app',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Application code to get configuration data about.'
            )
            ->addOption('all', null, InputOption::VALUE_NONE, 'Show configuration for all applications.');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $applications = $this->getApplications($input);
        $key = $input->getArgument('key');
        // Remove "/*" suffix when searching by array key
        $searchKey = preg_replace('%/\*$%', '', $key);

        $outputTable = new Table($output);
        $outputTable
            ->setHeaders(['Application Code', 'Key', 'Value']);

        foreach ($applications as $code => $application) {
            $value = $application->getArrayCopy();
            $parts = explode('/', $searchKey);
            foreach ($parts as $part) {
                if (is_array($value) && array_key_exists($part, $value)) {
                    $value = $value[$part];
                } else {
                    $value = null;
                    break;
                }
            }

            if (is_null($value)) {
                $displayValue = 'NULL';
            } elseif (is_array($value)) {
                $displayValue = print_r($value, true);
            } else {
                $displayValue = $value;
            }

            $outputTable->addRow([$code, $key, $displayValue]);
        }

        $outputTable->render();
        return 0;
    }

}
