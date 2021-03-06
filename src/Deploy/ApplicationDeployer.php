<?php
/**
 * @author Kirk Madera <kirk.madera@rmgmedia.com>
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
 * @package ConductorAppOrchestration\Deploy
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

    /**
     * ApplicationDeployer constructor.
     *
     * @param ApplicationConfig     $applicationConfig
     * @param PlanRunner            $planRunner
     * @param ShellAdapterInterface $shellAdapter
     * @param MountManager          $mountManager
     * @param string                $planPath
     * @param LoggerInterface|null  $logger
     */
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

    /**
     * @param string $deployPlan
     * @param string|null $buildId
     * @param bool   $clean
     */
    public function deploySkeleton(
        string $deployPlan,
        string $buildId = null,
        bool $clean = false,
        bool $force = false
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
                'codePath'          => $this->applicationConfig->getCodePath($buildId),
                'buildId'           => null,
                'buildPath'         => null,
                'repoReference'     => null,
                'snapshotName'      => null,
                'snapshotPath'      => null,
                'includeAssets'     => true,
                'assetSyncConfig'   => [],
                'includeDatabases'  => true,
                'allowFullRollback' => false,
                'options'           => ['force' => $force],
            ],
            $clean,
            false,
            $force
        );
    }

    /**
     * @param string      $deployPlan
     * @param bool        $skeletonOnly
     * @param string|null $buildId
     * @param string|null $buildPath
     * @param string|null $repoReference
     * @param string|null $snapshotName
     * @param string|null $snapshotPath
     * @param bool        $includeAssets
     * @param array       $assetSyncConfig
     * @param bool        $includeDatabases
     * @param bool        $allowFullRollback
     * @param bool        $clean
     * @param bool        $rollback
     */
    public function deploy(
        string $deployPlan,
        bool $skeletonOnly = false,
        string $buildId = null,
        string $buildPath = null,
        string $repoReference = null,
        string $snapshotName = null,
        string $snapshotPath = null,
        bool $includeAssets = true,
        array $assetSyncConfig = [],
        bool $includeDatabases = true,
        bool $allowFullRollback = false,
        bool $clean = false,
        bool $rollback = false,
        bool $force = false
    ): void {

        $deployPlans = $this->applicationConfig->getDeployConfig()->getPlans();
        $this->planRunner->setPlans($deployPlans);
        $this->planRunner->setPlanPath($this->planPath);
        $this->planRunner->setStepInterface(DeployCommandInterface::class);

        if ($skeletonOnly && $snapshotName) {
            throw new Exception\RuntimeException('$snapshotName may not be set with $skeletonOnly.');
        }

        $conditions = [];
        if ($skeletonOnly) {
            $conditions[] = 'skeleton';
        }

        if ($snapshotName && $includeAssets) {
            $conditions[] = 'skeleton';
            $conditions[] = 'assets';
        }

        if ($buildId || $repoReference) {
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
                'codePath'          => $this->applicationConfig->getCodePath($buildId),
                'buildId'           => $buildId,
                'buildPath'         => $buildPath,
                'repoReference'     => $repoReference,
                'snapshotName'      => $snapshotName,
                'snapshotPath'      => $snapshotPath,
                'includeAssets'     => $includeAssets,
                'assetSyncConfig'   => $assetSyncConfig,
                'includeDatabases'  => $includeDatabases,
                'allowFullRollback' => $allowFullRollback,
                'options'           => ['force' => $force],
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

    /**
     * @param string $planPath
     */
    public function setPlanPath(string $planPath): void
    {
        $this->planPath = $planPath;
    }

}
