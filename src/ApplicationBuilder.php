<?php
/**
 * @author Kirk Madera <kmadera@robofirm.com>
 */

namespace ConductorAppOrchestration;

use ConductorAppOrchestration\BuildCommand\BuildCommandInterface;
use ConductorAppOrchestration\Config\ApplicationConfig;
use ConductorCore\Filesystem\MountManager\MountManager;
use ConductorCore\Shell\Adapter\ShellAdapterInterface;
use FilesystemIterator;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Class ApplicationBuilder
 *
 * @package ConductorAppOrchestration
 */
class ApplicationBuilder
{
    /**
     * @var ShellAdapterInterface
     */
    private $shellAdapter;
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
    private $buildPath;
    /**
     * @var ApplicationConfig
     */
    private $applicationConfig;
    /**
     * @var StepRunner
     */
    private $stepRunner;

    public function __construct(
        ApplicationConfig $applicationConfig,
        ShellAdapterInterface $shellAdapter,
        FileLayoutHelper $fileLayoutHelper,
        MountManager $mountManager,
        StepRunner $stepRunner,
        int $diskSpaceErrorThreshold = 52428800,
        int $diskSpaceWarningThreshold = 104857600,
        string $buildPath = '/tmp/.conductor/build',
        LoggerInterface $logger = null
    ) {
        $this->applicationConfig = $applicationConfig;
        $this->shellAdapter = $shellAdapter;
        $this->fileLayoutHelper = $fileLayoutHelper;
        $this->mountManager = $mountManager;
        $stepRunner->setExpectedClassInterface(BuildCommandInterface::class);
        $this->stepRunner = $stepRunner;
        if (is_null($logger)) {
            $logger = new NullLogger();
        }
        $this->diskSpaceErrorThreshold = $diskSpaceErrorThreshold;
        $this->diskSpaceWarningThreshold = $diskSpaceWarningThreshold;
        $this->buildPath = $buildPath;
        $this->logger = $logger;
    }

    /**
     * @param string $buildPlan
     * @param string $repoReference
     * @param string $buildId
     * @param string $savePath
     *
     * @throws \Exception
     */
    public function build(
        string $buildPlan,
        string $repoReference,
        string $buildId,
        string $savePath
    ): void {
        $buildPlanName = $buildPlan;
        $buildPlan = $this->getBuildPlan($buildPlan);

        $this->prepareBuildPath($this->buildPath);
        chdir($this->buildPath);

        try {
            $this->logger->info(sprintf('Running build plan "%s".', $buildPlanName));
            $this->stepRunner->runSteps(
                $buildPlan['steps'],
                ['repoReference' => $repoReference, 'buildId' => $buildId]
            );
            $this->packageAndSaveBuild($buildId, $buildPlan['excludes'], $savePath);
            $this->clearBuildPath();
        } catch (\Exception $e) {
            $this->clearBuildPath();
            throw $e;
        }
    }

    /**
     * @param string $name
     *
     * @throws Exception\RuntimeException if build plan is not valid
     * @return array
     */
    private function getBuildPlan(string $name): array
    {
        $buildPlans = $this->applicationConfig->getBuildConfig()->getPlans();
        if (empty($buildPlans[$name]) || !is_array($buildPlans[$name])) {
            throw new Exception\DomainException(
                sprintf(
                    'Invalid build plan "%s" specified.',
                    $name
                )
            );
        }

        $buildPlan = $this->validateAndNormalizeBuildPlan($buildPlans[$name]);
        return $buildPlan;
    }

    /**
     * @param string $buildPath
     *
     * @throws Exception\RuntimeException if writable directory cannot be ensured; if build path is not empty; or if
     *         build path doesn't have enough free space.
     */
    private function prepareBuildPath(string $buildPath): void
    {
        $this->logger->info('Preparing build path.');
        if (!file_exists($buildPath)) {
            mkdir($buildPath, 0777, true);
        }

        if (!(is_dir($buildPath) && is_writable($buildPath))) {
            throw new Exception\RuntimeException(
                sprintf(
                    'Build path "%s" is not a writable directory.',
                    $buildPath
                )
            );
        }

        $isEmpty = !(new FilesystemIterator($buildPath))->valid();
        if (!$isEmpty) {
            throw new Exception\RuntimeException(
                sprintf(
                    'Build path "%s" is not empty. Ensure path is empty, then run this command again.',
                    $buildPath
                )
            );
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
     * @param string $buildId
     * @param array  $excludes
     * @param string $savePath
     *
     * @return void
     */
    private function packageAndSaveBuild(string $buildId, array $excludes, string $savePath): void
    {
        $this->logger->info('Packaging build.');
        $tarFilename = "$buildId.tgz";

        $command = 'tar -czv -f ' . escapeshellarg($tarFilename) . ' ./* --exclude-vcs ';

        if (!empty($excludes)) {
            foreach ($excludes as $excludePath) {
                $command .= '--exclude ' . escapeshellarg($excludePath) . ' ';
            }
        }

        $this->shellAdapter->runShellCommand($command);

        $filename = realpath($tarFilename);
        $this->logger->info("Saving build to \"$savePath/$tarFilename\".");
        $this->mountManager->putFile("local://$filename", "$savePath/$tarFilename");
    }

    private function clearBuildPath(): void
    {
        $this->logger->info('Clearing build path.');
        $command = 'find . -mindepth 1 -maxdepth 1 -exec rm -rf {} \;';
        $this->shellAdapter->runShellCommand($command);
    }

    /**
     * @param LoggerInterface $logger
     *
     * @return void
     */
    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
        $this->stepRunner->setLogger($logger);
    }

    public function setBuildPath(string $buildPath)
    {
        $this->buildPath = $buildPath;
    }

    /**
     * @param array $buildPlan
     *
     * @return array
     *
     */
    private function validateAndNormalizeBuildPlan(array $buildPlan): array
    {
        if (empty($buildPlan['steps'])) {
            throw new Exception\RuntimeException(
                'Build plan "steps" key must be set.'
            );
        }

        $normalizedBuildPlan = [
            'steps'    => [],
            'excludes' => $buildPlan['excludes'] ?? [],
        ];

        $normalizedBuildPlan['steps'] = $this->stepRunner->validateAndNormalizeSteps($buildPlan['steps']);
        return $normalizedBuildPlan;
    }
}
