<?php

namespace DevopsToolAppOrchestration;

class FileLayout implements FileLayoutAwareInterface
{
    use FileLayoutAwareTrait;

    public function __construct($appRoot, $fileLayout, $relativeDocumentRoot, $branch)
    {
        $this->appRoot = $appRoot;
        $this->fileLayout = $fileLayout;
        $this->relativeDocumentRoot = $relativeDocumentRoot;
        $this->branch = $branch;
    }
}
