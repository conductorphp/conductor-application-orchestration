<?php
/**
 * @author Kirk Madera <kmadera@robofirm.com>
 */

namespace DevopsToolAppOrchestration\Command;

use DevopsToolAppOrchestration\ApplicationAssetRefresher;
use DevopsToolAppOrchestration\ApplicationBuilder;
use DevopsToolAppOrchestration\ApplicationCodeInstaller;
use DevopsToolAppOrchestration\ApplicationDatabaseRefresher;
use DevopsToolAppOrchestration\ApplicationSkeletonInstaller;
use DevopsToolCore\Filesystem\MountManager\MountManager;
use DevopsToolCore\MonologConsoleHandlerAwareTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class AppInstallCommand extends AbstractCommand
{
    use MonologConsoleHandlerAwareTrait;

    /**
     * @var ApplicationSkeletonInstaller
     */
    private $applicationSkeletonInstaller;
    /**
     * @var ApplicationCodeInstaller
     */
    private $applicationCodeInstaller;
    /**
     * @var ApplicationDatabaseRefresher
     */
    private $applicationDatabaseRefresher;
    /**
     * @var ApplicationAssetRefresher
     */
    private $applicationAssetRefresher;
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
        ApplicationSkeletonInstaller $applicationSkeletonInstaller,
        ApplicationCodeInstaller $applicationCodeInstaller,
        ApplicationDatabaseRefresher $applicationDatabaseRefresher,
        ApplicationAssetRefresher $applicationAssetRefresher,
        ApplicationBuilder $applicationBuilder,
        MountManager $mountManager,
        LoggerInterface $logger = null,
        string $name = null
    ) {
        $this->applicationSkeletonInstaller = $applicationSkeletonInstaller;
        $this->applicationCodeInstaller = $applicationCodeInstaller;
        $this->applicationDatabaseRefresher = $applicationDatabaseRefresher;
        $this->applicationAssetRefresher = $applicationAssetRefresher;
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
        $this->setName('app:install')
            ->setDescription('Install application.')
            ->setHelp("This command installs an application based on configuration in a given application setup repo.")
            ->addOption(
                'app',
                null,
                InputOption::VALUE_OPTIONAL,
                'Application code if you want to pull repo_url and environment from configuration'
            )
            ->addOption('all', null, InputOption::VALUE_NONE, 'Refresh assets for all apps in configuration')
            ->addOption('branch', null, InputOption::VALUE_OPTIONAL, 'The code branch to install.')
            ->addOption(
                'filesystem',
                null,
                InputOption::VALUE_OPTIONAL,
                sprintf(
                    'The filesystem to pull snapshot from. <comment>Configured filesystems: %s. [default: application default filesystem]</comment>.',
                    implode(', ', $filesystemPrefixes)
                )
            )
            ->addOption(
                'snapshot',
                null,
                InputOption::VALUE_REQUIRED,
                'The snapshot to pull assets from.',
                'production-scrubbed'
            )
            ->addOption('skeleton', null, InputOption::VALUE_NONE, 'Install app skeleton only')
            ->addOption('no-code', null, InputOption::VALUE_NONE, 'Do not install code')
            ->addOption('no-assets', null, InputOption::VALUE_NONE, 'Do not install assets')
            ->addOption('no-databases', null, InputOption::VALUE_NONE, 'Do not install databases')
            ->addOption('no-build', null, InputOption::VALUE_NONE, 'Do not perform a build after install')
            ->addOption(
                'reinstall',
                null,
                InputOption::VALUE_NONE,
                'Reinstall if already installed. This will reinstall files, database, and assets.'
            )
            ->addOption(
                'asset-batch-size',
                null,
                InputOption::VALUE_REQUIRED,
                'Batch size for asset sync.'
            )
            ->addOption('build-plan', null, InputOption::VALUE_REQUIRED, 'Build plan to run', 'development');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->injectOutputIntoLogger($output, $this->logger);
        $this->applicationSkeletonInstaller->setLogger($this->logger);
        $this->applicationCodeInstaller->setLogger($this->logger);
        $this->applicationAssetRefresher->setLogger($this->logger);
        $this->applicationDatabaseRefresher->setLogger($this->logger);
        $this->applicationBuilder->setLogger($this->logger);
        $applications = $this->getApplications($input);
        $syncConfig = [
            'batch_size' => $input->getOption('asset-batch-size'),
        ];
        $installCode = !$input->getOption('skeleton') && !$input->getOption('no-code');
        $installAssets = !$input->getOption('skeleton') && !$input->getOption('no-assets');
        $installDatabases = !$input->getOption('skeleton') && !$input->getOption('no-databases');
        $runBuild = !$input->getOption('skeleton') && !$input->getOption('no-build');
        $buildPlan = $input->getOption('build-plan');
        $reinstall = $input->getOption('reinstall');

        $buildTriggers = [];
        if ($installCode) {
            $buildTriggers[] = ApplicationBuilder::TRIGGER_CODE;
        }
        if ($installAssets) {
            $buildTriggers[] = ApplicationBuilder::TRIGGER_ASSETS;
        }
        if ($installDatabases) {
            $buildTriggers[] = ApplicationBuilder::TRIGGER_DATABASES;
        }

        foreach ($applications as $code => $application) {
            $this->logger->info("Refreshing application \"$code\" assets.");
            $branch = $input->getOption('branch') ?? $application->getDefaultBranch();
            $filesystem = $input->getOption('filesystem') ?? $application->getDefaultFilesystem();
            $snapshot = $input->getOption('snapshot');

            $this->applicationSkeletonInstaller->prepareFileLayout($application);

            if ($installCode) {
                $this->applicationCodeInstaller->installCode($application, $branch, $reinstall);
            }

            $this->applicationSkeletonInstaller->installAppFiles($application, $branch, $reinstall);

            if ($installAssets) {
                $this->applicationAssetRefresher->refreshAssets($application, $filesystem, $snapshot, $syncConfig);
            }

            if ($installDatabases) {
                $this->applicationDatabaseRefresher->refreshDatabases(
                    $application,
                    $filesystem,
                    $snapshot,
                    $branch,
                    $reinstall
                );
            }

            if ($runBuild) {
                $this->applicationBuilder->buildInPlace($application, $branch, $buildPlan, $buildTriggers, $reinstall);
            }

            $this->logger->info("<info>Application \"$code\" installation complete!</info>");
        }
        return 0;
    }


}
