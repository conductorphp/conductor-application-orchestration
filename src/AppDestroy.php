<?php
/**
 * @author Kirk Madera <kmadera@robofirm.com>
 */

namespace DevopsToolAppOrchestration;

use DevopsToolCore\Database\DatabaseAdapter;
use DevopsToolCore\Filesystem\FileAdapter;
use Exception;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Class App
 *
 * @package App
 */
class AppDestroy implements FileLayoutAwareInterface
{
    use FileLayoutAwareTrait;

    /**
     * @var AppSetupRepository
     */
    protected $repo;
    /**
     * @var LoggerInterface
     */
    protected $logger;
    /**
     * @var DatabaseAdapter
     */
    protected $databaseAdapter;
    /**
     * @var FileAdapter
     */
    private $fileAdapter;
    /**
     * @var FileLayoutHelper|null
     */
    private $fileLayoutHelper;
    /**
     * @var string
     */
    private $databases;

    public function __construct(
        AppSetupRepository $repo,
        $appRoot,
        $fileLayout,
        $branch,
        $databases,
        DatabaseAdapter $databaseAdapter = null,
        FileAdapter $fileAdapter = null,
        LoggerInterface $logger = null,
        $fileLayoutHelper = null
    ) {
        $this->repo = $repo;
        $this->appRoot = $appRoot;
        $this->fileLayout = $fileLayout;
        $this->branch = $branch;
        $this->databases = $databases;
        $this->databaseAdapter = $databaseAdapter;
        if (is_null($fileAdapter)) {
            $fileAdapter = new FileAdapter();
        }
        $this->fileAdapter = $fileAdapter;
        if (is_null($logger)) {
            $logger = new NullLogger();
        }
        $this->logger = $logger;
        if (is_null($fileLayoutHelper)) {
            $fileLayoutHelper = new FileLayoutHelper();
        }
        $this->fileLayoutHelper = $fileLayoutHelper;

        $this->fileLayoutHelper->loadFileLayoutPaths($this);
    }

    /**
     * @param bool $branchOnly
     *
     * @throws Exception
     */
    public function destroy($branchOnly = false)
    {
        $this->logger->info("Destroying application file paths...");
        if (file_exists($this->codePath)) {
            $this->fileAdapter->removePath($this->codePath);
            $this->logger->debug("Removed directory \"$this->codePath\".");
        }

        if ($this->codePath != $this->localPath) {
            $this->fileAdapter->removePath($this->localPath);
            $this->logger->debug("Removed directory \"$this->localPath\".");
        }

        if (!$branchOnly && $this->codePath != $this->sharedPath) {
            // Only removing shared contents because the directory may be a shared filesystem mount
            // @todo Check if dir is a mount and remove the entire directory if not
            $this->fileAdapter->removePath("{$this->sharedPath}/*");
            $this->logger->debug("Removed directory \"$this->sharedPath\" contents.");
        }

        $fileLayout = $this->fileLayout;
        $appRoot = $this->appRoot;
        if (self::FILE_LAYOUT_BLUE_GREEN == $fileLayout && file_exists("$appRoot/current_release")) {
            unlink("$appRoot/current_release");
            $this->logger->debug("Removed symlink \"$appRoot/current_release\".");
        }

        if ($this->databases) {
            $this->logger->info("Destroying databases...");
            $databasesToDestroy = [];
            foreach ($this->databases as $database) {
                if ('branch' == $this->fileLayout) {
                    if ($branchOnly) {
                        $database .= '_' . $this->sanitizeDatabaseName($this->branch);
                        $databasesToDestroy[] = $database;
                    } else {
                        // Note: This method is not entirely safe and could potentially destroy some non-branch databases
                        //       if they follow the same naming convention.
                        $databasesToDestroy[] = $this->databaseAdapter->getDatabasesWithPrefix($database);
                    }
                } else {
                    $databasesToDestroy[] = $database;
                }
            }

            foreach ($databasesToDestroy as $database) {
                if ($this->databaseAdapter->databaseExists($database)) {
                    $this->logger->debug("Dropping database \"$database\"...");
                    $this->databaseAdapter->dropDatabase($database);
                }
            }
        } else {
            $this->logger->info("No databases to destroy.");
        }
    }

    /**
     * @param $name
     *
     * @return string
     */
    protected function sanitizeDatabaseName($name)
    {
        return strtolower(preg_replace('/[^a-z0-9]/i', '_', $name));
    }
}
