<?php

namespace DevopsToolAppOrchestration;

class FileLayout implements FileLayoutAwareInterface
{
    use FileLayoutAwareTrait;

    public function __construct(string $appRoot, string $fileLayout, string $relativeDocumentRoot, ?string $branch)
    {
        $this->appRoot = $appRoot;
        $this->fileLayout = $fileLayout;
        $this->relativeDocumentRoot = $relativeDocumentRoot;
        $this->branch = $branch;
    }
}