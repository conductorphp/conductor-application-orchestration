<?php
/**
 * @author Kirk Madera <kmadera@robofirm.com>
 */

namespace DevopsToolAppOrchestration\Command;

use DevopsToolCore\MonologConsoleHandlerAwareTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class AppListCommand extends AbstractCommand
{
    use MonologConsoleHandlerAwareTrait;
    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * AppListCommand constructor.
     *
     * @param LoggerInterface|null $logger
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
        $this->setName('app:list')
            ->setDescription('Lists configured apps.')
            ->setHelp("This command lists all configured applications.");
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->injectOutputIntoLogger($output, $this->logger);

        $outputTable = new Table($output);
        $outputTable
            ->setHeaders(['Application Name', 'Code', 'Platform']);
        foreach ($this->config['applications'] as $code => $application) {
            $outputTable->addRow([$application['name'], $code, $application['platform']]);
        }
        $outputTable->render();
        return 0;
    }

}
