<?php
/**
 * @author Kirk Madera <kmadera@robofirm.com>
 */

namespace DevopsToolAppOrchestration\Command;

use DevopsToolAppOrchestration\AppInstall;
use DevopsToolAppOrchestration\AppRefreshAssets;
use DevopsToolAppOrchestration\AppRefreshDatabases;
use DevopsToolCore\Filesystem\Filesystem;
use DevopsToolAppOrchestration\FilesystemFactory;
use DevopsToolCore\Filesystem\FilesystemTransferFactory;
use DevopsToolCore\MonologConsoleHandler;
use DevopsToolCore\ShellCommandHelper;
use DevopsToolCore\Database\DatabaseImportExportAdapterInterface;
use League\Flysystem\Adapter\Local;
use Monolog\Logger;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class AppInstallCommand extends AbstractCommand
{
    protected function configure()
    {
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
            ->addOption('filesystem', null, InputOption::VALUE_OPTIONAL, 'The filesystem to pull snapshot from.')
            ->addOption('snapshot', null, InputOption::VALUE_OPTIONAL, 'The snapshot to install from.')
            ->addOption('skeleton', null, InputOption::VALUE_NONE, 'Install app skeleton only')
            ->addOption('no-code', null, InputOption::VALUE_NONE, 'Do not install code')
            ->addOption('no-assets', null, InputOption::VALUE_NONE, 'Do not install assets')
            ->addOption('no-databases', null, InputOption::VALUE_NONE, 'Do not install databases')
            ->addOption('no-scripts', null, InputOption::VALUE_NONE, 'Do not run post install scripts')
            ->addOption(
                'reinstall',
                null,
                InputOption::VALUE_NONE,
                'Reinstall if already installed. This will reinstall files, database, and assets.'
            )
            ->addOption(
                'database-snapshot-format',
                null,
                InputOption::VALUE_OPTIONAL,
                sprintf(
                    'Format database was exported in. Must be "%s", "%s", or "%s".',
                    'mydumper',
                    'sql',
                    'tab'
                )
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // outputs multiple lines to the console (adding "\n" at the end of each line)
        $output->writeln(
            [
                'App: Install',
                '============',
                '',
            ]
        );

        $logger = new Logger('app:install');
        $logger->pushHandler(new MonologConsoleHandler($output));
        $shellCommandHelper = new ShellCommandHelper($logger);
        $this->parseConfigFile();

        $appIds = $this->getAppCodes($input);
        $installCode = !$input->getOption('skeleton') && !$input->getOption('no-code');
        $installAssets = !$input->getOption('skeleton') && !$input->getOption('no-assets');
        $installDatabases = !$input->getOption('skeleton') && !$input->getOption('no-databases');
        $runPostInstallScripts = !$input->getOption('skeleton') && !$input->getOption('no-scripts');
        $reinstall = $input->getOption('reinstall');

        foreach ($appIds as $appId) {
            $repo = $this->getRepo($appId);
            $config = $this->getMergedAppConfig($repo, $appId);
            $branch = $input->getOption('branch') ? $input->getOption('branch') : $config->getDefaultBranch();
            $filesystem = $input->getOption('filesystem') ? $input->getOption('filesystem')
                : $config->getDefaultFileSystem();
            $snapshotName = $input->getOption('snapshot') ? $input->getOption('snapshot')
                : $config->getDefaultSnapshotName();
            $appName = $config->getAppName();
            $workingDir = $config->getWorkingDir() ? $config->getWorkingDir() : getenv('HOME');

            $appRefreshAssets = $appRefreshDatabases = null;
            if ($installAssets && $config->getAssets()) {
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
                    false,
                    $logger
                );
            }

            if ($installDatabases && $config->getDatabases()) {
                $filesystemConfig = $config->getFilesystemConfig($filesystem);
                $sourceFilesystem = FilesystemFactory::create(
                    $filesystemConfig->getType(),
                    $filesystemConfig->getTypeSpecificConfig(),
                    $filesystemConfig->getSnapshotRoot() . "/$snapshotName/databases"
                );

                $destinationFilesystem = new Filesystem(new Local("$workingDir/.devops/app-refresh-databases"));
                $filesystemTransfer = FilesystemTransferFactory::create(
                    $sourceFilesystem,
                    $destinationFilesystem,
                    $shellCommandHelper,
                    $logger
                );

                $databaseSnapshotFormat = $input->getOption('database-snapshot-format');
                if (!$databaseSnapshotFormat) {
                    $databaseSnapshotFormat = $config->getDatabaseSnapshotFormat();
                }

                $importDatabaseAdapter = $this->getImportExportDatabaseAdapter(
                    $databaseSnapshotFormat,
                    $config,
                    $shellCommandHelper,
                    $logger
                );

                $appRefreshDatabases = new AppRefreshDatabases(
                    $filesystemTransfer,
                    $workingDir,
                    $repo,
                    $this->getDatabaseAdapter($config),
                    $importDatabaseAdapter,
                    $config->getDatabases(),
                    $config->getAppRoot(),
                    $appName,
                    $config->getFileLayout(),
                    $branch,
                    null,
                    $logger,
                    $shellCommandHelper
                );
            }

            $appInstall = new AppInstall(
                $repo,
                $config->getAppRoot(),
                $config->getFileLayout(),
                $branch,
                $config->getRepoUrl(),
                $config->getDefaultDirMode(),
                $config->getDefaultFileMode(),
                $appRefreshAssets,
                $appRefreshDatabases,
                $config->getFiles(),
                $config->getPostInstallScripts(),
                $config->getTemplateVars(),
                null,
                $logger,
                $shellCommandHelper
            );

            $output->writeln("Installing application \"$appName\"...");
            $appInstall->install($installCode, $installAssets, $installDatabases, $runPostInstallScripts, $reinstall);
            $output->writeln("<info>Application \"$appName\" installed!</info>");
        }
        return 0;
    }

}
