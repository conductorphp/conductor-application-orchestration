<?php
/**
 * @author Kirk Madera <kmadera@robofirm.com>
 */

namespace DevopsToolAppOrchestration\Command;

use DevopsToolAppOrchestration\AppSnapshot;
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

class AppSnapshotCommand extends AbstractCommand
{
    protected function configure()
    {
        $this->setName('app:snapshot')
            ->setDescription('Creates application snapshot.')
            ->setHelp(
                "This command creates an application snapshot intended for testing purposes on lower environments."
            )
            ->addArgument('name', InputArgument::OPTIONAL, 'Snapshot name. Defaults to environment name.')
            ->addOption(
                'app',
                null,
                InputOption::VALUE_OPTIONAL,
                'Application code if you want to pull repo_url and environment from configuration'
            )
            ->addOption('all', null, InputOption::VALUE_NONE, 'Refresh assets for all apps in configuration')
            ->addOption('branch', null, InputArgument::OPTIONAL, 'The branch to install database into.')
            ->addOption('filesystem', null, InputOption::VALUE_OPTIONAL, 'The filesystem to pull snapshot from.')
            ->addOption(
                'database-snapshot-format',
                null,
                InputOption::VALUE_OPTIONAL,
                sprintf(
                    'Format to export database in. Must be "%s", "%s", or "%s".',
                    'mydumper',
                    'tab',
                    'sql'
                )
            )
            ->addOption('no-databases', null, InputOption::VALUE_NONE, 'Do not include databases in snapshot.')
            ->addOption('no-assets', null, InputOption::VALUE_NONE, 'Do not include assets in snapshot.')
            ->addOption(
                'no-scrub',
                null,
                InputOption::VALUE_NONE,
                'Do not scrub the database or assets. Use this if you need to get an exact copy of production down to a test environment.'
            )
            ->addOption(
                'delete',
                null,
                InputOption::VALUE_NONE,
                'Delete any existing snapshot by this name first before pushing.'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // outputs multiple lines to the console (adding "\n" at the end of each line)
        $output->writeln(
            [
                'App Snapshot',
                '============',
                '',
            ]
        );

        $logger = new Logger('app:snapshot');
        $logger->pushHandler(new MonologConsoleHandler($output));
        $shellCommandHelper = new ShellCommandHelper($logger);

        $this->parseConfigFile();

        $appIds = $this->getAppCodes($input);
        $includeDatabases = !$input->getOption('no-databases');
        $includeAssets = !$input->getOption('no-assets');
        $scrub = !$input->getOption('no-scrub');
        $delete = $input->getOption('delete');

        foreach ($appIds as $appId) {
            $repo = $this->getRepo($appId);
            $config = $this->getMergedAppConfig($repo, $appId);

            if ($includeDatabases) {
                $databaseSnapshotFormat = $input->getOption('database-snapshot-format');
                if (!$databaseSnapshotFormat) {
                    $databaseSnapshotFormat = $config->getDatabaseSnapshotFormat();
                }
                $databaseAdapter = $this->getImportExportDatabaseAdapter(
                    $databaseSnapshotFormat,
                    $config,
                    $shellCommandHelper,
                    $logger
                );
            } else {
                $databaseAdapter = null;
            }

            $appName = $config->getAppName();
            $filesystem = $input->getOption('filesystem') ? $input->getOption('filesystem')
                : $config->getDefaultFileSystem();
            if ($input->getArgument('name')) {
                $snapshotName = $input->getArgument('name');
            } else {
                $snapshotName = $config->getEnvironment();
                if ($scrub) {
                    $snapshotName .= '-scrubbed';
                }
            }
            $branch = $input->getOption('branch') ? $input->getOption('branch') : $config->getDefaultBranch();
            $workingDir = ($config->getWorkingDir() ? $config->getWorkingDir() : getenv('HOME'));
            $databaseFilesystemTransfer = $assetFilesystemTransfer = null;
            if ($includeAssets) {
                $filesystemConfig = $config->getFilesystemConfig($filesystem);
                $sourceFilesystem = new Filesystem(new Local($config->getAppRoot()));
                $destinationFilesystem = FilesystemFactory::create(
                    $filesystemConfig->getType(),
                    $filesystemConfig->getTypeSpecificConfig(),
                    $filesystemConfig->getSnapshotRoot() . "/$snapshotName/assets"
                );
                $assetFilesystemTransfer = FilesystemTransferFactory::create(
                    $sourceFilesystem,
                    $destinationFilesystem,
                    $shellCommandHelper,
                    $logger
                );
            }

            if ($includeDatabases) {
                $filesystemConfig = $config->getFilesystemConfig($filesystem);
                $sourceFilesystem = new Filesystem(new Local("$workingDir/.devops/app-snapshot"));
                $destinationFilesystem = FilesystemFactory::create(
                    $filesystemConfig->getType(),
                    $filesystemConfig->getTypeSpecificConfig(),
                    $filesystemConfig->getSnapshotRoot() . "/$snapshotName/databases"
                );
                $databaseFilesystemTransfer = FilesystemTransferFactory::create(
                    $sourceFilesystem,
                    $destinationFilesystem,
                    $shellCommandHelper,
                    $logger
                );
            }

            $appSnapshot = new AppSnapshot(
                $workingDir,
                $config->getAppRoot(),
                $config->getFileLayout(),
                $branch,
                $databaseAdapter,
                $databaseFilesystemTransfer,
                $config->getDatabases(),
                $config->getDatabaseTableGroups(),
                $assetFilesystemTransfer,
                $config->getAssets(),
                $config->getAssetGroups(),
                $delete,
                $logger,
                null,
                $shellCommandHelper
            );

            $output->writeln("Creating an application snapshot for application \"$appName\"...");
            $appSnapshot->createSnapshot($includeDatabases, $includeAssets, $scrub);
            $output->writeln("<info>Application snapshot created for app \"$appName\".</info>");
        }
        return 0;
    }


}
