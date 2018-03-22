<?php
/**
 * @author Kirk Madera <kmadera@robofirm.com>
 */

namespace ConductorAppOrchestration\Build;

use ConductorAppOrchestration\Build\Command\BuildCommandInterface;
use ConductorAppOrchestration\Config\ApplicationConfig;
use ConductorAppOrchestration\PlanRunner;
use ConductorCore\Filesystem\MountManager\MountManager;
use ConductorCore\Shell\Adapter\ShellAdapterInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Class ApplicationBuilder
 *
 * @package ConductorAppOrchestration\Build
 */
class ApplicationBuilder
{
    /**
     * @var ApplicationConfig
     */
    private $applicationConfig;
    /**
     * @var PlanRunner
     */
    private $planRunner;
    /**
     * @var ShellAdapterInterface
     */
    private $shellAdapter;
    /**
     * @var MountManager
     */
    private $mountManager;
    /**
     * @var LoggerInterface
     */
    private $logger;
    /**
     * @var string
     */
    private $planPath;

    public function __construct(
        ApplicationConfig $applicationConfig,
        PlanRunner $planRunner,
        ShellAdapterInterface $shellAdapter,
        MountManager $mountManager,
        string $planPath = '/tmp/.conductor/build',
        LoggerInterface $logger = null
    ) {
        $this->applicationConfig = $applicationConfig;
        $this->shellAdapter = $shellAdapter;
        $this->mountManager = $mountManager;
        $this->planRunner = $planRunner;
        if (is_null($logger)) {
            $logger = new NullLogger();
        }
        $this->planPath = $planPath;
        $this->logger = $logger;
    }

    /**
     * @param string $buildPlan
     * @param string $branch
     * @param string $buildId
     * @param string $savePath
     *
     * @throws \Exception
     */
    public function build(
        string $buildPlan,
        string $branch,
        string $buildId,
        string $savePath
    ): void {
        $buildPlans = $this->applicationConfig->getBuildConfig()->getPlans();
        $this->planRunner->setPlans($buildPlans);
        $this->planRunner->setPlanPath($this->planPath);
        $this->planRunner->setStepInterface(BuildCommandInterface::class);
        $this->planRunner->runPlan(
            $buildPlan,
            [],
            [
                'branch'   => $branch,
                'buildId'  => $buildId,
                'savePath' => $savePath,
            ],
            false, // @todo Any use for clean steps in build?
            false
        );
    }

    /**
     * @inheritdoc
     */
    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
        $this->planRunner->setLogger($logger);
    }

    /**
     * @param string $planPath
     */
    public function setPlanPath(string $planPath): void
    {
        $this->planPath = $planPath;
    }

}
