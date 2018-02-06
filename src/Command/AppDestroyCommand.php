<?php
/**
 * @author Kirk Madera <kmadera@robofirm.com>
 */

namespace DevopsToolAppOrchestration\Command;

use DevopsToolAppOrchestration\ApplicationConfig;
use DevopsToolAppOrchestration\ApplicationDestroyer;
use DevopsToolCore\MonologConsoleHandlerAwareTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

class AppDestroyCommand extends Command
{
    use MonologConsoleHandlerAwareTrait;

    /**
     * @var ApplicationConfig
     */
    private $applicationConfig;
    /**
     * @var ApplicationDestroyer
     */
    private $applicationDestroyer;
    /**
     * @var LoggerInterface
     */
    private $logger;


    /**
     * AppDestroyCommand constructor.
     *
     * @param ApplicationConfig    $applicationConfig
     * @param ApplicationDestroyer $applicationDestroyer
     * @param LoggerInterface|null $logger
     * @param string|null          $name
     */
    public function __construct(
        ApplicationConfig $applicationConfig,
        ApplicationDestroyer $applicationDestroyer,
        LoggerInterface $logger = null,
        string $name = null
    ) {
        $this->applicationConfig = $applicationConfig;
        $this->applicationDestroyer = $applicationDestroyer;
        if (is_null($logger)) {
            $logger = new NullLogger();
        }
        $this->logger = $logger;
        parent::__construct($name);
    }

    protected function configure()
    {
        $this->setName('app:destroy')
            ->setDescription('Destroy application.')
            ->setHelp("This command destroys an application based on configuration in a given application setup repo.")
            ->addOption(
                'branch',
                null,
                InputArgument::OPTIONAL,
                'The branch instance to destroy. Only relevant when using the \"branch\" file layout.'
            )
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Do not ask confirmation');
    }

    /**
     * @param InputInterface  $input
     * @param OutputInterface $output
     *
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->injectOutputIntoLogger($output, $this->logger);
        $this->applicationDestroyer->setLogger($this->logger);
        $appName = $this->applicationConfig->getAppName();

        $branch = $input->getOption('branch');
        if (!$input->getOption('force')) {
            /** @var QuestionHelper $helper */
            $helper = $this->getHelper('question');
            $question = new ConfirmationQuestion(
                sprintf(
                    '<question>Are you sure you want to destroy application instances %s%s? [y/N]</question>',
                    $appName,
                    ($branch ? " ($branch branch only)" : '')
                ),
                false
            );

            if (!$helper->ask($input, $output, $question)) {
                return 0;
            }
        }

        $branchDescription = ($branch ? " ($branch branch only)" : '');
        $this->logger->info("Destroying application instance $appName$branchDescription.");
        $this->applicationDestroyer->destroy($branch);
        $this->logger->info("Application instance $appName$branchDescription destroyed.");
        return 0;
    }

}
