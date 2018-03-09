<?php
/**
 * @author Kirk Madera <kmadera@robofirm.com>
 */

namespace ConductorAppOrchestration\Command;

use ConductorAppOrchestration\ApplicationDeployer;
use ConductorAppOrchestration\Config\ApplicationConfig;
use ConductorCore\MonologConsoleHandlerAwareTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class AppDeployCommand extends Command
{
    use MonologConsoleHandlerAwareTrait;

    /**
     * @var ApplicationConfig
     */
    private $applicationConfig;
    /**
     * @var ApplicationDeployer
     */
    private $applicationDeployer;
    /**
     * @var LoggerInterface
     */
    private $logger;


    public function __construct(
        ApplicationConfig $applicationConfig,
        ApplicationDeployer $applicationDeployer,
        LoggerInterface $logger = null,
        string $name = null
    ) {
        $this->applicationConfig = $applicationConfig;
        $this->applicationDeployer = $applicationDeployer;
        if (is_null($logger)) {
            $logger = new NullLogger();
        }
        $this->logger = $logger;
        parent::__construct($name);
    }

    protected function configure()
    {
        $this->setName('app:deploy')
            ->setDescription('Deploy application build and/or snapshot or just run a deploy plan.')
            ->setHelp("This command deploys an application build and/or snapshot or just runs a deploy plan.")
            ->addArgument(
                'deploy-plan',
                InputArgument::REQUIRED,
                'Deploy plan to run.'
            )
            ->addOption(
                'build-id',
                null,
                InputOption::VALUE_REQUIRED,
                'The build to deploy.'
            )
            ->addOption(
                'snapshot',
                null,
                InputOption::VALUE_REQUIRED,
                'The snapshot to deploy.'
            )
            ->addOption(
                'from-repo',
                null,
                InputOption::VALUE_NONE,
                'Deploy code from the repository.'
            )
            ->addOption(
                'repo-reference',
                null,
                InputOption::VALUE_REQUIRED,
                'Repository reference (branch, tag, commit) to deploy. Required if --from-repo given.'
            )
            ->addOption(
                'full-rollback',
                null,
                InputOption::VALUE_NONE,
                'Allow for a full rollback. This will cause increased site downtime due to the need to '
                . 'backup databases.'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        throw new \Exception('Not yet implemented');
        $this->applicationConfig->validate();
        $this->injectOutputIntoLogger($output, $this->logger);
        $this->applicationDeployer->setLogger($this->logger);
        $buildId = $input->getArgument('build-id');
        $deployPlan = $input->getArgument('deploy-plan');

        $appName = $this->applicationConfig->getAppName();
        $this->logger->info("Deploying application \"$appName\".");
        $this->applicationDeployer->deploy(
            $buildId,
            $deployPlan
        );
        $this->logger->info("<info>Application \"$appName\" deployment complete!</info>");
        return 0;
    }

}
