<?php
/**
 * @author Kirk Madera <kmadera@robofirm.com>
 */

namespace DevopsToolAppOrchestration\Command;

use DevopsToolAppOrchestration\AppInstall;
use DevopsToolAppOrchestration\AppRefreshDatabases;
use DevopsToolCore\Filesystem\Filesystem;
use DevopsToolAppOrchestration\FilesystemFactory;
use DevopsToolCore\Filesystem\FilesystemTransferFactory;
use DevopsToolCore\MonologConsoleHandler;
use DevopsToolCore\ShellCommandHelper;
use DevopsToolCore\Database\DatabaseImportExportAdapterInterface;
use League\Flysystem\Adapter\Local;
use Monolog\Logger;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class AppRefreshDatabasesCommandold extends AbstractCommand
{
    protected function configure()
    {
        $this->setName('app:refresh-databases')
            ->setDescription('Refresh application databases.')
            ->setHelp(
                "This command refreshes application databases based on configuration in a given application setup repo."
            )
            ->addOption(
                'app',
                null,
                InputOption::VALUE_OPTIONAL,
                'Application code. May be left empty if only one app exists in configuration or specifying --all.'
            )
            ->addOption('all', null, InputOption::VALUE_NONE, 'Refresh databases for all apps in configuration')
            ->addOption('branch', null, InputArgument::OPTIONAL, 'The branch to install database into.')
            ->addOption('filesystem', null, InputOption::VALUE_OPTIONAL, 'The filesystem to pull snapshot from.')
            ->addOption('snapshot', null, InputArgument::OPTIONAL, 'The snapshot to pull databases from.')
            ->addOption(
                'format',
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
                'App: Refresh Database',
                '============',
                '',
            ]
        );

        $logger = new Logger('app:refresh-databases');
        $logger->pushHandler(new MonologConsoleHandler($output));
        $shellCommandHelper = new ShellCommandHelper($logger);

        $this->parseConfigFile();
        $appIds = $this->getAppCodes($input);

        foreach ($appIds as $appId) {
            $repo = $this->getRepo($appId);
            $config = $this->getMergedAppConfig($repo, $appId);
            $databaseAdapter = $this->getDatabaseAdapter($config);
            $databaseSnapshotFormat = $input->getOption('format');
            if (!$databaseSnapshotFormat) {
                $databaseSnapshotFormat = $config->getDatabaseSnapshotFormat();
            }

            $importDatabaseAdapter = $this->getImportExportDatabaseAdapter(
                $databaseSnapshotFormat,
                $config,
                $shellCommandHelper,
                $logger
            );
            $branch = $input->getOption('branch') ? $input->getOption('branch') : $config->getDefaultBranch();
            $filesystem = $input->getOption('filesystem') ? $input->getOption('filesystem')
                : $config->getDefaultFileSystem();
            $snapshotName = $input->getOption('snapshot') ? $input->getOption('snapshot')
                : $config->getDefaultSnapshotName();
            $workingDir = $config->getWorkingDir() ? $config->getWorkingDir() : getenv('HOME');

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

            $appRefreshDatabases = new AppRefreshDatabases(
                $filesystemTransfer,
                $workingDir,
                $repo,
                $databaseAdapter,
                $importDatabaseAdapter,
                $config->getDatabases(),
                $config->getAppRoot(),
                $config->getAppName(),
                $config->getFileLayout(),
                $branch,
                null,
                $logger,
                $shellCommandHelper
            );

            $appInstall = new AppInstall(
                $repo,
                $config->getAppRoot(),
                $config->getFileLayout(),
                $branch,
                $config->getRepoUrl(),
                $config->getDefaultDirMode(),
                $config->getDefaultFileMode(),
                null,
                $appRefreshDatabases,
                null,
                $config->getPostInstallScripts(),
                null,
                null,
                $logger,
                $shellCommandHelper
            );

            $appName = $config->getAppName();

            $output->writeln("Refreshing application \"$appName\" database...");
            $appInstall->install(false, false, true, true, true);
            $output->writeln("<info>Application \"$appName\" database refreshed!</info>");
        }
        return 0;
    }

}
