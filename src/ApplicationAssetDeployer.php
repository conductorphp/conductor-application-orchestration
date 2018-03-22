<?php
/**
 * @author Kirk Madera <kmadera@robofirm.com>
 */

namespace ConductorAppOrchestration;

use ConductorAppOrchestration\Config\ApplicationConfig;
use ConductorCore\Filesystem\MountManager\MountManager;
use ConductorCore\Shell\Adapter\LocalShellAdapter;
use ConductorCore\Shell\Adapter\ShellAdapterInterface;
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
    private $localShellAdapter;
    /**
     * @var FileLayoutHelper
     */
    private $fileLayoutHelper;
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
     * @param FileLayoutHelper     $fileLayoutHelper
     * @param MountManager         $mountManager
     * @param LoggerInterface|null $logger
     */
    public function __construct(
        ApplicationConfig $applicationConfig,
        LocalShellAdapter $localShellAdapter,
        FileLayoutHelper $fileLayoutHelper,
        MountManager $mountManager,
        LoggerInterface $logger = null
    ) {
        $this->applicationConfig = $applicationConfig;
        $this->localShellAdapter = $localShellAdapter;
        $this->fileLayoutHelper = $fileLayoutHelper;
        $this->mountManager = $mountManager;
        if (is_null($logger)) {
            $logger = new NullLogger();
        }
        $this->logger = $logger;
    }

    /**
     * @param LoggerInterface $logger
     *
     * @return void
     */
    public function setLogger(LoggerInterface $logger): void
    {
        $this->mountManager->setLogger($logger);
        $this->logger = $logger;
    }

    /**
     * @param string      $snapshotPath
     * @param string      $snapshotName
     * @param array       $assets
     * @param array       $syncOptions
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

            $pathPrefix = $this->fileLayoutHelper->resolvePathPrefix($application, $asset['location']);
            $sourcePath = "$snapshotPath/$snapshotName/assets/{$asset['location']}/$sourcePath";
            if ($pathPrefix) {
                $destinationPath = "$pathPrefix/$destinationPath";
            };
            $destinationPath = $application->getAppRoot() . '/' . $destinationPath;

            $this->mountManager->sync(
                $sourcePath,
                "local://$destinationPath",
                $syncOptions
            );
        }
    }

}
