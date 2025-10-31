<?php

namespace ConductorAppOrchestration\Console;

use ConductorAppOrchestration\Config\ApplicationConfig;
use ConductorAppOrchestration\Deploy\ApplicationDeployer;
use ConductorAppOrchestration\Exception;
use ConductorCore\Filesystem\MountManager\MountManager;
use ConductorCore\MonologConsoleHandlerAwareTrait;
use FilesystemIterator;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

class AppDeployCommand extends Command
{
    use MonologConsoleHandlerAwareTrait;

    private ApplicationConfig $applicationConfig;
    private ApplicationDeployer $applicationDeployer;
    private MountManager $mountManager;
    private LoggerInterface $logger;

    public function __construct(
        ApplicationConfig   $applicationConfig,
        ApplicationDeployer $applicationDeployer,
        MountManager        $mountManager,
        LoggerInterface     $logger = null,
        string              $name = null
    ) {
        $this->applicationConfig = $applicationConfig;
        $this->applicationDeployer = $applicationDeployer;
        $this->mountManager = $mountManager;
        if (is_null($logger)) {
            $logger = new NullLogger();
        }
        $this->logger = $logger;
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $filesystemPrefixes = $this->mountManager->getFilesystemPrefixes();
        $this->setName('app:deploy')
            ->setDescription('Deploy application build and/or snapshot or just run a deploy plan.')
            ->setHelp("This command deploys an application build and/or snapshot or just runs a deploy plan.")
            ->addOption(
                'skeleton',
                null,
                InputOption::VALUE_NONE,
                'Deploy the skeleton only.'
            )
            ->addOption(
                'refresh',
                null,
                InputOption::VALUE_NONE,
                'Trigger a refresh which redeploys skeleton, existing code, and triggers code-dependent scripts.'
            )
            ->addOption(
                'plan',
                null,
                InputOption::VALUE_REQUIRED,
                'Deploy plan to run.',
                $this->applicationConfig->getDeployConfig()->getDefaultPlan()
            )
            ->addOption(
                'build-id',
                null,
                InputOption::VALUE_REQUIRED,
                'The build to deploy.'
            )
            ->addOption(
                'build-path',
                null,
                InputOption::VALUE_OPTIONAL,
                sprintf(
                    'The filesystem and path to pull the build from. <comment>[allowed: %s]</comment>,',
                    implode(', ', $filesystemPrefixes)
                ),
                $this->applicationConfig->getDefaultFilesystem() . '://builds'
            )
            ->addOption(
                'repo-reference',
                null,
                InputOption::VALUE_REQUIRED,
                'Repository reference (branch, tag, commit) to deploy.'
            )
            ->addOption(
                'snapshot',
                null,
                InputOption::VALUE_REQUIRED,
                'The snapshot to deploy.'
            )
            ->addOption(
                'snapshot-path',
                null,
                InputOption::VALUE_OPTIONAL,
                sprintf(
                    'The filesystem and path to pull the snapshot from. <comment>[allowed: %s]</comment>,',
                    implode(', ', $filesystemPrefixes)
                ),
                $this->applicationConfig->getDefaultFilesystem() . '://snapshots'
            )
            ->addOption(
                'assets',
                null,
                InputOption::VALUE_NONE,
                'Include assets when deploying a snapshot.'
            )
            ->addOption(
                'asset-batch-size',
                null,
                InputOption::VALUE_REQUIRED,
                'Batch size for asset sync when deploying a snapshot.',
                100
            )->addOption(
                'asset-max-concurrency',
                null,
                InputOption::VALUE_REQUIRED,
                'Max concurrency of assets to deploy at one time.',
                10
            )
            ->addOption(
                'databases',
                null,
                InputOption::VALUE_NONE,
                'Include databases when deploying a snapshot.'
            )
            ->addOption(
                'allow-full-rollback',
                null,
                InputOption::VALUE_NONE,
                'Allow for a full rollback. This will cause increased site downtime due to the need to '
                . 'backup databases.'
            )
            ->addOption(
                'clean',
                null,
                InputOption::VALUE_NONE,
                'Run clean plan before running deploy.'
            )
            ->addOption(
                'rollback',
                null,
                InputOption::VALUE_NONE,
                'Perform a rollback.'
            )
            ->addOption(
                'force',
                null,
                InputOption::VALUE_NONE,
                'Bypass confirmation text when running with --clean.'
            )
            ->addOption(
                'working-dir',
                null,
                InputOption::VALUE_REQUIRED,
                'The local working directory to use during deploy process.',
                '/tmp/.conductor/deploy'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $force = $input->getOption('force');
        $workingDir = $input->getOption('working-dir');

        // Confirm continue if working directory is not empty since it will be cleared
        if (!$force && is_dir($workingDir) && (new FilesystemIterator($workingDir))->valid()) {
            $helper = $this->getHelper('question');
            $question = new ConfirmationQuestion(
                sprintf(
                    '<comment>All contents of working directory "%s" will be deleted. Are you sure you want to continue? [y/N]</comment> ',
                    $workingDir
                ), false
            );

            if (!$helper->ask($input, $output, $question)) {
                return self::SUCCESS;
            }
        }

        $this->applicationConfig->validate();
        $this->injectOutputIntoLogger($output, $this->logger);
        $this->applicationDeployer->setLogger($this->logger);
        $this->applicationDeployer->setPlanPath($workingDir);
        $appName = $this->applicationConfig->getAppName();

        if (!$force && $input->getOption('clean')) {
            $helper = $this->getHelper('question');
            $question = new ConfirmationQuestion(
                '<comment>Running with --clean may be a destructive action. Are you sure you want to '
                . 'do this? [y/N]</comment> ', false
            );

            if (!$helper->ask($input, $output, $question)) {
                return self::SUCCESS;
            }
        }

        $this->validateInput($input);

        $includeAssets = $includeDatabases = false;
        if ($input->getOption('snapshot')) {
            if ($input->getOption('assets')) {
                $includeAssets = true;
            }

            if ($input->getOption('databases')) {
                $includeDatabases = true;
            }

            if (!$input->getOption('assets') && !$input->getOption('databases')) {
                $includeAssets = $includeDatabases = true;
            }
        }

        $message = $this->getDeploymentDescription($input, $includeAssets, $includeDatabases);

        $this->logger->info($message);

        if ($input->getOption('skeleton')) {
            $this->applicationDeployer->deploySkeleton(
                $input->getOption('plan'),
                $input->getOption('clean'),
                $force,
            );
        } else {
            $this->applicationDeployer->deploy(
                $input->getOption('plan'),
                $input->getOption('skeleton'),
                $input->getOption('refresh'),
                $input->getOption('build-id'),
                $input->getOption('build-path'),
                $input->getOption('repo-reference'),
                $input->getOption('snapshot'),
                $input->getOption('snapshot-path'),
                $includeAssets,
                [
                    'batch_size' => $input->getOption('asset-batch-size'),
                    'max_concurrency' => $input->getOption('asset-max-concurrency'),
                ],
                $includeDatabases,
                $input->getOption('allow-full-rollback'),
                $input->getOption('clean'),
                $input->getOption('rollback'),
                $force,
            );
        }
        $this->logger->info("<info>Application \"$appName\" deployment completed!</info>");
        return self::SUCCESS;
    }

    private function validateInput(InputInterface $input): void
    {
        if ($input->getOption('build-id') && $input->getOption('repo-reference')) {
            throw new Exception\RuntimeException(
                'Options --build-id and --repo-reference may not both be set.'
            );
        }

        if ($input->getOption('skeleton') && ($input->getOption('snapshot') || $input->getOption('build-id') || $input->getOption('repo-reference'))) {
            throw new Exception\RuntimeException(
                'Options --snapshot, --build-id, and --repo-reference may not be set with --skeleton. Use --refresh instead.'
            );
        }

        if (!$input->getOption('snapshot')
            && ($input->getOption('assets') || $input->getOption('databases'))) {
            throw new Exception\RuntimeException(
                'Options --assets and --databases may only be specified with --snapshot.'
            );
        }
    }

    private function getDeploymentDescription(InputInterface $input, bool $includeAssets, bool $includeDatabases): string
    {
        if ($input->getOption('skeleton')) {
            $message = 'Deploying skeleton only.';
        } else {
            $message = 'Deploying ';
            if ($input->getOption('build-id') || $input->getOption('repo-reference')) {
                $message .= 'code from ';
                if ($input->getOption('build-id')) {
                    $message .= 'build "' . $input->getOption('build-id') . '"';
                } else {
                    $message .= 'branch/tag/commit "' . $input->getOption('repo-reference') . '"';
                }
                if ($input->getOption('snapshot')) {
                    $message .= ' and ';
                } else {
                    $message .= '.';
                }
            }

            if ($input->getOption('snapshot')) {
                $message .= 'snapshot "' . $input->getOption('snapshot-path') . '/' . $input->getOption('snapshot')
                    . '"';
                if ($includeAssets || $includeDatabases) {
                    if ($includeAssets) {
                        $message .= ' assets';
                        if ($includeDatabases) {
                            $message .= ' and';
                        }
                    }
                    if ($includeDatabases) {
                        $message .= ' databases';
                    }
                }
                $message .= '.';
            }
        }
        return $message;
    }

}
