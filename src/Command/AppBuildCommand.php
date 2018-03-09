<?php
/**
 * @author Kirk Madera <kmadera@robofirm.com>
 */

namespace ConductorAppOrchestration\Command;

use ConductorAppOrchestration\ApplicationBuilder;
use ConductorAppOrchestration\Config\ApplicationConfig;
use ConductorCore\Filesystem\MountManager\MountManager;
use ConductorCore\MonologConsoleHandlerAwareTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class AppBuildCommand extends Command
{
    use MonologConsoleHandlerAwareTrait;

    /**
     * @var ApplicationConfig
     */
    private $applicationConfig;
    /**
     * @var ApplicationBuilder
     */
    private $applicationBuilder;
    /**
     * @var MountManager
     */
    private $mountManager;
    /**
     * @var LoggerInterface
     */
    private $logger;


    public function __construct(
        ApplicationConfig $applicationConfig,
        ApplicationBuilder $applicationDeployer,
        MountManager $mountManager,
        LoggerInterface $logger = null,
        string $name = null
    ) {
        $this->applicationConfig = $applicationConfig;
        $this->applicationBuilder = $applicationDeployer;
        $this->mountManager = $mountManager;
        if (is_null($logger)) {
            $logger = new NullLogger();
        }
        $this->logger = $logger;
        parent::__construct($name);
    }

    protected function configure()
    {
        $filesystemPrefixes = $this->mountManager->getFilesystemPrefixes();
        $this->setName('app:build')
            ->setDescription('Build application code from repository and save as tgz to a filesystem.')
            ->setHelp("This command builds the application code from the repository and saves as tgz to a filesystem.")
            ->addArgument(
                'repo-reference',
                InputArgument::REQUIRED,
                'The repository reference (branch, tag, commit) to build.'
            )
            ->addArgument(
                'build-id',
                InputArgument::OPTIONAL,
                'The build id to upload this. Generated based on other parameters if not provided. The build '
                . 'id is written to stdout.'
            )
            ->addOption(
                'build-plan',
                null,
                InputOption::VALUE_OPTIONAL,
                'Build plan to run.',
                $this->applicationConfig->getBuildConfig()->getDefaultPlan()
            )
            ->addOption(
                'build-path',
                null,
                InputOption::VALUE_OPTIONAL,
                sprintf(
                    'The filesystem and path to push the build to. <comment>[allowed: %s]</comment>,',
                    implode(', ', $filesystemPrefixes)
                ),
                $this->applicationConfig->getDefaultFilesystem() . '://builds'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->applicationConfig->validate();
        $this->injectOutputIntoLogger($output, $this->logger);
        $this->applicationBuilder->setLogger($this->logger);
        $buildPlan = $input->getOption('build-plan');
        $repoReference = $input->getArgument('repo-reference');
        $buildId = $input->getArgument('build-id') ?? $repoReference . '-' . $buildPlan . '-' . time();
        $buildPath = $input->getOption('build-path');

        $appName = $this->applicationConfig->getAppName();
        $this->logger->info("Building application \"$appName\".");
        $this->applicationBuilder->build($buildPlan, $repoReference, $buildId, $buildPath);
        $this->logger->info("<info>Application \"$appName\" build complete!</info>");
        $output->write($buildId);
        return 0;
    }

}
