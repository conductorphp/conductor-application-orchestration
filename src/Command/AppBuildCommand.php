<?php
/**
 * @author Kirk Madera <kmadera@robofirm.com>
 */

namespace ConductorAppOrchestration\Command;

use ConductorAppOrchestration\ApplicationBuilder;
use ConductorAppOrchestration\ApplicationConfig;
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
     * @var LoggerInterface
     */
    private $logger;


    public function __construct(
        ApplicationConfig $applicationConfig,
        ApplicationBuilder $applicationBuilder,
        LoggerInterface $logger = null,
        string $name = null
    ) {
        $this->applicationConfig = $applicationConfig;
        $this->applicationBuilder = $applicationBuilder;
        if (is_null($logger)) {
            $logger = new NullLogger();
        }
        $this->logger = $logger;
        parent::__construct($name);
    }

    protected function configure()
    {
        $this->setName('app:build')
            ->setDescription('Build application and optionally push artifacts to a filesystem.')
            ->setHelp("This command runs a build process and then optionally pushes artifacts to a filesystem.")
            ->addArgument(
                'git-reference',
                InputArgument::OPTIONAL,
                'The code reference (branch, tag, commit) to build.',
                'master'
            )
            ->addArgument(
                'build-plan',
                InputArgument::OPTIONAL,
                'Build plan to run.',
                'development'
            )
            ->addOption(
                'save',
                null,
                InputOption::VALUE_NONE,
                'Triggers build mode that creates a tgz file and saves to a given path.'
            )
            ->addOption(
                'save-path',
                null,
                InputOption::VALUE_REQUIRED,
                'Path to save build to, including filesystem prefix.',
                'local://' . getcwd()
            )
            ->addOption(
                'build-id',
                null,
                InputArgument::OPTIONAL,
                'A unique ID for this build. If not specified, the git-reference will be used with timestamp appended.'
            )
            ->addOption(
                'force-clean-build',
                null,
                InputOption::VALUE_NONE,
                'Force a clean build; Only relevant if building in place (no build-id set).'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->injectOutputIntoLogger($output, $this->logger);
        $this->applicationBuilder->setLogger($this->logger);
        $gitReference = $input->getArgument('git-reference');
        $buildPlan = $input->getArgument('build-plan');
        $save = $input->getOption('save');
        $savePath = $input->getOption('save-path');
        $buildId = $input->getOption('build-id') ?? $gitReference . '-' . time();
        $forceCleanBuild = $input->getOption('force-clean-build');

        $appName = $this->applicationConfig->getAppName();
        $this->logger->info("Building application \"$appName\".");
        if ($save) {
            $this->applicationBuilder->build($gitReference, $buildPlan, $buildId, $savePath);
        } else {
            $this->applicationBuilder->buildInPlace(
                $gitReference,
                $buildPlan,
                null,
                $forceCleanBuild
            );
        }
        $this->logger->info("<info>Application \"$appName\" build complete!</info>");
        return 0;
    }

}
