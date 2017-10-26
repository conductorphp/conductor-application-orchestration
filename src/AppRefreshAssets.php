<?php
/**
 * @author Kirk Madera <kmadera@robofirm.com>
 */

namespace DevopsToolAppOrchestration;

use Exception;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use DevopsToolCore\Filesystem\FilesystemTransferInterface;

/**
 * Class AppRefreshAssets
 *
 * @package App
 */
class AppRefreshAssets implements FileLayoutAwareInterface
{
    use FileLayoutAwareTrait;

    /**
     * @var FilesystemTransferInterface
     */
    private $filesystemSync;
    /**
     * @var array
     */
    private $assets;
    /**
     * @var string
     */
    private $defaultDirMode;
    /**
     * @var bool
     */
    private $delete;
    /**
     * @var LoggerInterface
     */
    private $logger;
    /**
     * @var FileLayoutHelper
     */
    private $fileLayoutHelper;


    /**
     * AppRefreshAssets constructor.
     *
     * @param FilesystemTransferInterface $filesystemSync
     * @param string                      $appRoot
     * @param string                      $fileLayout
     * @param string                      $branch
     * @param array                       $assets
     * @param string                      $defaultDirMode
     * @param bool                        $delete
     * @param LoggerInterface|null        $logger
     * @param FileLayoutHelper|null       $fileLayoutHelper
     */
    public function __construct(
        FilesystemTransferInterface $filesystemSync,
        $appRoot,
        $fileLayout,
        $branch,
        array $assets,
        $defaultDirMode,
        $delete = false,
        LoggerInterface $logger = null,
        FileLayoutHelper $fileLayoutHelper = null
    ) {
        $this->filesystemSync = $filesystemSync;
        $this->appRoot = $appRoot;
        $this->fileLayout = $fileLayout;
        $this->branch = $branch;
        $this->assets = $assets;
        $this->defaultDirMode = $defaultDirMode;
        $this->delete = $delete;
        if (is_null($logger)) {
            $logger = new NullLogger();
        }
        $this->logger = $logger;

        if (is_null($fileLayoutHelper)) {
            $fileLayoutHelper = new FileLayoutHelper();
        }
        $this->fileLayoutHelper = $fileLayoutHelper;
        $fileLayoutHelper->loadFileLayoutPaths($this);
    }

    /**
     * @param bool $replace
     *
     * @throws Exception
     */
    public function refreshAssets($replace = true)
    {
        if (!$this->fileLayoutHelper->isFileLayoutInstalled($this)) {
            throw new Exception("App is not yet installed. Install first before refreshing assets.");
        }

        if ($this->assets) {
            $this->logger->info('Refreshing assets...');
            foreach ($this->assets as $sourcePath => $asset) {

                if (empty($asset['ensure']) || empty($asset['location'])) {
                    throw new Exception("Asset \"$sourcePath\" must have \"ensure\" and \"location\" properties set.");
                }

                if (!empty($asset['local_path'])) {
                    $destinationPath = $asset['local_path'];
                } else {
                    $destinationPath = $sourcePath;
                }

                $pathPrefix = $this->fileLayoutHelper->resolvePathPrefix($this, $asset['location']);
                $sourcePath = "{$asset['location']}/$sourcePath";
                if ($pathPrefix) {
                    $destinationPath = "$pathPrefix/$destinationPath";
                }
                $absoluteDestinationPath = "{$this->appRoot}/$destinationPath";
                $isInstalled = ('file' == $asset['ensure'] && is_file($absoluteDestinationPath))
                    || ('directory' == $asset['ensure'] && is_dir($absoluteDestinationPath)
                        && count(
                            glob("$absoluteDestinationPath/*")
                        ) > 0);

                if ($replace || !$isInstalled) {
                    $this->logger->debug("Refreshing asset \"$destinationPath\"...");
                    switch ($asset['ensure']) {
                        case 'file':
                            $this->filesystemSync->copy($sourcePath, $destinationPath);
                            break;

                        case 'directory':
                            $this->filesystemSync->sync($sourcePath, $destinationPath, [], [], $this->delete);
                            break;

                        default:
                            throw new Exception(
                                "Asset \"$sourcePath\" has invalid \"ensure\" property set to \"{$asset['ensure']}\". Must be \"file\" or \"directory\"."
                            );
                            break;
                    }
                } else {
                    $this->logger->debug("Skipping asset \"$destinationPath\". Already installed.");
                }
            }
        } else {
            $this->logger->info('No assets to refresh.');
        }
    }

}
