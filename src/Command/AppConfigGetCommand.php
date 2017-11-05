<?php
/**
 * @author Kirk Madera <kmadera@robofirm.com>
 */

namespace DevopsToolAppOrchestration\Command;

use DevopsToolCore\MonologConsoleHandlerAwareTrait;
use Exception;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class AppConfigGetCommand extends AbstractCommand
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
     * @param null            $name
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
        $this->injectOutputIntoLogger($output, $this->logger);
        $applicationCode = $input->getOption('app');
        $application = $this->config['applications'][$applicationCode];

        $key = $input->getArgument('key');
        $value = $application;
        $parts = explode('.', $key);
        foreach ($parts as $part) {
            if (!array_key_exists($part, $value)) {
                throw new Exception(
                    "Key \"$key\" not found in application $applicationCode (${application['name']}) configuration."
                );
            }
            $value = $value[$part];
        }

        $outputTable = new Table($output);
        $outputTable
            ->setHeaders(['Key', 'Value'])
            ->addRow([$key, $value])
            ->render();

        return 0;
    }

}
