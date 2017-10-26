<?php
/**
 * @author Kirk Madera <kmadera@robofirm.com>
 */

namespace DevopsToolAppOrchestration\Command;

use DevopsToolAppOrchestration\AppInstall;
use DevopsToolAppOrchestration\AppRefreshAssets;
use DevopsToolCore\Filesystem\Filesystem;
use DevopsToolAppOrchestration\FilesystemFactory;
use DevopsToolCore\Filesystem\FilesystemTransferFactory;
use DevopsToolCore\MonologConsoleHandler;
use DevopsToolCore\ShellCommandHelper;
use League\Flysystem\Adapter\Local;
use Monolog\Logger;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class AppRefreshAssetsCommand extends AbstractCommand
{
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
                'App id if you want to pull repo_url and environment from ~/.devops/app-setup.yaml'
            )
            ->addOption('all', null, InputOption::VALUE_NONE, 'Refresh assets for all apps in ~/.devops/app-setup.yaml')
            ->addOption('branch', null, InputArgument::OPTIONAL, 'The branch to install assets into.')
            ->addOption('filesystem', null, InputOption::VALUE_OPTIONAL, 'The filesystem to pull snapshot from.')
            ->addOption('snapshot', null, InputArgument::OPTIONAL, 'The snapshot to pull assets from.')
            ->addOption(
                'delete',
                null,
                InputOption::VALUE_NONE,
                'Delete local assets which are not present in snapshot.'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // outputs multiple lines to the console (adding "\n" at the end of each line)
        $output->writeln(
            [
                'App: Refresh Assets',
                '============',
                '',
            ]
        );

        $logger = new Logger('app:refresh-assets');
        $logger->pushHandler(new MonologConsoleHandler($output));
        $shellCommandHelper = new ShellCommandHelper($logger);
        $this->parseConfigFile();

        $appIds = $this->getAppIds($input);

        foreach ($appIds as $appId) {
            $repo = $this->getRepo($appId);
            $config = $this->getMergedAppConfig($repo, $appId);
            $branch = $input->getOption('branch') ? $input->getOption('branch') : $config->getDefaultBranch();
            $filesystem = $input->getOption('filesystem') ? $input->getOption('filesystem')
                : $config->getDefaultFileSystem();
            $snapshotName = $input->getOption('snapshot') ? $input->getOption('snapshot')
                : $config->getDefaultSnapshotName();

            $filesystemConfig = $config->getFilesystemConfig($filesystem);
            $sourceFilesystem = FilesystemFactory::create(
                $filesystemConfig->getType(),
                $filesystemConfig->getTypeSpecificConfig(),
                $filesystemConfig->getSnapshotRoot() . "/$snapshotName/assets"
            );

            $destinationFilesystem = new Filesystem(new Local($config->getAppRoot()));
            $filesystemTransfer = FilesystemTransferFactory::create(
                $sourceFilesystem,
                $destinationFilesystem,
                $shellCommandHelper,
                $logger
            );

            $appRefreshAssets = new AppRefreshAssets(
                $filesystemTransfer,
                $config->getAppRoot(),
                $config->getFileLayout(),
                $branch,
                $config->getAssets(),
                $config->getDefaultDirMode(),
                $input->getOption('delete'),
                $logger
            );

            $appInstall = new AppInstall(
                $repo,
                $config->getAppRoot(),
                $config->getFileLayout(),
                $branch,
                $config->getRepoUrl(),
                $config->getDefaultDirMode(),
                $config->getDefaultFileMode(),
                $appRefreshAssets,
                null,
                null,
                $config->getPostInstallScripts(),
                null,
                null,
                $logger,
                $shellCommandHelper
            );

            $appName = $config->getAppName();
            $output->writeln("Refreshing application \"$appName\" assets...");
            $appInstall->install(false, true, false, true, true);
            $output->writeln("<info>Application \"$appName\" assets refreshed!</info>");
        }
        return 0;
    }

}
