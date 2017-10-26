<?php
/**
 * @author Kirk Madera <kmadera@robofirm.com>
 */

namespace DevopsToolAppOrchestration;

/**
 * Class FileLayoutAwareTrait
 *
 * @package App
 */
trait FileLayoutAwareTrait
{
    /**
     * @var string
     */
    private $appRoot;

    /**
     * @var string
     */
    private $relativeDocumentRoot;

    /**
     * @var string
     */
    private $fileLayout;

    /**
     * @var string
     */
    private $branch;

    /**
     * @var string
     */
    private $codePath;

    /**
     * @var string
     */
    private $localPath;

    /**
     * @var string
     */
    private $sharedPath;

    /**
     * @return string
     */
    public function getAppRoot()
    {
        return $this->appRoot;
    }

    /**
     * @return string
     */
    public function getRelativeDocumentRoot()
    {
        return $this->relativeDocumentRoot;
    }

    /**
     * @param boolean $userCurrentRelease
     *
     * @return string
     */
    public function getDocumentRoot($useCurrentRelease = false)
    {
        if (FileLayoutAwareInterface::FILE_LAYOUT_BLUE_GREEN == $this->fileLayout && $useCurrentRelease) {
            $documentRoot = $this->appRoot . '/' . FileLayoutAwareInterface::PATH_CURRENT_RELEASE;
        } else {
            $documentRoot = $this->codePath;
        }
        if (!empty($this->relativeDocumentRoot)) {
            $documentRoot .= '/' . $this->relativeDocumentRoot;
        }
        return $documentRoot;
    }

    /**
     * @return string
     */
    public function getFileLayout()
    {
        return $this->fileLayout;
    }

    /**
     * @return string
     */
    public function getBranch()
    {
        return $this->branch;
    }

    /**
     * @param string $path
     *
     * @return $this
     */
    public function setCodePath($path)
    {
        $this->codePath = $path;
        return $this;
    }

    /**
     * @return string
     */
    public function getCodePath()
    {
        return $this->codePath;
    }

    /**
     * @param string $path
     *
     * @return $this
     */
    public function setLocalPath($path)
    {
        $this->localPath = $path;
        return $this;
    }

    /**
     * @return string
     */
    public function getLocalPath()
    {
        return $this->localPath;
    }

    /**
     * @param string $path
     *
     * @return $this
     */
    public function setSharedPath($path)
    {
        $this->sharedPath = $path;
        return $this;
    }

    /**
     * @return string
     */
    public function getSharedPath()
    {
        return $this->sharedPath;
    }


}
