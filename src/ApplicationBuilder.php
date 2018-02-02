<?php
/**
 * @author Kirk Madera <kmadera@robofirm.com>
 */

namespace DevopsToolAppOrchestration;

use DevopsToolAppOrchestration\BuildCommand\BuildCommandInterface;
use DevopsToolCore\ShellCommandHelper;
use FilesystemIterator;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Class ApplicationBuilder
 *
 * @package DevopsToolAppOrchestration
 */
class ApplicationBuilder
{
    const TRIGGER_CODE = 'code';
    const TRIGGER_ASSETS = 'assets';
    const TRIGGER_DATABASES = 'databases';

    /**
     * @var ShellCommandHelper
     */
    private $shellCommandHelper;
    /**
     * @var FileLayoutHelper
     */
    private $fileLayoutHelper;
    /**
     * @var int
     */
    private $diskSpaceErrorThreshold;
    /**
     * @var int
     */
    private $diskSpaceWarningThreshold;
    /**
     * @var LoggerInterface
     */
    private $logger;
    /**
     * @var bool
     */
    private $canCallBuild;

    public function __construct(
        ShellCommandHelper $shellCommandHelper,
        FileLayoutHelper $fileLayoutHelper,
        int $diskSpaceErrorThreshold = 52428800,
        int $diskSpaceWarningThreshold = 104857600,
        LoggerInterface $logger = null
    ) {
        $this->shellCommandHelper = $shellCommandHelper;
        $this->fileLayoutHelper = $fileLayoutHelper;
        if (is_null($logger)) {
            $logger = new NullLogger();
        }
        $this->diskSpaceErrorThreshold = $diskSpaceErrorThreshold;
        $this->diskSpaceWarningThreshold = $diskSpaceWarningThreshold;
        $this->logger = $logger;
    }

    /**
     * @param LoggerInterface $logger
     *
     * @return void
     */
    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * @param ApplicationConfig $application
     * @param string            $buildPlan
     * @param string            $branch
     * @param array|null        $triggers
     * @param bool              $forceCleanBuild
     */
    public function buildInPlace(
        ApplicationConfig $application,
        string $buildPlan,
        string $branch,
        array $triggers = null,
        bool $forceCleanBuild = false
    ): void {
        $this->validateCanCallBuild();
        $this->validateAppIsInstalled($application);

        $buildPath = $application->getCodePath($branch);
        $this->prepareBuildPath($buildPath, true, $application);
        chdir($buildPath);

        $buildPlanName = $buildPlan;
        $buildPlan = $this->getBuildPlan($application, $buildPlan, $forceCleanBuild);

        if ($forceCleanBuild) {
            $this->cleanBuild($buildPlan);
        }

        if ($branch) {
            $this->checkoutBranch($branch);
        }

        $this->runBuildPlan($buildPlanName, $buildPlan, $triggers);
    }

    /**
     * @param ApplicationConfig $application
     * @param string            $buildPlan
     * @param string            $branch
     * @param int               $buildId
     * @param string            $buildPath
     *
     * @throws \Exception
     */
    public function build(
        ApplicationConfig $application,
        string $buildPlan,
        string $branch,
        int $buildId,
        string $buildPath = '/tmp/.conductor-application-builder'
    ): void {
        $this->validateCanCallBuild();
        $buildPlanName = $buildPlan;
        $buildPlan = $this->getBuildPlan($application, $buildPlan);

        $this->prepareBuildPath($buildPath, false, $application);
        chdir($buildPath);

        $this->cloneRepoToBuildPath($application, $branch);

        try {
            $this->runBuildPlan($buildPlanName, $buildPlan);
            $this->packageAndUploadBuild($buildId);
            $this->clearBuildPath();
        } catch (\Exception $e) {
            $this->clearBuildPath();
            throw $e;
        }
    }

