<?php
/**
 * @author Kirk Madera <kmadera@robofirm.com>
 */

namespace DevopsToolAppOrchestration;

use DevopsToolCore\Filesystem\MountManager\MountManager;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Class ApplicationAssetRefresher
 *
 * @package DevopsToolAppOrchestration
 */
class ApplicationAssetRefresher
{
    /**
     * @var LoggerInterface
     */
    protected $logger;
    /**
     * @var MountManager
     */
    protected $mountManager;
    /**
     * @var FileLayoutHelper
     */
    private $fileLayoutHelper;

    public function __construct(
        FileLayoutHelper $fileLayoutHelper,
        MountManager $mountManager,
        LoggerInterface $logger = null
    ) {
        $this->mountManager = $mountManager;
        if (is_null($logger)) {
            $logger = new NullLogger();
        }
        $this->logger = $logger;
        $this->fileLayoutHelper = $fileLayoutHelper;
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
     * @param ApplicationConfig $application
     * @param string            $sourceFilesystemPrefix
     * @param string            $snapshotName
     * @param array             $syncOptions
     *
     * @throws Exception\RuntimeException if app skeleton has not yet been installed
     * @throws Exception\RuntimeException if there is an asset configuration error
     */
    public function refreshAssets(
        ApplicationConfig $application,
        string $sourceFilesystemPrefix,
        string $snapshotName,
        array $syncOptions = []
    ): void {
        $fileLayout = new FileLayout(
            $application->getAppRoot(),
            $application->getFileLayout(),
            $application->getRelativeDocumentRoot()
        );
        $this->fileLayoutHelper->loadFileLayoutPaths($fileLayout);
        if (!$this->fileLayoutHelper->isFileLayoutInstalled($fileLayout)) {
            throw new Exception\RuntimeException("App is not yet installed. Install app skeleton before refreshing assets.");
        }

        if ($application->getAssets()) {
            $this->logger->info('Refreshing assets');
            foreach ($application->getAssets() as $sourcePath => $asset) {
                if (empty($asset['ensure']) || empty($asset['location'])) {
                    throw new Exception\RuntimeException("Asset \"$sourcePath\" must have \"ensure\" and \"location\" properties set.");
                }

                if (!empty($asset['local_path'])) {
                    $destinationPath = $asset['local_path'];
                } else {
                    $destinationPath = $sourcePath;
                }

                $pathPrefix = $this->fileLayoutHelper->resolvePathPrefix($application, $asset['location']);
                $sourcePath = "snapshots/$snapshotName/assets/{$asset['location']}/$sourcePath";
                if ($pathPrefix) {
                    $destinationPath = "$pathPrefix/$destinationPath";
                };
                $destinationPath = $application->getAppRoot() . '/' . $destinationPath;

                $this->mountManager->sync(
                    "$sourceFilesystemPrefix://$sourcePath",
                    "local://$destinationPath",
                    $syncOptions
                );
            }
        } else {
            $this->logger->info('No assets specified in configuration.');
        }
    }
}
