<?php
/**
 * @author Kirk Madera <kmadera@robofirm.com>
 */

namespace ConductorAppOrchestration\Snapshot;

use ConductorAppOrchestration\Config\ApplicationConfig;
use ConductorAppOrchestration\PlanRunner;
use ConductorAppOrchestration\Snapshot\Command\SnapshotCommandInterface;
use ConductorCore\Database\DatabaseImportExportAdapterManager;
use ConductorCore\Filesystem\MountManager\MountManager;
use ConductorCore\Shell\Adapter\ShellAdapterInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Class ApplicationSnapshotTaker
 *
 * @package App
 */
class ApplicationSnapshotTaker
{
    /**
     * @var ApplicationConfig
     */
    private $applicationConfig;
    /**
     * @var DatabaseImportExportAdapterManager
     */
    private $databaseImportExportAdapterManager;
    /**
     * @var MountManager
     */
    private $mountManager;
    /**
     * @var ShellAdapterInterface
     */
    private $localShellAdapter;
    /**
     * @var PlanRunner
     */
    private $planRunner;
    /**
     * @var string
     */
    private $planPath;
    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * ApplicationSnapshotTaker constructor.
     *
     * @param ApplicationConfig                  $applicationConfig
     * @param DatabaseImportExportAdapterManager $databaseImportExportAdapterManager
     * @param MountManager                       $mountManager
     * @param ShellAdapterInterface              $localShellAdapter
     * @param PlanRunner                         $planRunner
     * @param string                             $planPath
     * @param LoggerInterface|null               $logger
     */
    public function __construct(
        ApplicationConfig $applicationConfig,
        DatabaseImportExportAdapterManager $databaseImportExportAdapterManager,
        MountManager $mountManager,
        ShellAdapterInterface $localShellAdapter,
        PlanRunner $planRunner,
        string $planPath = '/tmp/.conductor/snapshot',
        LoggerInterface $logger = null
    ) {
        $this->applicationConfig = $applicationConfig;
        $this->databaseImportExportAdapterManager = $databaseImportExportAdapterManager;
        $this->mountManager = $mountManager;
        $this->localShellAdapter = $localShellAdapter;
        $this->planRunner = $planRunner;
        $this->planPath = $planPath;
        if (is_null($logger)) {
            $logger = new NullLogger();
        }
        $this->logger = $logger;
    }

    /**
     * @param string      $snapshotPlan
     * @param string      $snapshotName
     * @param string      $snapshotPath
     * @param string|null $branch
     * @param bool        $includeDatabases
     * @param bool        $includeAssets
     * @param bool        $replace
     * @param array       $assetSyncConfig
     */
    public function takeSnapshot(
        string $snapshotPlan,
        string $snapshotName,
        string $snapshotPath,
        string $branch = null,
        bool $includeDatabases = true,
        bool $includeAssets = true,
        bool $replace = false,
        array $assetSyncConfig = []
    ) {
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
                'snapshotName'     => $snapshotName,
                'snapshotPath'     => $snapshotPath,
                'branch'           => $branch,
                'includeDatabases' => $includeDatabases,
                'includeAssets'    => $includeAssets,
                'assetSyncConfig'  => $assetSyncConfig,
            ],
            $replace,
            false
        );
    }

    /**
     * @param LoggerInterface $logger
     */
    public function setLogger(LoggerInterface $logger): void
    {
        $this->databaseImportExportAdapterManager->setLogger($logger);
        $this->mountManager->setLogger($logger);
        $this->logger = $logger;
    }

}
