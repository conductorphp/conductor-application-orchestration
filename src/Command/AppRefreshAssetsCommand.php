<?php
/**
 * @author Kirk Madera <kmadera@robofirm.com>
 */

namespace DevopsToolAppOrchestration\Command;

use DevopsToolAppOrchestration\ApplicationAssetRefresher;
use DevopsToolCore\MonologConsoleHandlerAwareTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class AppRefreshAssetsCommand extends AbstractCommand
{
    use MonologConsoleHandlerAwareTrait;

    /**
     * @var ApplicationAssetRefresher
     */
    private $applicationAssetRefresher;
    /**
     * @var LoggerInterface
     */
    private $logger;


    public function __construct(
        ApplicationAssetRefresher $applicationAssetRefresher,
        ?LoggerInterface $logger,
        ?string $name
    ) {
        $this->applicationAssetRefresher = $applicationAssetRefresher;
        if (is_null($logger)) {
            $logger = new NullLogger();
        }
        $this->logger = $logger;
        parent::__construct($name);
    }

    protected function configure()
    {
        $this->setName('app:refresh-assets')
            ->setDescription('Refresh application assets.')
            ->setHelp(
                "This command refreshes application assets based on configuration in a given application setup repo."
            )
            ->addOption(
                'app',
                null,
                InputOption::VALUE_OPTIONAL,
                'Application code if you want to pull repo_url and environment from configuration'
            )
            ->addOption('all', null, InputOption::VALUE_NONE, 'Refresh assets for all apps in configuration')
            ->addOption(
                'filesystem',
                null,
                InputOption::VALUE_OPTIONAL,
                'The filesystem to pull snapshot from. [Defaults to application default filesystem]'
            )
            ->addOption(
                'snapshot',
                null,
                InputArgument::OPTIONAL,
                'The snapshot to pull assets from.',
                'production-scrubbed'
            )
            ->addOption(
                'delete',
                null,
                InputOption::VALUE_NONE,
                'Delete local assets which are not present in snapshot.'
            )
            ->addOption(
                'batch-size',
                null,
                InputOption::VALUE_REQUIRED,
                'Batch size for asset sync.'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->injectOutputIntoLogger($output, $this->logger);
        $this->applicationAssetRefresher->setLogger($this->logger);
        $applications = $this->getApplications($input);
        $syncConfig = [
            'delete' => $input->getOption('delete'),
            'batch_size' => $input->getOption('batch-size'),
        ];

        foreach ($applications as $code => $application) {
            $this->logger->info("Refreshing application \"$code\" assets...");
            $filesystem = $input->getOption('filesystem') ?? $application->getDefaultFilesystem();
            $snapshot = $input->getOption('snapshot');
            $this->applicationAssetRefresher->refreshAssets($application, $filesystem, $snapshot, $syncConfig);
            $this->logger->info("<info>Application \"$code\" assets refreshed!</info>");
        }
        return 0;
    }

}
