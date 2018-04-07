<?php
/**
 * @author Kirk Madera <kmadera@robofirm.com>
 */

namespace ConductorAppOrchestration\Destroy;

use ConductorAppOrchestration\Config\ApplicationConfig;
use ConductorAppOrchestration\FileLayoutInterface;
use ConductorCore\Database\DatabaseAdapterInterface;
use ConductorCore\Database\DatabaseAdapterManager;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Class App
 *
 * @package App
 */
class ApplicationDestroyer implements LoggerAwareInterface
{
    /**
     * @var ApplicationConfig
     */
    private $applicationConfig;
    /**
     * @var LoggerInterface
     */
    protected $logger;
    /**
     * @var DatabaseAdapterInterface
     */
    protected $databaseAdapterManager;

    public function __construct(
        ApplicationConfig $applicationConfig,
        DatabaseAdapterManager $databaseAdapterManager,
        LoggerInterface $logger = null
    ) {
        $this->applicationConfig = $applicationConfig;
        $this->databaseAdapterManager = $databaseAdapterManager;
        if (is_null($logger)) {
            $logger = new NullLogger();
        }
        $this->logger = $logger;
    }

    public function destroy(): void
    {
        $application = $this->applicationConfig;
        $codePath = $application->getCodePath();
        $localPath = $application->getLocalPath();
        $sharedPath = $application->getSharedPath();

        if (file_exists($codePath)) {
            $this->removePath($codePath);
            $this->logger->debug("Removed directory \"$codePath\".");
        }

        if ($codePath != $localPath) {
            $this->removePath($localPath);
            $this->logger->debug("Removed directory \"$localPath\".");
        }

        if ($codePath != $sharedPath) {
            // Only removing shared contents because the directory may be a shared filesystem mount
            // @todo Check if dir is a mount and remove the entire directory if not
            $this->removePath("{$sharedPath}/*");
            $this->logger->debug("Removed directory \"$sharedPath\" contents.");
        }

        $fileLayout = $application->getFileLayoutStrategy();
        $appRoot = $application->getAppRoot();
        if (FileLayoutInterface::STRATEGY_BLUE_GREEN == $fileLayout
            && file_exists(
                "$appRoot/" . FileLayoutInterface::PATH_CURRENT
            )) {
            unlink("$appRoot/" . FileLayoutInterface::PATH_CURRENT);
            $this->logger->debug("Removed symlink \"$appRoot/" . FileLayoutInterface::PATH_CURRENT . "\".");
        }

        $databases = $this->applicationConfig->getSnapshotConfig()->getDatabases();
        if ($databases) {
            $this->logger->debug("Destroying databases.");

            foreach ($databases as $database => $databaseInfo) {
                $this->logger->debug("Dropping database \"$database\".");
                $adapterName = $databaseInfo['adapter'] ?? $this->applicationConfig->getDefaultDatabaseAdapter();
                $databaseAdapter = $this->databaseAdapterManager->getAdapter($adapterName);
                $databaseAdapter->dropDatabaseIfExists($database);
            }
        } else {
            $this->logger->debug("No databases to destroy.");
        }
    }

    /**
     * rmdir() will not remove the dir if it is not empty
     *
     * @param string $path
     *
     * @return void
     */
    private function removePath(string $path): void
    {
        if (false !== strpos($path, '*')) {
            $paths = glob($path);
            foreach ($paths as $path) {
                $this->removePath($path);
            }
        } else {
            if (is_dir($path)) {
                $iterator = new \RecursiveDirectoryIterator($path);
                $iterator = new \RecursiveIteratorIterator($iterator, \RecursiveIteratorIterator::CHILD_FIRST);
                /** @var \SplFileInfo $file */
                foreach ($iterator as $file) {
                    if ('.' === $file->getBasename() || '..' === $file->getBasename()) {
                        continue;
                    }
                    if ($file->isLink() || $file->isFile()) {
                        unlink($file->getPathname());
                    } else {
                        rmdir($file->getPathname());
                    }
                }
                rmdir($path);
            } else {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }

    /**
     * @inheritdoc
     */
    public function setLogger(LoggerInterface $logger): void
    {
        $this->databaseAdapterManager->setLogger($logger);
        $this->logger = $logger;
    }
}
