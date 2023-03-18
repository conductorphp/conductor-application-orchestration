<?php

namespace ConductorAppOrchestration\Deploy;

use ConductorAppOrchestration\Config\ApplicationConfig;
use ConductorAppOrchestration\Deploy\Command\DeployCommandInterface;
use ConductorAppOrchestration\Exception;
use ConductorAppOrchestration\PlanRunner;
use ConductorCore\Filesystem\MountManager\MountManager;
use ConductorCore\Shell\Adapter\ShellAdapterInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class ApplicationDeployer
{
    private ApplicationConfig $applicationConfig;
    private PlanRunner $planRunner;
    private string $planPath;
    private LoggerInterface $logger;

    public function __construct(
        ApplicationConfig     $applicationConfig,
        PlanRunner            $planRunner,
        string                $planPath = '/tmp/.conductor/deploy',
    ) {
        $this->applicationConfig = $applicationConfig;
        $this->planRunner = $planRunner;
        $this->planPath = $planPath;
    }

    public function deploySkeleton(
        string $deployPlan,
        ?string $buildId = null,
        bool   $clean = false,
        bool   $force = false
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
                'codePath' => $this->applicationConfig->getCodePath($buildId),
                'buildId' => null,
                'buildPath' => null,
                'repoReference' => null,
                'snapshotName' => null,
                'snapshotPath' => null,
                'includeAssets' => true,
                'assetSyncConfig' => [],
                'includeDatabases' => true,
                'allowFullRollback' => false,
                'options' => ['force' => $force],
            ],
            $clean,
            false,
            $force
        );
    }

    public function deploy(
        string $deployPlan,
        bool   $skeletonOnly = false,
        ?string $buildId = null,
        ?string $buildPath = null,
        ?string $repoReference = null,
        ?string $snapshotName = null,
        ?string $snapshotPath = null,
        bool   $includeAssets = true,
        array  $assetSyncConfig = [],
        bool   $includeDatabases = true,
        bool   $allowFullRollback = false,
        bool   $clean = false,
        bool   $rollback = false,
        bool   $force = false
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
                'codePath' => $this->applicationConfig->getCodePath($buildId),
                'buildId' => $buildId,
                'buildPath' => $buildPath,
                'repoReference' => $repoReference,
                'snapshotName' => $snapshotName,
                'snapshotPath' => $snapshotPath,
                'includeAssets' => $includeAssets,
                'assetSyncConfig' => $assetSyncConfig,
                'includeDatabases' => $includeDatabases,
                'allowFullRollback' => $allowFullRollback,
                'options' => ['force' => $force],
            ],
            $clean,
            $rollback
        );
    }

    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
        $this->planRunner->setLogger($logger);
    }

    public function setPlanPath(string $planPath): void
    {
        $this->planPath = $planPath;
    }

}
