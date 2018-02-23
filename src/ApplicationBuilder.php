<?php
/**
 * @author Kirk Madera <kmadera@robofirm.com>
 */

namespace ConductorAppOrchestration;

use ConductorAppOrchestration\BuildCommand\BuildCommandInterface;
use ConductorCore\Filesystem\MountManager\MountManager;
use ConductorCore\ShellCommandHelper;
use FilesystemIterator;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Class ApplicationBuilder
 *
 * @package ConductorAppOrchestration
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
     * @var MountManager
     */
    private $mountManager;
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
     * @var string
     */
    private $buildPath = '/tmp/.conductor-application-builder';
    /**
     * @var bool
     */
    private $canCallBuild;
    /**
     * @var ApplicationConfig
     */
    private $applicationConfig;

    public function __construct(
        ApplicationConfig $applicationConfig,
        ShellCommandHelper $shellCommandHelper,
        FileLayoutHelper $fileLayoutHelper,
        MountManager $mountManager,
        int $diskSpaceErrorThreshold = 52428800,
        int $diskSpaceWarningThreshold = 104857600,
        LoggerInterface $logger = null
    ) {
        $this->applicationConfig = $applicationConfig;
        $this->shellCommandHelper = $shellCommandHelper;
        $this->fileLayoutHelper = $fileLayoutHelper;
        $this->mountManager = $mountManager;
        if (is_null($logger)) {
            $logger = new NullLogger();
        }
        $this->diskSpaceErrorThreshold = $diskSpaceErrorThreshold;
        $this->diskSpaceWarningThreshold = $diskSpaceWarningThreshold;
        $this->logger = $logger;
    }

    /**
     * @param string     $gitReference
     * @param string     $buildPlan
     * @param array|null $triggers
     * @param bool       $forceCleanBuild
     */
    public function buildInPlace(
        string $gitReference,
        string $buildPlan,
        array $triggers = null,
        bool $forceCleanBuild = false
    ): void {
        $this->validateCanCallBuild();
        $this->validateAppIsInstalled();

        $buildPath = $this->applicationConfig->getCodePath($gitReference);
        $this->prepareBuildPath($buildPath, true);
        chdir($buildPath);

        $buildPlanName = $buildPlan;
        $buildPlan = $this->getBuildPlan($buildPlan, $forceCleanBuild);

        if ($gitReference) {
            $this->checkoutReference($gitReference);
        }

        if ($forceCleanBuild) {
            $this->cleanBuild($buildPlan);
        }

        $this->runBuildPlan($buildPlanName, $buildPlan, $triggers);
    }

    /**
     * @param string $gitReference
     * @param string $buildPlan
     * @param string $buildId
     * @param string $savePath
     *
     * @throws \Exception
     */
    public function build(
        string $gitReference,
        string $buildPlan,
        string $buildId,
        string $savePath
    ): void {
        $this->validateCanCallBuild();
        $buildPlanName = $buildPlan;
        $buildPlan = $this->getBuildPlan($buildPlan);

        $this->prepareBuildPath($this->buildPath, false);
        chdir($this->buildPath);

        $this->cloneRepoToBuildPath($gitReference);

        try {
            // @todo How do we deal with the use case where a file that is part of the skeleton is needed to perform the
            //       build. For example, a config.rb file that is specific to Production. Should this also install the
            //       skeleton before running the build? We would have to be able to set environment also for the skeleton
            //       build. Is this really a valid use case anyways?
            $this->runBuildPlan($buildPlanName, $buildPlan);
            $this->packageAndSaveBuild($buildId, $savePath);
            $this->clearBuildPath();
        } catch (\Exception $e) {
            $this->clearBuildPath();
            throw $e;
        }
    }

    /**
     * @param string $name
     * @param bool   $forceCleanBuild Whether needing to run clean commands or not
     *
     * @throws Exception\RuntimeException if build plan is not valid
     * @return array
     */
    private function getBuildPlan(string $name, bool $forceCleanBuild = false): array
    {
        $buildPlans = $this->applicationConfig->getBuildPlans();
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
//        if ($forceCleanBuild) {
//        foreach ($buildPlan['steps'] as $index => $step) {
//            if (0 === strpos($step, '@')) {
//                $name = substr($step, 1);
//                $buildPlan['steps'][$index] = implode(' && ', $this->getBuildPlan($name)['steps']);
//            }
//        }
//
//        if ($forceCleanBuild && !empty($buildPlan['clean_steps'])) {
//            foreach ($buildPlan['clean_steps'] as $index => $step) {
//                if (0 === strpos($step, '@')) {
//                    $name = substr($step, 1);
//                    $buildPlan['clean_steps'][$index] = implode(' && ', $this->getBuildPlan($name)['steps']);
//                }
//            }
//        }
//        }

        return $buildPlan;
    }

    /**
     * @param string $buildPath
     * @param bool   $inPlace
     *
     * @throws Exception\RuntimeException if writable directory cannot be ensured; if build path is not empty; or if
     *         build path doesn't have enough free space.
     */
    private function prepareBuildPath(string $buildPath, bool $inPlace): void
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
            if (!$this->pathIsRepoClone($buildPath)) {
                throw new Exception\RuntimeException(
                    sprintf(
                        'Build path "%s" is not a git repository of app "' . $this->applicationConfig->getAppName()
                        . '". '
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
     * @param string $gitReference
     */
    private function checkoutReference(string $gitReference): void
    {
        $this->logger->info(sprintf('Checking out git reference "%s".', $gitReference));
        $this->shellCommandHelper->runShellCommand(
            'git fetch --all && git -c advice.detachedHead=false checkout ' .
            escapeshellarg($gitReference)
        );
    }

    /**
     * @param string $gitReference
     */
    private function cloneRepoToBuildPath(string $gitReference): void
    {
        $this->logger->info(
            sprintf(
                'Cloning "%s:%s".',
                $this->applicationConfig->getRepoUrl(),
                $gitReference
            )
        );
        $command = 'git clone ' . escapeshellarg($this->applicationConfig->getRepoUrl()) . ' ./ --branch '
            . escapeshellarg($gitReference)
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
        $this->runSteps($buildPlan['steps'] ?? [], $triggers);
    }

    /**
     * @param string $buildId
     * @param string $savePath
     *
     * @return void
     */
    private function packageAndSaveBuild(string $buildId, string $savePath): void
    {
        $this->logger->info('Packaging build.');
        $tarFilename = "$buildId.tgz";

        $command = 'tar -czfv ' . escapeshellarg($tarFilename) . ' ./* --exclude-vcs ';

        foreach ($this->applicationConfig->getBuildExcludePaths() as $excludePath) {
            $command .= '--exclude ' . escapeshellarg($excludePath) . ' ';
        }

        $this->shellCommandHelper->runShellCommand($command);

        $filename = realpath($tarFilename);
        $this->logger->info("Saving build to \"$savePath/$tarFilename\".");
        $this->mountManager->putFile("local://$filename", "$savePath/$tarFilename");
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
        $this->runSteps($buildPlan['clean_steps'] ?? []);
        $this->shellCommandHelper->runShellCommand('git clean -df');
    }

    /**
     * @param string $path
     *
     * @return bool
     */
    private function pathIsRepoClone(string $path): bool
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

        return $currentOriginRepoUrl == $this->applicationConfig->getRepoUrl();
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
     * @throws Exception\RuntimeException if app skeleton is not installed
     */
    private function validateAppIsInstalled(): void
    {
        $fileLayout = new FileLayout(
            $this->applicationConfig->getAppRoot(),
            $this->applicationConfig->getFileLayout(),
            $this->applicationConfig->getRelativeDocumentRoot()
        );
        $this->fileLayoutHelper->loadFileLayoutPaths($fileLayout);
        if (!$this->fileLayoutHelper->isFileLayoutInstalled($fileLayout)) {
            throw new Exception\RuntimeException(
                "Application skeleton is not yet installed. Run app:install or app:install:skeleton first."
            );
        }
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

    public function setBuildPath(string $buildPath)
    {
        $this->buildPath = $buildPath;
    }

}
