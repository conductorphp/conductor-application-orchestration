<?php
/**
 * @author Kirk Madera <kmadera@robofirm.com>
 */

namespace DevopsToolAppOrchestration\Command;

use DevopsToolAppOrchestration\ApplicationDestroyer;
use DevopsToolCore\MonologConsoleHandlerAwareTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

class AppDestroyCommand extends AbstractCommand
{
    use MonologConsoleHandlerAwareTrait;

    /**
     * @var ApplicationDestroyer
     */
    private $applicationDestroyer;
    /**
     * @var LoggerInterface
     */
    private $logger;


    public function __construct(
        ApplicationDestroyer $applicationDestroyer,
        LoggerInterface $logger = null,
        $name = null
    ) {
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
                'app',
                null,
                InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY,
                'Application code if you want to pull repo_url and environment from configuration'
            )
            ->addOption('all', null, InputOption::VALUE_NONE, 'Destroy all apps in configuration')
            ->addOption(
                'branch',
                null,
                InputArgument::OPTIONAL,
                'The branch instance to destroy. Only relevant when using the \"branch\" file layout.'
            )
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Do not ask confirmation');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->injectOutputIntoLogger($output, $this->logger);
        $this->applicationDestroyer->setLogger($this->logger);
        $applications = $this->getApplications($input);

        $branch = $input->getOption('branch');
        if (!$input->getOption('force')) {
            /** @var QuestionHelper $helper */
            $helper = $this->getHelper('question');
            $question = new ConfirmationQuestion(
                sprintf(
                    '<question>Are you sure you want to destroy application instances %s%s? [y/N]</question>',
                    '"' . implode('", "', array_keys($applications)) . '"',
                    ($branch ? " ($branch branch only)" : '')
                ),
                false
            );

            if (!$helper->ask($input, $output, $question)) {
                return 0;
            }
        }

        foreach ($applications as $code => $application) {
            $branchDescription = ($branch ? " ($branch branch only)" : '');
            $this->logger->info("Destroying application instance $code$branchDescription...");
            $this->applicationDestroyer->destroy($application, $branch);
            $this->logger->info("Application instance $code$branchDescription destroyed.");
        }
        return 0;
    }

}
