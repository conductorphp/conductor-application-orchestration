<?php
/**
 * @author Kirk Madera <kmadera@robofirm.com>
 */

namespace ConductorAppOrchestration;

use ConductorAppOrchestration\Config\ApplicationConfig;
use ConductorCore\Filesystem\MountManager\MountManager;
use ConductorCore\Shell\Adapter\LocalShellAdapter;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Class ApplicationAssetDeployer
 *
 * @package ConductorAppOrchestration
 */
class ApplicationAssetDeployer
{
    /**
     * @var ApplicationConfig
     */
    private $applicationConfig;
    /**
     * @var LocalShellAdapter
     */
    private $shellAdapter;
    /**
     * @var MountManager
     */
    protected $mountManager;
    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * ApplicationAssetDeployer constructor.
     *
     * @param ApplicationConfig    $applicationConfig
     * @param LocalShellAdapter    $localShellAdapter
     * @param MountManager         $mountManager
     * @param LoggerInterface|null $logger
     */
    public function __construct(
        ApplicationConfig $applicationConfig,
        LocalShellAdapter $localShellAdapter,
        MountManager $mountManager,
        LoggerInterface $logger = null
    ) {
        $this->applicationConfig = $applicationConfig;
        $this->shellAdapter = $localShellAdapter;
        $this->mountManager = $mountManager;
        if (is_null($logger)) {
            $logger = new NullLogger();
        }
        $this->logger = $logger;
    }

    /**
     * @param string $snapshotPath
     * @param string $snapshotName
     * @param array  $assets
     * @param array  $syncOptions
     */
    public function deployAssets(
        string $snapshotPath,
        string $snapshotName,
        array $assets,
        array $syncOptions = []
    ): void {
        if (!$assets) {
            throw new Exception\RuntimeException('No assets given for deployment.');
        }

        $application = $this->applicationConfig;
        $this->logger->info('Installing assets');
        foreach ($assets as $sourcePath => $asset) {
            if (empty($asset['ensure']) || empty($asset['location'])) {
                throw new Exception\RuntimeException(
                    "Asset \"$sourcePath\" must have \"ensure\" and \"location\" properties set."
                );
            }

            if (!empty($asset['local_path'])) {
                $destinationPath = $asset['local_path'];
            } else {
                $destinationPath = $sourcePath;
            }
            $path = $this->applicationConfig->getPath($asset['location']);
            $destinationPath = "$path/$destinationPath";

            $sourcePath = "$snapshotPath/$snapshotName/assets/{$asset['location']}/$sourcePath";

            $this->mountManager->sync(
                $sourcePath,
                "local://$destinationPath",
                $syncOptions
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
        if ($this->shellAdapter instanceof LoggerAwareInterface) {
            $this->shellAdapter->setLogger($logger);
        }
        $this->mountManager->setLogger($logger);
        $this->logger = $logger;
    }

}
