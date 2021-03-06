<?php
/**
 * @author Kirk Madera <kirk.madera@rmgmedia.com>
 */

namespace ConductorAppOrchestration\Console;

use ConductorAppOrchestration\Config\ApplicationConfig;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class AppConfigShowCommand extends Command
{
    /**
     * @var ApplicationConfig
     */
    private $applicationConfig;

    /**
     * AppConfigShowCommand constructor.
     *
     * @param ApplicationConfig $applicationConfig
     * @param null              $name
     */
    public function __construct(ApplicationConfig $applicationConfig, $name = null)
    {
        $this->applicationConfig = $applicationConfig;
        parent::__construct($name);
    }

    protected function configure()
    {
        $this->setName('app:config:show')
            ->setDescription('Output application configuration.')
            ->setHelp("This command outputs application configuration matching a given search filter.")
            ->addArgument(
                'filter',
                InputArgument::OPTIONAL,
                'Pattern to filter config keys for display. Wildcards may be used.'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->applicationConfig->validate();
        $filter = $input->getArgument('filter');
        $outputTable = new Table($output);
        $outputTable
            ->setHeaders(['Key', 'Value']);
        $this->expandToOutputRows(null, $this->applicationConfig->toArray(), $outputTable, $filter);
        $outputTable->render();
        return 0;
    }

    /**
     * @param string|null $keyPrefix
     * @param             $data
     * @param Table       $outputTable
     * @param string|null $filter
     */
    private function expandToOutputRows(
        string $keyPrefix = null,
        $data,
        Table $outputTable,
        string $filter = null
    ): void {
        ksort($data);
        foreach ($data as $key => $value) {
            if ($keyPrefix) {
                $key = "$keyPrefix/$key";
            }

            if (is_scalar($value)) {
                if (!$filter || fnmatch($filter, $key)) {
                    $outputTable->addRow([$key, $value]);
                }
            } else {
                $this->expandToOutputRows($key, $value, $outputTable, $filter);
            }
        }
    }

}
