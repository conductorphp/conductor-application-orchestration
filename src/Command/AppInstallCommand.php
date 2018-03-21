<?php
/**
 * @author Kirk Madera <kmadera@robofirm.com>
 */

namespace ConductorAppOrchestration\Command;

use ConductorAppOrchestration\ApplicationAssetDeployer;
use ConductorAppOrchestration\Build\ApplicationBuilder;
use ConductorAppOrchestration\ApplicationCodeDeployer;
use ConductorAppOrchestration\Config\ApplicationConfig;
use ConductorAppOrchestration\ApplicationDatabaseDeployer;
use ConductorAppOrchestration\ApplicationSkeletonDeployer;
use ConductorCore\Filesystem\MountManager\MountManager;
use ConductorCore\MonologConsoleHandlerAwareTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class AppInstallCommand extends Command
{
    use MonologConsoleHandlerAwareTrait;

    /**
     * @var ApplicationConfig
     */
    private $applicationConfig;
    /**
     * @var ApplicationSkeletonDeployer
     */
    private $applicationSkeletonDeployer;
    /**
     * @var ApplicationCodeDeployer
     */
    private $applicationCodeInstaller;
    /**
     * @var ApplicationDatabaseDeployer
     */
    private $applicationDatabaseDeployer;
    /**
     * @var ApplicationAssetDeployer
     */
    private $applicationAssetDeployer;
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
        ApplicationSkeletonDeployer $applicationSkeletonDeployer,
        ApplicationCodeDeployer $applicationCodeInstaller,
        ApplicationDatabaseDeployer $applicationDatabaseDeployer,
        ApplicationAssetDeployer $applicationAssetDeployer,
        ApplicationBuilder $applicationBuilder,
        MountManager $mountManager,
        LoggerInterface $logger = null,
        string $name = null
    ) {
        $this->applicationConfig = $applicationConfig;
        $this->applicationSkeletonDeployer = $applicationSkeletonDeployer;
        $this->applicationCodeInstaller = $applicationCodeInstaller;
        $this->applicationDatabaseDeployer = $applicationDatabaseDeployer;
        $this->applicationAssetDeployer = $applicationAssetDeployer;
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
            ->setHelp("This command installs an application based on configuration.")
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
                'The snapshot to pull assets and database from.',
                'production-scrubbed'
            )
            ->addOption('no-code', null, InputOption::VALUE_NONE, 'Do not install code')
            ->addOption('no-assets', null, InputOption::VALUE_NONE, 'Do not install assets')
            ->addOption('no-databases', null, InputOption::VALUE_NONE, 'Do not install databases')
            ->addOption('no-build', null, InputOption::VALUE_NONE, 'Do not perform a build after install')
            ->addOption(
                'replace',
                null,
                InputOption::VALUE_NONE,
                'Replace if already installed. This will update code and replace database and assets.'
            )
            ->addOption(
                'asset-batch-size',
                null,
                InputOption::VALUE_REQUIRED,
                'Batch size for asset sync.'
            )
            ->addOption('plan', null, InputOption::VALUE_REQUIRED, 'Build plan to run', 'development');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        throw new \Exception('This command is deprecated. Use app:deploy instead.');
        $this->applicationConfig->validate();
        $this->injectOutputIntoLogger($output, $this->logger);
        $this->applicationSkeletonDeployer->setLogger($this->logger);
        $this->applicationCodeInstaller->setLogger($this->logger);
        $this->applicationAssetDeployer->setLogger($this->logger);
        $this->applicationDatabaseDeployer->setLogger($this->logger);
        $this->applicationBuilder->setLogger($this->logger);
        $syncConfig = [
            'batch_size' => $input->getOption('asset-batch-size'),
        ];
        $installCode = !$input->getOption('no-code');
        $installAssets = !$input->getOption('no-assets');
        $installDatabases = !$input->getOption('no-databases');
        $runBuild = !$input->getOption('no-build');
        $buildPlan = $input->getOption('plan');
        $replace = $input->getOption('replace');

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

        $appName = $this->applicationConfig->getAppName();
        $this->logger->info("Installing application \"$appName\".");
        $branch = $input->getOption('branch') ?? $this->applicationConfig->getDefaultBranch();
        $filesystem = $input->getOption('filesystem') ?? $this->applicationConfig->getDefaultFilesystem();
        $snapshot = $input->getOption('snapshot');

        $this->applicationSkeletonDeployer->prepareFileLayout();

        if ($installCode) {
            $this->applicationCodeInstaller->deployCode($branch, $replace, $replace);
        }

        $this->applicationSkeletonDeployer->installAppFiles($branch, $replace);

        if ($installAssets) {
            $this->applicationAssetDeployer->deployAssets($filesystem, $snapshot, $syncConfig);
        }

        if ($installDatabases) {
            $this->applicationDatabaseDeployer->deployDatabases(
                $filesystem,
                $snapshot,
                $branch,
                $replace
            );
        }

        if ($runBuild) {
            $this->applicationBuilder->buildInPlace($branch, $buildPlan, $buildTriggers, $replace);
        }

        $this->logger->info("<info>Application \"$appName\" installed!</info>");
        return 0;
    }

}
