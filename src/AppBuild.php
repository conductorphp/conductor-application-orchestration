<?php

namespace DevopsToolAppOrchestration;

use DevopsToolAppOrchestration\BuildCommand\BuildCommandInterface;
use DevopsToolAppOrchestration\Exception;
use DevopsToolCore\Filesystem\FilesystemTransferInterface;
use DevopsToolCore\ShellCommandHelper;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class AppBuild
{
    /**
     * @var string
     */
    private $repoUrl;
    /**
     * @var array
     */
    private $buildPlans;
    /**
     * @var string
     */
    private $workingDir;
    /**
     * @var array
     */
    private $excludes;
    /**
     * @var FilesystemTransferInterface
     */
    private $filesystemTransfer;
    /**
     * @var LoggerInterface
     */
    private $logger;
    /**
     * @var ShellCommandHelper
     */
    private $shellCommandHelper;
    /**
     * @var int Minimum disk space in MB allowed before throwing error
     */
    private $diskSpaceErrorThreshold = 50;
    /**
     * @var int Minimum disk space in MB allowed before issuing a warning
     */
    private $diskSpaceWarningThreshold = 250;
    /**
     * @var bool
     */
    private static $canCallBuild;

    public function __construct(
        $repoUrl,
        array $buildPlans,
        $workingDir = '.',
        array $excludes = null,
        FilesystemTransferInterface $filesystemTransfer = null,
        LoggerInterface $logger = null,
        ShellCommandHelper $shellCommandHelper = null
    ) {
        $this->repoUrl = $repoUrl;
        $this->buildPlans = $buildPlans;
        $this->workingDir = $this->expandTilde($workingDir);
        $this->excludes = array_map(
            function ($value) {
                // Trailing slashes causes folders to not be excluded
                return rtrim($value, '/');
            },
            array_unique($excludes)
        );
        $this->filesystemTransfer = $filesystemTransfer;
        if (is_null($logger)) {
            $logger = new NullLogger();
        }
        $this->logger = $logger;
        $this->shellCommandHelper = $shellCommandHelper;
    }

    /**
     * @param string      $plan
     * @param string|null $branch
     * @param string|null $buildId
     * @param bool        $clean
     */
    public function build($plan, $branch = null, $buildId = null, $clean = false)
    {
        $this->validateCanCallBuild();
        $buildPlan = $this->getBuildPlan($plan, $clean);

        $this->prepareWorkingDirectory();
        chdir($this->workingDir);

        if ($this->workingDirIsRepoClone()) {
            $clearWorkingDirOnExit = false;
            if ($clean) {
                $this->cleanWorkingDir($buildPlan);
            } elseif ($buildId) {
                $this->validateWorkingDirClean();
            }

            if ($branch) {
                $this->checkoutBranch($branch);
            }
        } else {
            $clearWorkingDirOnExit = true;
            $isEmpty = !(new \FilesystemIterator($this->workingDir))->valid();
            if (!$isEmpty) {
                throw new Exception\RuntimeException(
                    sprintf(
                        'Working directory "%s" is not empty.',
                        $this->workingDir
                    )
                );
            }
            $this->cloneRepo($branch);
        }

        try {
            $this->runBuildPlan($plan, $buildPlan);

            if ($buildId) {
                $this->packageAndUploadBuild($buildId);
                if ($clearWorkingDirOnExit) {
                    $this->clearWorkingDirectory();
                }
            }
        } catch (\Exception $e) {
            if ($clearWorkingDirOnExit) {
                $this->clearWorkingDirectory();
            }
            throw $e;
        }
    }

    /**
     * @param string $name
     * @param bool   $clean Whether needing to run clean commands or not
     *
     * @throws Exception\RuntimeException if build plan is not valid
     * @return array
     */
    private function getBuildPlan($name, $clean = false)
    {
        if (empty($this->buildPlans[$name]) || !is_array($this->buildPlans[$name])) {
            throw new Exception\DomainException(
                sprintf(
                    'Invalid build plan "%s" specified.',
                    $name
                )
            );
        }

        $buildPlan = $this->buildPlans[$name];
        if (empty($buildPlan['commands']) || !is_array($buildPlan['commands'])) {
            throw new Exception\RuntimeException(
                sprintf(
                    'Build plan "%s" has missing or invalid "commands" key.',
                    $name
                )
            );
        }

        foreach ($buildPlan['commands'] as $index => $command) {
            if (0 === strpos($command, '@')) {
                $name = substr($command, 1);
                $buildPlan['commands'][$index] = implode(' && ', $this->getBuildPlan($name)['commands']);
            }
        }

        if ($clean && !empty($buildPlan['clean_commands'])) {
            foreach ($buildPlan['clean_commands'] as $index => $command) {
                if (0 === strpos($command, '@')) {
                    $name = substr($command, 1);
                    $buildPlan['clean_commands'][$index] = implode(' && ', $this->getBuildPlan($name)['commands']);
                }
            }
        }

        return $buildPlan;
    }

    /**
     * @throws Exception\RuntimeException if writable directory cannot be ensured; if working directory is not empty; or if
     *         working directory doesn't have enough free space.
     * @return void
     */
    private function prepareWorkingDirectory()
    {
        $this->logger->info('Preparing working directory...');
        if (!file_exists($this->workingDir)) {
            mkdir($this->workingDir);
        }

        if (!(is_dir($this->workingDir) && is_writable($this->workingDir))) {
            throw new Exception\RuntimeException(
                sprintf(
                    'Working directory "%s" is not a writable directory.',
                    $this->workingDir
                )
            );
        }

        $freeDiskSpace = disk_free_space($this->workingDir);
        if ($freeDiskSpace <= $this->diskSpaceErrorThreshold * 1024 * 1024) {
            throw new Exception\RuntimeException(
                sprintf(
                    'Less than %sMB of space left in directory "%s". Aborting.',
                    $this->diskSpaceErrorThreshold,
                    $this->workingDir
                )
            );
        }

        if ($freeDiskSpace <= $this->diskSpaceWarningThreshold * 1024 * 1024) {
            $this->logger->warning(
                sprintf(
                    'Less than %sMB of space left in directory "%s". Aborting.',
                    $this->diskSpaceWarningThreshold,
                    $this->workingDir
                )
            );
        }
    }

    /**
     * @param string $path
     *
     * @return string
     */
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

    /**
     * @param int $diskSpaceErrorThreshold
     *
     * @return AppBuild
     */
    public function setDiskSpaceErrorThreshold($diskSpaceErrorThreshold)
    {
        $this->diskSpaceErrorThreshold = $diskSpaceErrorThreshold;
        return $this;
    }

    /**
     * @param int $diskSpaceWarningThreshold
     *
     * @return AppBuild
     */
    public function setDiskSpaceWarningThreshold($diskSpaceWarningThreshold)
    {
        $this->diskSpaceWarningThreshold = $diskSpaceWarningThreshold;
        return $this;
    }

    /**
     * @throws Exception\RuntimeException if cannot call build
     */
    private function validateCanCallBuild()
    {
        if (is_null(self::$canCallBuild)) {
            $commands = [
                'cat',
                'git',
                'grep',
                'head',
                'sed',
            ];

            foreach ($commands as $command) {
                if (!$this->shellCommandHelper->isCallable($command)) {
                    throw new Exception\RuntimeException(
                        sprintf(
                            'Cannot use "%s" in this environment. Shell command "%s" not callable.',
                            __METHOD__,
                            $command
                        )
                    );
                }
            };
        }
    }

    /**
     * @throws Exception\RuntimeException if directory is not a git repo; if git repo origin does not match expected; or if $branch
     *                          or $build set and working directory is not clean.
     */
    private function validateWorkingDirClean()
    {
        $workingDirectoryClean = false !== strpos(
                $this->shellCommandHelper->runShellCommand('git status'),
                'working directory clean'
            );

        if (!$workingDirectoryClean) {
            throw new Exception\RuntimeException(
                sprintf(
                    'Cannot run build because working directory "%s" is not clean.',
                    $this->workingDir
                )
            );
        }
    }

    /**
     * @param string $branch
     */
    private function checkoutBranch($branch)
    {
        $this->logger->info(sprintf('Checking out branch "%s"...', $branch));
        $currentBranch = trim($this->shellCommandHelper->runShellCommand('git branch | grep \'^*\' | cut -c 3-'));
        if ($branch != $currentBranch) {
            $this->logger->info(sprintf('Checking out branch "%s"...', $branch));
            $this->shellCommandHelper->runShellCommand(
                'git fetch --all && git checkout ' .
                escapeshellarg($branch)
            );
        }
    }

    /**
     * @param $branch
     *
     * @return void
     */
    private function cloneRepo($branch)
    {
        $this->logger->info(
            sprintf(
                'Cloning "%s:%s"...',
                $this->repoUrl,
                $branch
            )
        );
        $command = 'git clone ' . escapeshellarg($this->repoUrl) . ' ./ --branch ' . escapeshellarg($branch)
            . ' --depth 1 --single-branch -v';
        $this->shellCommandHelper->runShellCommand($command);
    }

    /**
     * @param string $name
     * @param array  $buildPlan
     *
     * @return void
     */
    private function runBuildPlan($name, $buildPlan)
    {
        $this->logger->info(sprintf('Running build plan "%s"...', $name));
        $this->runCommands($buildPlan['commands']);
    }

    /**
     * @param string $buildId
     *
     * @return void
     */
    private function packageAndUploadBuild($buildId)
    {
        $this->logger->info('Packaging build...');
        $tarFilename = "$buildId.tgz";

        $command = 'tar -pcaf ' . escapeshellarg($tarFilename) . ' ./* --exclude-vcs ';

        if ($this->excludes) {
            foreach ($this->excludes as $exclude) {
                $command .= '--exclude ' . escapeshellarg($exclude) . ' ';
            }
        }

        $this->shellCommandHelper->runShellCommand($command);

        $this->logger->info('Uploading build...');
        $this->filesystemTransfer->copy($tarFilename, $tarFilename);
    }

    /**
     * @return void
     */
    private function clearWorkingDirectory()
    {
        $this->logger->info('Clearing working directory...');
        $command = 'find ' . escapeshellarg($this->workingDir) . ' -mindepth 1 -maxdepth 1 -exec rm -rf {} \;';
        $this->shellCommandHelper->runShellCommand($command);
    }

    /**
     * @param array $buildPlan
     *
     * @return void
     */
    private function cleanWorkingDir(array $buildPlan)
    {
        $this->logger->info("Cleaning working directory...");
        $this->shellCommandHelper->runShellCommand('git clean -df');

        if (empty($buildPlan['clean_commands'])) {
            return;
        }

        $this->runCommands($buildPlan['clean_commands']);
    }

    /**
     * @return bool
     */
    private function workingDirIsRepoClone()
    {
        if (!is_dir($this->workingDir)) {
            return false;
        }

        try {
            $currentOriginRepoUrl = trim(
                $this->shellCommandHelper->runShellCommand(
                    'git remote -v 2> /dev/null | grep origin | head -1 | '
                    . 'cut -c 8- | sed "s| .*$||g"'
                )
            );
        } catch (\Exception $e) {
            return false;
        }

        return $currentOriginRepoUrl == $this->repoUrl;
    }

    /**
     * @param array $commands
     *
     * @return void
     */
    private function runCommands(array $commands)
    {
        foreach ($commands as $name => $command) {
            $this->logger->info("Running command \"$name\".");
            if (0 === strpos($name, '\\')) {
                if (!class_exists($name)) {
                    throw new Exception\RuntimeException(
                        sprintf(
                            'Invalid command "%s". Could not load class.',
                            $name
                        )
                    );
                }

                if (!in_array(BuildCommandInterface::class, class_implements($name))) {
                    throw new Exception\RuntimeException(
                        sprintf(
                            'Invalid command "%s". Must implement "%s".',
                            $name,
                            BuildCommandInterface::class
                        )
                    );
                }

                $options = $command;
                /** @var BuildCommandInterface $obj */
                $obj = new $name();
                $obj->setOptions($options)
                    ->setLogger($this->logger)
                    ->run();
            } else {
                $this->shellCommandHelper->runShellCommand($command);
            }
        }
    }

}