    /**
     * @param ApplicationConfig $application
     * @param string            $name
     * @param bool              $forceCleanBuild Whether needing to run clean commands or not
     *
     * @throws Exception\RuntimeException if build plan is not valid
     * @return array
     */
    private function getBuildPlan(ApplicationConfig $application, string $name, bool $forceCleanBuild = false): array
    {
        $buildPlans = $application->getBuildPlans();
        if (empty($buildPlans[$name]) || !is_array($buildPlans[$name])) {
            throw new Exception\DomainException(
                sprintf(
                    'Invalid build plan "%s" specified.',
                    $name
                )
            );
        }

        $buildPlan = $buildPlans[$name];
        if (empty($buildPlan['steps']) || !is_array($buildPlan['steps'])) {
            throw new Exception\RuntimeException(
                sprintf(
                    'Build plan "%s" has missing or invalid "steps" key.',
                    $name
                )
            );
        }

        // @todo Set up references for other commands or other build plans?
//        foreach ($buildPlan['steps'] as $index => $command) {
//            if (0 === strpos($command, '@')) {
//                $name = substr($command, 1);
//                $buildPlan['steps'][$index] = implode(' && ', $this->getBuildPlan($name)['steps']);
//            }
//        }
//
//        if ($forceCleanBuild && !empty($buildPlan['clean_steps'])) {
//            foreach ($buildPlan['clean_steps'] as $index => $command) {
//                if (0 === strpos($command, '@')) {
//                    $name = substr($command, 1);
//                    $buildPlan['clean_steps'][$index] = implode(' && ', $this->getBuildPlan($name)['steps']);
//                }
//            }
//        }

        return $buildPlan;
    }

    /**
     * @param string $buildPath
     *
     * @throws Exception\RuntimeException if writable directory cannot be ensured; if build path is not empty; or if
     *         build path doesn't have enough free space.
     */
    private function prepareBuildPath(string $buildPath, bool $inPlace, ApplicationConfig $application): void
    {
        $this->logger->info('Preparing build path.');
        if (!$inPlace && !file_exists($buildPath)) {
            mkdir($buildPath);
        }

        if (!(is_dir($buildPath) && is_writable($buildPath))) {
            throw new Exception\RuntimeException(
                sprintf(
                    'Build path "%s" is not a writable directory.',
                    $buildPath
                )
            );
        }

        if ($inPlace) {
            if (!$this->pathIsRepoClone($buildPath, $application)) {
                throw new Exception\RuntimeException(
                    sprintf(
                        'Build path "%s" is not a git repository of app "' . $application->getAppName() . '". '
                        . 'Install code before building.',
                        $buildPath
                    )
                );
            }
        } else {
            $isEmpty = !(new FilesystemIterator($buildPath))->valid();
            if (!$isEmpty) {
                throw new Exception\RuntimeException(
                    sprintf(
                        'Build path "%s" is not empty.',
                        $buildPath
                    )
                );
            }
        }

        $freeDiskSpace = disk_free_space($buildPath);
        if ($freeDiskSpace <= $this->diskSpaceErrorThreshold) {
            throw new Exception\RuntimeException(
                sprintf(
                    'Less than %sB of space left in directory "%s". Aborting.',
                    $this->diskSpaceErrorThreshold,
                    $buildPath
                )
            );
        }

        if ($freeDiskSpace <= $this->diskSpaceWarningThreshold) {
            $this->logger->warning(
                sprintf(
                    'Less than %sB of space left in directory "%s". Aborting.',
                    $this->diskSpaceWarningThreshold,
                    $buildPath
                )
            );
        }
    }

