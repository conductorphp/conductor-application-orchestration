<?php
/**
 * @author Kirk Madera <kmadera@robofirm.com>
 */

namespace DevopsToolAppOrchestration\Command;

use DevopsToolAppOrchestration\AppBuild;
use DevopsToolAppOrchestration\ApplicationBuilder;
use DevopsToolAppOrchestration\FilesystemFactory;
use DevopsToolCore\Filesystem\FilesystemTransferFactory;
use DevopsToolCore\MonologConsoleHandlerAwareTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class AppBuildCommand extends AbstractCommand
{
    use MonologConsoleHandlerAwareTrait;

    private $applicationAssetRefresher;
    /**
     * @var ApplicationBuilder
     */
    private $applicationBuilder;
    /**
     * @var LoggerInterface
     */
    private $logger;


    public function __construct(
        ApplicationBuilder $applicationBuilder,
        LoggerInterface $logger = null,
        string $name = null
    ) {
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
                'app',
                null,
                InputOption::VALUE_REQUIRED,
                'Application code from configuration. Required if there is more than one app.'
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
        $applications = $this->getApplications($input);
        $gitReference = $input->getArgument('git-reference');
        $buildPlan = $input->getArgument('build-plan');
        $save = $input->getOption('save');
        $savePath = $input->getOption('save-path');
        $buildId = $input->getOption('build-id') ?? $gitReference . '-' . time();
        $forceCleanBuild = $input->getOption('force-clean-build');

        foreach ($applications as $code => $application) {
            $this->logger->info("Building application \"$code\".");
            if ($save) {
                $this->applicationBuilder->build($application, $gitReference, $buildPlan, $buildId, $savePath);
            } else {
                $this->applicationBuilder->buildInPlace(
                    $application,
                    $gitReference,
                    $buildPlan,
                    null,
                    $forceCleanBuild
                );
            }
            $this->logger->info("<info>Application \"$code\" build complete!</info>");
        }
        return 0;
    }

}
