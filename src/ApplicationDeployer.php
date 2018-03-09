<?php
/**
 * @author Kirk Madera <kmadera@robofirm.com>
 */

namespace ConductorAppOrchestration;

use ConductorAppOrchestration\Config\ApplicationConfig;
use ConductorCore\Filesystem\MountManager\MountManager;
use ConductorCore\Shell\Adapter\LocalShellAdapter;
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
     * @var LocalShellAdapter
     */
    private $localShellAdapter;
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
     * @var ApplicationConfig
     */
    private $applicationConfig;

    public function __construct(
        ApplicationConfig $applicationConfig,
        LocalShellAdapter $localShellAdapter,
        FileLayoutHelper $fileLayoutHelper,
        MountManager $mountManager,
        int $diskSpaceErrorThreshold = 52428800,
        int $diskSpaceWarningThreshold = 104857600,
        LoggerInterface $logger = null
    ) {
        $this->applicationConfig = $applicationConfig;
        $this->localShellAdapter = $localShellAdapter;
        $this->fileLayoutHelper = $fileLayoutHelper;
        $this->mountManager = $mountManager;
        if (is_null($logger)) {
            $logger = new NullLogger();
        }
        $this->diskSpaceErrorThreshold = $diskSpaceErrorThreshold;
        $this->diskSpaceWarningThreshold = $diskSpaceWarningThreshold;
        $this->logger = $logger;
    }

    public function deploy(
        string $buildId,
        string $deployPlan
    ): void {

    }

    /**
     * @param LoggerInterface $logger
     */
    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

}
