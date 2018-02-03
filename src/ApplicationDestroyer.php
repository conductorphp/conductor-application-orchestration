<?php
/**
 * @author Kirk Madera <kmadera@robofirm.com>
 */

namespace DevopsToolAppOrchestration;

use DevopsToolCore\Database\DatabaseAdapterInterface;
use DevopsToolCore\Database\DatabaseAdapterManager;
use Exception;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Class App
 *
 * @package App
 */
class ApplicationDestroyer
{
    /**
     * @var LoggerInterface
     */
    protected $logger;
    /**
     * @var DatabaseAdapterInterface
     */
    protected $databaseAdapterManager;

    public function __construct(
        DatabaseAdapterManager $databaseAdapterManager,
        LoggerInterface $logger = null
    ) {
        $this->databaseAdapterManager = $databaseAdapterManager;
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
        $this->logger = $logger;
    }

    /**
     * @throws Exception
     */
    public function destroy(ApplicationConfig $application, string $branch = null): void
    {
        $codePath = $application->getCodePath($branch);
        $localPath = $application->getLocalPath($branch);
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

        $fileLayout = $application->getFileLayout();
        $appRoot = $application->getAppRoot();
        if (ApplicationConfig::FILE_LAYOUT_BLUE_GREEN == $fileLayout && file_exists("$appRoot/" . ApplicationConfig::PATH_CURRENT_RELEASE)) {
            unlink("$appRoot/" . ApplicationConfig::PATH_CURRENT_RELEASE);
            $this->logger->debug("Removed symlink \"$appRoot/" . ApplicationConfig::PATH_CURRENT_RELEASE . "\".");
        }

        if ($application->getDatabases()) {
            $this->logger->debug("Destroying databases.");
            $databasesToDestroy = [];
            foreach ($application->getDatabases() as $database => $databaseInfo) {
                if ('branch' == $application->getFileLayout()) {
                    if ($branch) {
                        $database .= '_' . $this->sanitizeDatabaseName($branch);
                        $databasesToDestroy[] = $database;
                    } else {
                        $allDatabases = $this->databaseAdapterManager->getDatabases();
                        $appDatabases  = preg_grep('%^' . $database . '_%', $allDatabases);
                        $databasesToDestroy += $appDatabases;
                    }
                } else {
                    $databasesToDestroy[] = $database;
                }
            }

            // @todo Add ability to use different database adapters per database. For example, one app may use a MySQL db
            //       and a Mongo db
            $databaseAdapterName = $application->getDefaultDatabaseAdapter();
            $databaseAdapter = $this->databaseAdapterManager->getAdapter($databaseAdapterName);

            foreach ($databasesToDestroy as $database) {
                $this->logger->debug("Dropping database \"$database\".");
                $databaseAdapter->dropDatabaseIfExists($database);
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
}