    /**
     * @throws Exception\RuntimeException if cannot call build
     */
    private function validateCanCallBuild(): void
    {
        if (is_null($this->canCallBuild)) {
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
     * @param string $branch
     */
    private function checkoutBranch(string $branch): void
    {
        $this->logger->info(sprintf('Checking out branch "%s".', $branch));
        $currentBranch = trim($this->shellCommandHelper->runShellCommand('git branch | grep \'^*\' | cut -c 3-'));
        if ($branch != $currentBranch) {
            $this->logger->info(sprintf('Checking out branch "%s".', $branch));
            $this->shellCommandHelper->runShellCommand(
                'git fetch --all && git checkout ' .
                escapeshellarg($branch)
            );
        }
    }

    /**
     * @param ApplicationConfig $application
     * @param string            $branch
     */
    private function cloneRepoToBuildPath(ApplicationConfig $application, string $branch): void
    {
        $this->logger->info(
            sprintf(
                'Cloning "%s:%s".',
                $application->getRepoUrl(),
                $branch
            )
        );
        $command = 'git clone ' . escapeshellarg($application->getRepoUrl()) . ' ./ --branch ' . escapeshellarg($branch)
            . ' --depth 1 --single-branch -v';
        $this->shellCommandHelper->runShellCommand($command);
    }

    /**
     * @param string     $name
     * @param array      $buildPlan
     * @param array|null $triggers
     */
    private function runBuildPlan(string $name, array $buildPlan, array $triggers = null): void
    {
        $this->logger->info(sprintf('Running build plan "%s".', $name));
        $this->runSteps($buildPlan['steps'], $triggers);
    }

    /**
     * @param string $buildId
     *
     * @return void
     */
    private function packageAndUploadBuild(string $buildId): void
    {
        $this->logger->info('Packaging build.');
        $tarFilename = "$buildId.tgz";

        $command = 'tar -pcaf ' . escapeshellarg($tarFilename) . ' ./* --exclude-vcs ';

        if ($this->excludes) {
            foreach ($this->excludes as $exclude) {
                $command .= '--exclude ' . escapeshellarg($exclude) . ' ';
            }
        }

        $this->shellCommandHelper->runShellCommand($command);

        $this->logger->info('Uploading build.');
        $this->filesystemTransfer->copy($tarFilename, $tarFilename);
    }

    private function clearBuildPath(): void
    {
        $this->logger->info('Clearing build path.');
        $command = 'find . -mindepth 1 -maxdepth 1 -exec rm -rf {} \;';
        $this->shellCommandHelper->runShellCommand($command);
    }

    /**
     * @param array $buildPlan
     */
    private function cleanBuild(array $buildPlan): void
    {
        $this->logger->info("Cleaning build");

        if (empty($buildPlan['clean_steps'])) {
            return;
        }

        $this->runSteps($buildPlan['clean_steps']);
        $this->shellCommandHelper->runShellCommand('git clean -df');
    }

    /**
     * @param string            $path
     * @param ApplicationConfig $application
     *
     * @return bool
     */
    private function pathIsRepoClone(string $path, ApplicationConfig $application): bool
    {
        try {
            $currentOriginRepoUrl = trim(
                $this->shellCommandHelper->runShellCommand(
                    'cd ' . escapeshellarg($path) . ' && '
                    . 'git remote -v 2> /dev/null | grep origin | head -1 | '
                    . 'cut -c 8- | sed "s| .*$||g"'
                )
            );
        } catch (\Exception $e) {
            return false;
        }

        return $currentOriginRepoUrl == $application->getRepoUrl();
    }

    /**
     * @param array $steps
     * @param array $triggers
     */
    private function runSteps(array $steps, array $triggers = null): void
    {
        foreach ($steps as $stepName => $step) {
            if ($triggers && !empty($step['triggers']) && !array_intersect($triggers, $step['triggers'])) {
                $this->logger->info(
                    sprintf(
                        'Skipped step "%s" because trigger(s) %s not met.',
                        $stepName,
                        implode(', ', $step['triggers'])
                    )
                );
                continue;
            }

            $this->logger->info("Running step \"$stepName\".");
            if (!empty($step['class'])) {
                if (!class_exists($step['class'])) {
                    throw new Exception\RuntimeException(
                        sprintf(
                            'Invalid step "%s". Could not load class %s.',
                            $stepName,
                            $step['class']
                        )
                    );
                }

                if (!in_array(BuildCommandInterface::class, class_implements($step['class']))) {
                    throw new Exception\RuntimeException(
                        sprintf(
                            'Invalid step "%s". Class %s must implement "%s".',
                            $stepName,
                            $step['class'],
                            BuildCommandInterface::class
                        )
                    );
                }

                $options = $step['options'] ?? null;
                /** @var BuildCommandInterface $obj */
                $obj = new $step['class']();
                if ($obj instanceof LoggerAwareInterface) {
                    $obj->setLogger($this->logger);
                }
                $obj->run($options);
            } elseif (!empty($step['command'])) {
                $this->shellCommandHelper->runShellCommand($step['command']);
            } else {
                throw new Exception\RuntimeException(
                    sprintf(
                        'Invalid step "%s". Key "class" or "command" must be defined in configuration.',
                        $stepName
                    )
                );
            }
        }
    }

    /**
     * @param ApplicationConfig $application
     *
     * @throws Exception\RuntimeException if app skeleton is not installed
     */
    private function validateAppIsInstalled(ApplicationConfig $application): void
    {
        $fileLayout = new FileLayout(
            $application->getAppRoot(),
            $application->getFileLayout(),
            $application->getRelativeDocumentRoot()
        );
        $this->fileLayoutHelper->loadFileLayoutPaths($fileLayout);
        if (!$this->fileLayoutHelper->isFileLayoutInstalled($fileLayout)) {
            throw new Exception\RuntimeException(
                "App is not yet installed. Install app skeleton before running a build."
            );
        }
    }

}
