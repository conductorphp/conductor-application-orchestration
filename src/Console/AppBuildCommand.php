<?php
/**
 * @author Kirk Madera <kirk.madera@rmgmedia.com>
 */

namespace ConductorAppOrchestration\Console;

use ConductorAppOrchestration\Build\ApplicationBuilder;
use ConductorAppOrchestration\Config\ApplicationConfig;
use ConductorCore\Filesystem\MountManager\MountManager;
use ConductorCore\MonologConsoleHandlerAwareTrait;
use FilesystemIterator;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

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

    /**
     * AppBuildCommand constructor.
     *
     * @param ApplicationConfig $applicationConfig
     * @param ApplicationBuilder $applicationBuilder
     * @param MountManager $mountManager
     * @param LoggerInterface|null $logger
     * @param string|null $name
     */
    public function __construct(
        ApplicationConfig  $applicationConfig,
        ApplicationBuilder $applicationBuilder,
        MountManager       $mountManager,
        LoggerInterface    $logger = null,
        string             $name = null
    ) {
        $this->applicationConfig = $applicationConfig;
        $this->applicationBuilder = $applicationBuilder;
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
                'plan',
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
            )
            ->addOption(
                'working-dir',
                null,
                InputOption::VALUE_REQUIRED,
                'The local working directory to use during build process.',
                '/tmp/.conductor/build'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $workingDir = $input->getOption('working-dir');

        // Confirm continue if working directory is not empty since it will be cleared
        if (is_dir($workingDir) && (new FilesystemIterator($workingDir))->valid()) {
            $helper = $this->getHelper('question');
            $question = new ConfirmationQuestion(
                sprintf(
                    '<comment>All contents of working directory "%s" will be deleted. Are you sure you want to continue? [y/N]</comment> ',
                    $workingDir
                ), false
            );

            if (!$helper->ask($input, $output, $question)) {
                return;
            }
        }

        $this->applicationConfig->validate();

        $this->injectOutputIntoLogger($output, $this->logger);
        $this->applicationBuilder->setLogger($this->logger);
        $this->applicationBuilder->setPlanPath($workingDir);
        $buildPlan = $input->getOption('plan');
        $repoReference = $input->getArgument('repo-reference');
        $buildId = $this->getBuildId($input, $repoReference, $buildPlan);
        $buildPath = $input->getOption('build-path');

        $this->mountManager->setWorkingDirectory(getcwd());
        [$prefix, $path] = $this->mountManager->getPrefixAndPath($buildPath);
        $buildPath = "$prefix://$path";

        $appName = $this->applicationConfig->getAppName();
        $this->logger->info("Building application \"$appName\".");
        $this->applicationBuilder->build($buildPlan, $repoReference, $buildId, $buildPath);
        $this->logger->info("<info>Application \"$appName\" build complete!</info>");
        $this->logger->info("Build ID: $buildId");
        $output->write($buildId);
        return 0;
    }

    /**
     * @param InputInterface $input
     * @param string $repoReference
     * @param string $buildPlan
     * @return string
     */
    private function getBuildId(InputInterface $input, string $repoReference, string $buildPlan): string
    {
        $buildId = $input->getArgument('build-id');
        if ($buildId) {
            return $buildId;
        }

        // Assuming max allowed length of 255 for a filename, truncate for sanity check
        // 200 + 1 + 34 + 1 + 19 = 255
        $buildId = substr($repoReference, 0, 200)
            . '-'
            . substr($buildPlan, 0, 34)
            . '-'
            . date('YmdHisO'); # 19 characters

        // Replace sets of characters outside of whitelist with a dash
        $buildId = preg_replace('%[^a-z0-9+]+%i', '-', $buildId);

        return $buildId;
    }

}
