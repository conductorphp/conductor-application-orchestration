<?php
/**
 * @author Kirk Madera <kmadera@robofirm.com>
 */

namespace DevopsToolAppOrchestration\Command;

use DevopsToolAppOrchestration\AppBuild;
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

class AppBuildCommand extends AbstractCommand
{
    protected function configure()
    {
        $this->setName('app:build')
            ->setDescription('Build application and optionally push artifacts to a filesystem.')
            ->setHelp("This command runs custom build tools and then optionally pushes artifacts to a filesystem.")
            ->addArgument(
                'plan',
                InputArgument::OPTIONAL,
                'Build plan to use.',
                'development'
            )
            ->addArgument('branch', InputArgument::OPTIONAL, 'The code branch to build.')
            ->addArgument('build-id', InputArgument::OPTIONAL, 'A unique ID for this build.')
            ->addOption(
                'app',
                null,
                InputOption::VALUE_REQUIRED,
                'Application code from configuration. Required if there is more than one app.'
            )
            ->addOption(
                'filesystem',
                null,
                InputOption::VALUE_OPTIONAL,
                'The filesystem to push the build to.'
            )
            ->addOption(
                'working-dir',
                null,
                InputOption::VALUE_REQUIRED,
                'Working directory to use when building.'
            )
            ->addOption(
                'clean',
                null,
                InputOption::VALUE_NONE,
                'Clean in-place directory before building.'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // outputs multiple lines to the console (adding "\n" at the end of each line)
        $output->writeln(
            [
                'App: Build',
                '============',
                '',
            ]
        );

        $logger = new Logger('app:build');
        $logger->pushHandler(new MonologConsoleHandler($output));
        $shellCommandHelper = new ShellCommandHelper($logger);
        $this->parseConfigFile();

        $appId = $this->getAppCode($input);
        $repo = $this->getRepo($appId);
        $config = $this->getMergedAppConfig($repo, $appId);
        $plan = $input->getArgument('plan');
        $buildId = $input->getArgument('build-id');
        $branch = $input->getArgument('branch');
        $workingDir = $input->getOption('working-dir');
        if (!$workingDir) {
            $workingDir = $buildId ? '~/.devops/app-build' : '.';
        }
        $workingDir = $this->expandTilde($workingDir);
        $clean = $input->getOption('clean');

        if ($buildId) {
            $destinationFilesystemName = $input->getOption('filesystem')
                ? $input->getOption('filesystem')
                : $config->getDefaultFileSystem();
            $destinationFilesystemConfig = $config->getFilesystemConfig($destinationFilesystemName);

            $sourceFilesystem = new Filesystem(new Local($workingDir));
            $destinationFilesystem = FilesystemFactory::create(
                $destinationFilesystemConfig->getType(),
                $destinationFilesystemConfig->getTypeSpecificConfig(),
                $destinationFilesystemConfig->getBuildRoot()
            );
            $filesystemTransfer = FilesystemTransferFactory::create(
                $sourceFilesystem,
                $destinationFilesystem,
                $shellCommandHelper,
                $logger
            );
        } else {
            $filesystemTransfer = null;
        }

        $excludes = [];
        $files = $config->getFiles();
        if ($files) {
            foreach ($files as $type => $filesOfType) {
                if (is_array($filesOfType)) {
                    $excludes = array_merge(
                        $excludes,
                        array_map(
                            function ($value) {
                                return "./$value";
                            },
                            array_keys($filesOfType)
                        )
                    );
                }
            }
        }

        $appName = $config->getAppName();
        $appBuild = new AppBuild(
            $config->getRepoUrl(),
            $config->getBuildPlans(),
            $workingDir,
            $excludes,
            $filesystemTransfer,
            $logger,
            $shellCommandHelper
        );

        $output->writeln("Building application \"$appName\"...");
        $appBuild->build($plan, $branch, $buildId, $clean);
        $message = "<info>Application \"$appName\" build complete";
        if ($buildId) {
            $message .= " and pushed to storage filesystem as build \"$buildId\"";
        }
        $message .= '!</info>';
        $output->writeln($message);
        return 0;
    }

    private function expandTilde($path)
    {
        if (false === strpos($path, '~')) {
            return $path;
        }

        if (function_exists('posix_getuid')) {
            $info = posix_getpwuid(posix_getuid());
            $home = $info['dir'];
        } else {
            $home = getenv('HOME');
        }

        return str_replace('~', $home, $path);
    }

}
