<?php
/**
 * @author Kirk Madera <kmadera@robofirm.com>
 */

namespace ConductorAppOrchestration\Deploy;

use ConductorAppOrchestration\Config\ApplicationConfig;
use ConductorAppOrchestration\Deploy\Command\DeployCommandInterface;
use ConductorAppOrchestration\Exception;
use ConductorAppOrchestration\PlanRunner;
use ConductorCore\Filesystem\MountManager\MountManager;
use ConductorCore\Shell\Adapter\ShellAdapterInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Class ApplicationDeployer
 *
 * @package ConductorAppOrchestration
 */
class ApplicationDeployer
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
        string $planPath = '/tmp/.conductor/deploy',
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

    public function deploySkeleton(
        string $deployPlan,
        bool $clean = false
    ): void {
        $deployPlans = $this->applicationConfig->getDeployConfig()->getPlans();
        $this->planRunner->setPlans($deployPlans);
        $this->planRunner->setPlanPath($this->planPath);
        $this->planRunner->setStepInterface(DeployCommandInterface::class);

        $conditions = ['skeleton'];

        $this->planRunner->runPlan(
            $deployPlan,
            $conditions,
            [
                'codeRoot'          => $this->applicationConfig->getCodePath(),
                'buildId'           => null,
                'buildPath'         => null,
                'branch'            => null,
                'snapshotName'      => null,
                'snapshotPath'      => null,
                'includeAssets'     => true,
                'assetSyncConfig'   => [],
                'includeDatabases'  => true,
                'allowFullRollback' => false,
            ],
            $clean,
            false
        );
    }


    public function deploy(
        string $deployPlan,
        $skeletonOnly = false,
        string $buildId = null,
        string $buildPath = null,
        string $branch = null,
        string $snapshotName = null,
        string $snapshotPath = null,
        bool $includeAssets = true,
        array $assetSyncConfig = [],
        bool $includeDatabases = true,
        bool $allowFullRollback = false,
        bool $clean = false,
        bool $rollback = false
    ): void {
        if ($skeletonOnly) {
            if ($buildId || $branch || $snapshotName) {
                throw new Exception\RuntimeException(
                    'Deploying $skeletonOnly. $buildId, $branch, and $snapshotName may not be set.'
                );
            }
        }

        $deployPlans = $this->applicationConfig->getDeployConfig()->getPlans();
        $this->planRunner->setPlans($deployPlans);
        $this->planRunner->setPlanPath($this->planPath);
        $this->planRunner->setStepInterface(DeployCommandInterface::class);

        $conditions = [];
        if ($skeletonOnly) {
            $conditions[] = 'skeleton';
        }
        if ($snapshotName && $includeAssets) {
            $conditions[] = 'skeleton';
            $conditions[] = 'assets';
        }
        if ($buildId || $branch) {
            $conditions[] = 'skeleton';
            $conditions[] = 'code';
            $conditions[] = $buildId ? 'code-build' : 'code-repo';
        }
        if ($snapshotName && $includeDatabases) {
            $conditions[] = 'databases';
        }

        if (empty($conditions)) {
            $conditions[] = 'refresh';
        }

        $this->planRunner->runPlan(
            $deployPlan,
            $conditions,
            [
                'codeRoot'          => $this->applicationConfig->getCodePath(),
                'buildId'           => $buildId,
                'buildPath'         => $buildPath,
                'branch'            => $branch,
                'snapshotName'      => $snapshotName,
                'snapshotPath'      => $snapshotPath,
                'includeAssets'     => $includeAssets,
                'assetSyncConfig'   => $assetSyncConfig,
                'includeDatabases'  => $includeDatabases,
                'allowFullRollback' => $allowFullRollback,
            ],
            $clean,
            $rollback
        );
    }

    /**
     * @param LoggerInterface $logger
     *
     * @return void
     */
    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
        $this->planRunner->setLogger($logger);
    }

    public function setPlanPath(string $planPath)
    {
        $this->planPath = $planPath;
    }


}
