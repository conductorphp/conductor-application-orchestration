<?php
/**
 * @author Kirk Madera <kmadera@robofirm.com>
 */

namespace DevopsToolAppOrchestration\Command;

use DevopsToolAppOrchestration\AppConfig;
use DevopsToolAppOrchestration\AppMaintenance;
use DevopsToolAppOrchestration\FileLayout;
use DevopsToolAppOrchestration\FileLayoutHelper;
use DevopsToolAppOrchestration\MaintenanceStrategy\Magento1FileMaintenanceStrategy;
use DevopsToolAppOrchestration\MaintenanceStrategy\MaintenanceStrategyInterface;
use Exception;
use League\Flysystem\Adapter\Local as LocalFileAdapter;
use League\Flysystem\Filesystem;
use League\Flysystem\Sftp\SftpAdapter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class AppMaintenanceCommand extends AbstractCommand
{
    protected function configure()
    {
        $this->setName('app:maintenance')
            ->setDescription('Manage maintenance mode.')
            ->setHelp(
                "This command can check if maintenance mode is enabled or enable/disable maintenance mode for an application."
            )
            ->addArgument('action', InputArgument::REQUIRED, 'Action to take. May use: status, enable, or disable')
            ->addOption(
                'app',
                null,
                InputOption::VALUE_OPTIONAL,
                'Application code if you want to pull repo_url and environment from configuration'
            )
            ->addOption('repo', null, InputOption::VALUE_OPTIONAL, 'The url of the setup repo.')
            ->addOption('environment', null, InputOption::VALUE_OPTIONAL, 'The environment in which this server lives.')
            ->addOption('role', null, InputOption::VALUE_OPTIONAL, 'This server\'s role.')
            ->addOption(
                'branch',
                null,
                InputArgument::OPTIONAL,
                'The branch instance to destroy. Only relevant when using the \"branch\" file layout.'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // outputs multiple lines to the console (adding "\n" at the end of each line)
        $output->writeln(
            [
                'App Maintenance:',
                '============',
                '',
            ]
        );

        $this->parseConfigFile();

        $appIds = $this->getAppCodes($input);
        $action = $input->getArgument('action');

        foreach ($appIds as $appId) {
            $repo = $this->getRepo($appId);
            $config = $this->getMergedAppConfig($repo, $appId);
            $branch = $input->getOption('branch') ? $input->getOption('branch') : $config->getDefaultBranch();
            $appName = $config->getAppName();

            $maintenanceStrategy = $this->getMaintenanceStrategy($config, $branch, $output);
            $appMaintenance = new AppMaintenance(
                $maintenanceStrategy
            );

            if ('enable' == $action) {
                $output->writeln("Enabling maintenance mode for app \"$appName\"...");
                $appMaintenance->enable();
                $output->writeln("Maintenance mode <info>enabled</info> for app \"$appName\".");
            } elseif ('disable' == $action) {
                $output->writeln("Disabling maintenance mode for app \"$appName\"...");
                $appMaintenance->disable();
                $output->writeln("Maintenance mode <error>disabled</error> for app \"$appName\".");
            } else {
                $output->writeln("Checking if maintenance mode is enabled for app \"$appName\"...");
                $status = $appMaintenance->isEnabled() ? '<info>enabled</info>' : '<error>disabled</error>';
                $output->writeln("Maintenance mode is $status for app \"$appName\".");
            }
        }
        return 0;
    }

    /**
     * @param AppConfig $config
     * @param string $branch
     * @param OutputInterface $output
     *
     * @return Magento1FileMaintenanceStrategy
     * @throws Exception
     */
    protected function getMaintenanceStrategy(AppConfig $config, $branch, OutputInterface $output)
    {
        $platform = $config->getPlatform();
        $maintenanceStrategy = $config->getMaintenanceStrategy();
        $servers = $config->getServers();
        switch ($maintenanceStrategy) {
            case MaintenanceStrategyInterface::STRATEGY_FILE:

                $fileLayout = new FileLayout(
                    $config->getAppRoot(),
                    $config->getFileLayout(),
                    $config->getRelativeDocumentRoot(),
                    $branch
                );

                $fileLayoutHelper = new FileLayoutHelper();
                $fileLayoutHelper->loadFileLayoutPaths($fileLayout);
                $documentRoot = $fileLayout->getDocumentRoot(true);

                $filesystems = [];
                if (empty($servers)) {
                    $filesystems[] = new Filesystem(new LocalFileAdapter($documentRoot));
                    $output->writeln(
                        '<comment>No "servers" node set in config. Assuming this is a single server setup.</comment>'
                    );
                } else {
                    foreach ($servers as $server) {
                        if (in_array($server['host'], ['127.0.0.1', 'localhost'])) {
                            $filesystems[] = new Filesystem(new LocalFileAdapter($documentRoot));
                        } else {
                            $filesystems[] = new Filesystem(
                                new SftpAdapter(
                                    array_merge(
                                        $config->getSshDefaults(),
                                        $server,
                                        ['root' => $documentRoot]
                                    )
                                )
                            );
                        }
                    }
                }

                switch ($platform) {
                    case MaintenanceStrategyInterface::PLATFORM_MAGENTO1:
                        $maintenanceStrategy = new Magento1FileMaintenanceStrategy(
                            $filesystems
                        );
                        break;

                    default:
                        throw new \Exception("Platform \"$platform\" does not have a supported maintenance strategy.");
                        break;
                }
                break;

            default:
                break;
        }

        if (!isset($maintenanceStrategy)) {
            throw new Exception(
                "Unaccounted for app type \"$platform\" with maintenance strategy \"$maintenanceStrategy\"."
            );
        }
        return $maintenanceStrategy;
    }

}
