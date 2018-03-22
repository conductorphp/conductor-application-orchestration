<?php

namespace ConductorAppOrchestration;

class FileLayout implements FileLayoutAwareInterface
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
     * FileLayout constructor.
     *
     * @param string      $appRoot
     * @param string      $fileLayout
     * @param string      $relativeDocumentRoot
     * @param string|null $branch
     */
    public function __construct(
        string $appRoot,
        string $fileLayout,
        string $relativeDocumentRoot,
        string $branch = null
    ) {
        $this->appRoot = $appRoot;
        $this->fileLayout = $fileLayout;
        $this->relativeDocumentRoot = $relativeDocumentRoot;
        $this->branch = $branch;
    }

    /**
     * @return string
     */
    public function getAppRoot(): string
    {
        return $this->appRoot;
    }

    /**
     * @return string
     */
    public function getRelativeDocumentRoot(): string
    {
        return $this->relativeDocumentRoot;
    }

    /**
     * @param boolean $userCurrentRelease
     *
     * @return string
     */
    public function getDocumentRoot(bool $useCurrentRelease = false): string
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
    public function getFileLayout(): string
    {
        return $this->fileLayout;
    }

    /**
     * @return string|null
     */
    public function getBranch(): ?string
    {
        return $this->branch;
    }

    /**
     * @param string $path
     *
     */
    public function setCodePath(string $path): void
    {
        $this->codePath = $path;
    }

    /**
     * @return string
     */
    public function getCodePath(): string
    {
        return $this->codePath;
    }

    /**
     * @param string $path
     *
     */
    public function setLocalPath(string $path): void
    {
        $this->localPath = $path;
    }

    /**
     * @return string
     */
    public function getLocalPath(): string
    {
        return $this->localPath;
    }

    /**
     * @param string $path
     *
     */
    public function setSharedPath(string $path): void
    {
        $this->sharedPath = $path;
    }

    /**
     * @return string
     */
    public function getSharedPath(): string
    {
        return $this->sharedPath;
    }
}
