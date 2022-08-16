<?php

namespace ConductorAppOrchestration\Console;

use ConductorAppOrchestration\Config\ApplicationConfig;
use ConductorAppOrchestration\Destroy\ApplicationDestroyer;
use ConductorCore\MonologConsoleHandlerAwareTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

class AppDestroyCommand extends Command
{
    use MonologConsoleHandlerAwareTrait;

    private ApplicationConfig $applicationConfig;
    private ApplicationDestroyer $applicationDestroyer;
    private LoggerInterface $logger;


    public function __construct(
        ApplicationConfig    $applicationConfig,
        ApplicationDestroyer $applicationDestroyer,
        LoggerInterface      $logger = null,
        string               $name = null
    ) {
        $this->applicationConfig = $applicationConfig;
        $this->applicationDestroyer = $applicationDestroyer;
        if (is_null($logger)) {
            $logger = new NullLogger();
        }
        $this->logger = $logger;
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('app:destroy')
            ->setDescription('Destroy application.')
            ->setHelp("This command destroys the local deployment of the application.")
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Do not ask confirmation');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->applicationConfig->validate();
        $this->injectOutputIntoLogger($output, $this->logger);
        $this->applicationDestroyer->setLogger($this->logger);
        $appName = $this->applicationConfig->getAppName();

        if (!$input->getOption('force')) {
            /** @var QuestionHelper $helper */
            $helper = $this->getHelper('question');
            $question = new ConfirmationQuestion(
                sprintf(
                    '<question>Are you sure you want to destroy deployment of application "%s"? [y/N]</question>',
                    $appName
                ),
                false
            );

            if (!$helper->ask($input, $output, $question)) {
                return self::SUCCESS;
            }
        }

        $this->logger->info("Destroying deployment of application \"$appName\".");
        $this->applicationDestroyer->destroy();
        $this->logger->info("Deployment of application \"$appName\" destroyed.");
        return self::SUCCESS;
    }

}
