<?php

namespace ConductorAppOrchestration\Snapshot;

use ConductorAppOrchestration\Config\ApplicationConfig;
use ConductorAppOrchestration\PlanRunner;
use ConductorAppOrchestration\Snapshot\Command\SnapshotCommandInterface;
use ConductorCore\Database\DatabaseImportExportAdapterManager;
use ConductorCore\Filesystem\MountManager\MountManager;
use ConductorCore\Shell\Adapter\ShellAdapterInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class ApplicationSnapshotTaker
{
    private ApplicationConfig $applicationConfig;
    private DatabaseImportExportAdapterManager $databaseImportExportAdapterManager;
    private MountManager $mountManager;
    private PlanRunner $planRunner;
    private string $planPath;
    protected LoggerInterface $logger;

    public function __construct(
        ApplicationConfig                  $applicationConfig,
        DatabaseImportExportAdapterManager $databaseImportExportAdapterManager,
        MountManager                       $mountManager,
        PlanRunner                         $planRunner,
        string                             $planPath = '/tmp/.conductor/snapshot',
        ?LoggerInterface                    $logger = null
    ) {
        $this->applicationConfig = $applicationConfig;
        $this->databaseImportExportAdapterManager = $databaseImportExportAdapterManager;
        $this->mountManager = $mountManager;
        $this->planRunner = $planRunner;
        $this->planPath = $planPath;
        if (is_null($logger)) {
            $logger = new NullLogger();
        }
        $this->logger = $logger;
    }

    /**
     * @throws \Exception
     */
    public function takeSnapshot(
        string $snapshotPlan,
        string $snapshotName,
        string $snapshotPath,
        bool   $includeDatabases = true,
        bool   $includeAssets = true,
        bool   $replace = false,
        array  $assetSyncConfig = []
    ): void {
        $snapshotPlans = $this->applicationConfig->getSnapshotConfig()->getPlans();
        $this->planRunner->setPlans($snapshotPlans);
        $this->planRunner->setPlanPath($this->planPath);
        $this->planRunner->setStepInterface(SnapshotCommandInterface::class);
        $conditions = [];
        if ($includeAssets) {
            $conditions[] = 'assets';
        }
        if ($includeDatabases) {
            $conditions[] = 'databases';
        }

        $this->planRunner->runPlan(
            $snapshotPlan,
            $conditions,
            [
                'snapshotName' => $snapshotName,
                'snapshotPath' => $snapshotPath,
                'includeDatabases' => $includeDatabases,
                'includeAssets' => $includeAssets,
                'assetSyncConfig' => $assetSyncConfig,
            ],
            $replace,
        );
    }

    public function setLogger(LoggerInterface $logger): void
    {
        $this->databaseImportExportAdapterManager->setLogger($logger);
        $this->mountManager->setLogger($logger);
        $this->logger = $logger;
    }

    public function setPlanPath(string $planPath): void
    {
        $this->planPath = $planPath;
    }

}
