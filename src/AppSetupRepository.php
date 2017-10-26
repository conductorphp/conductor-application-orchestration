<?php
/**
 * @author Kirk Madera <kmadera@robofirm.com>
 */

namespace DevopsToolAppOrchestration;

use Exception;
use GitElephant\Objects\Branch;
use GitElephant\Objects\Tree;
use GitElephant\Objects\TreeishInterface;
use GitElephant\Repository;
use Symfony\Component\Yaml\Yaml;

class AppSetupRepository
{
    /** @var Repository */
    protected $repo;
    /** @var string */
    protected $environment;
    /** @var string */
    protected $role;
    /** @var array */
    protected $defaultConfig;

    /**
     * AppSetupRepository constructor.
     *
     * @param Repository $repo
     * @param string     $environment
     * @param string     $role
     * @param array      $defaultConfig
     */
    public function __construct(Repository $repo, $environment, $role = 'web', array $defaultConfig = [])
    {
        $this->repo = $repo;
        $this->environment = $environment;
        $this->role = $role;
        $this->defaultConfig = $defaultConfig;
    }

    public function getConfig()
    {
        $environmentRoleConfig = $environmentConfig = $roleConfig = $globalConfig = [];
        if ($this->hasFile("config.yaml", $this->environment, $this->role)) {
            $environmentRoleConfig = Yaml::parse(
                $this->getFileContents("config.yaml", $this->environment, $this->role)
            );
        }

        if ($this->hasFile("config.yaml", $this->environment)) {
            $environmentConfig = Yaml::parse($this->getFileContents("config.yaml", $this->environment));
        }

        if ($this->hasFile("config.yaml", null, $this->role)) {
            $roleConfig = Yaml::parse($this->getFileContents("config.yaml", null, $this->role));
        }

        if ($this->hasFile("config.yaml")) {
            $globalConfig = Yaml::parse($this->getFileContents("config.yaml"));
        }

        $appConfig = array_replace_recursive($globalConfig, $roleConfig, $environmentConfig, $environmentRoleConfig);
        $defaultConfig = $this->defaultConfig;
        if (!empty($appConfig['platform']) && !empty($this->defaultConfig['platforms'][$appConfig['platform']])) {
            $platformDefaultConfig = $this->defaultConfig['platforms'][$appConfig['platform']];
        } else {
            $platformDefaultConfig = [];
        }
        unset($defaultConfig['platforms']);

        return array_replace_recursive($defaultConfig, $platformDefaultConfig, $appConfig);
    }

    /**
     * @param string                $filename
     * @param TreeishInterface|null $tree
     *
     * @return bool|string
     */
    public function getFileContentsInHierarchy($filename, TreeishInterface $tree = null)
    {
        if ($this->environment) {
            if ($this->role && $this->hasFile($filename, $this->environment, $this->role)) {
                return $this->getFileContents($filename, $this->environment, $this->role);
            }

            if ($this->hasFile($filename, $this->environment)) {
                return $this->getFileContents($filename, $this->environment);
            }
        }

        if ($this->hasFile($filename)) {
            return $this->getFileContents($filename);
        }

        return false;
    }

    /**
     * @todo Remove error suppression. This is necessary because GitElephant\Repository and GitElephant\Objects\Object
     *       attempt to read preg_match indexes which are not set resulting in notices
     *
     * @param string           $filename
     * @param string           $environment
     * @param string           $role
     * @param string           $filename
     * @param TreeishInterface $tree
     *
     * @return bool
     */
    public function hasFile($filename, $environment = null, $role = null, TreeishInterface $tree = null)
    {
        if (is_null($tree)) {
            $tree = new Branch($this->repo, 'master');
        }

        if ($role) {
            $filename = "roles/$role/$filename";
        }

        if ($environment) {
            $filename = "environments/$environment/$filename";
        }

        $binaryFile = @$this->repo->getTree($tree->getSha(), $filename);
        if (@$binaryFile->getObject()->getSize() > 0) {
            if ($this->isSymlink($binaryFile)) {
                $target = $this->resolveSymlink($binaryFile, $tree);
                return $this->hasFile($target, $tree);
            }
            return true;
        }

        return false;
    }

    /**
     * @todo Remove error suppression. This is necessary because GitElephant\Repository and GitElephant\Objects\Object
     *       attempt to read preg_match indexes which are not set resulting in notices
     *
     * @param string           $filename
     * @param string           $environment
     * @param string           $role
     * @param TreeishInterface $tree
     *
     * @return string
     * @throws Exception
     */
    public function getFileContents($filename, $environment = null, $role = null, TreeishInterface $tree = null)
    {
        if (is_null($tree)) {
            $tree = new Branch($this->repo, 'master');
        }

        if ($role) {
            $filename = "roles/$role/$filename";
        }

        if ($environment) {
            $filename = "environments/$environment/$filename";
        }

        $binaryFile = @$this->repo->getTree($tree->getSha(), $filename);
        if (@$binaryFile->getObject()->getSize() > 0) {
            if ($binaryFile->isBlob()) {
                // If symlink, load symlinked file
                if ($this->isSymlink($binaryFile)) {
                    $target = $this->resolveSymlink($binaryFile, $tree);
                    return $this->getFileContents($target, $tree);
                }
                return $this->repo->outputRawContent($binaryFile->getBlob(), $tree);
            }

            // @todo What to do with binary files and links?
            if ($binaryFile->isBinary()) {
                throw new Exception("Getting content of binary files from repo is not yet supported.");
            }

            if ($binaryFile->isLink()) {
                throw new Exception("Getting content of links from repo is not yet supported.");
            }

            throw new Exception("Unknown file type.");
        }

        throw new Exception("File \"$filename\" does not exist.");
    }

    protected function isSymlink(Tree $binaryFile)
    {
        return $binaryFile->isBlob() && 120000 == $binaryFile->getObject()->getPermissions();
    }

    protected function resolveSymlink(Tree $binaryFile, TreeishInterface $tree)
    {
        $target = $this->repo->outputRawContent($binaryFile->getBlob(), $tree);
        $numDirsUp = substr_count($target, '../');
        $basePath = dirname($binaryFile->getObject()->getFullPath());
        for ($i = 0; $i < $numDirsUp; $i++) {
            if (false === strpos($basePath, '/')) {
                $basePath = '';
                break;
            }
            $basePath = substr($basePath, strpos($basePath, '/') + 1);
        }
        $target = str_replace('../', '', $target);
        if ($basePath) {
            $target = "$basePath/$target";
        }
        return $target;
    }
}
