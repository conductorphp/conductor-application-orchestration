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

    /**
     * @param string|null $branch
     */
    public function destroy(string $branch = null): void
    {
        $application = $this->applicationConfig;
        $codePath = $application->getCodePath($branch);
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

        if (!$branch && $codePath != $sharedPath) {
            // Only removing shared contents because the directory may be a shared filesystem mount
            // @todo Check if dir is a mount and remove the entire directory if not
            $this->removePath("{$sharedPath}/*");
            $this->logger->debug("Removed directory \"$sharedPath\" contents.");
        }

        $fileLayout = $application->getFileLayoutStrategy();
        $appRoot = $application->getAppRoot();
        if (FileLayoutInterface::STRATEGY_BLUE_GREEN == $fileLayout
            && file_exists(
                "$appRoot/" . FileLayoutInterface::PATH_CURRENT_RELEASE
            )) {
            unlink("$appRoot/" . FileLayoutInterface::PATH_CURRENT_RELEASE);
            $this->logger->debug("Removed symlink \"$appRoot/" . FileLayoutInterface::PATH_CURRENT_RELEASE . "\".");
        }

        $databases = $this->applicationConfig->getSnapshotConfig()->getDatabases();
        if ($databases) {
            $this->logger->debug("Destroying databases.");
            $databasesToDestroy = [];
            foreach ($databases as $database => $databaseInfo) {
                if (FileLayoutInterface::STRATEGY_BRANCH == $application->getFileLayoutStrategy()) {
                    if ($branch) {
                        $database .= '_' . $this->sanitizeDatabaseName($branch);
                        $databasesToDestroy[] = $database;
                    } else {
                        $adapterName = $databaseInfo['adapter'] ?? $this->applicationConfig->getDefaultDatabaseAdapter();
                        $databaseAdapter = $this->databaseAdapterManager->getAdapter($adapterName);
                        $allDatabases = $databaseAdapter->getDatabases();
                        $appDatabases = preg_grep('%^' . $database . '_%', $allDatabases);
                        $databasesToDestroy += $appDatabases;
                    }
                } else {
                    $databasesToDestroy[] = $database;
                }
            }

            if ($databasesToDestroy) {
                foreach ($databasesToDestroy as $database) {
                    $this->logger->debug("Dropping database \"$database\".");
                    $adapterName = $databaseInfo['adapter'] ?? $this->applicationConfig->getDefaultDatabaseAdapter();
                    $databaseAdapter = $this->databaseAdapterManager->getAdapter($adapterName);
                    $databaseAdapter->dropDatabaseIfExists($database);
                }
            }
        } else {
            $this->logger->debug("No databases to destroy.");
        }
    }

    /**
     * @param string $name
     *
     * @return string
     */
    private function sanitizeDatabaseName(string $name): string
    {
        return strtolower(preg_replace('/[^a-z0-9]/i', '_', $name));
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
