<?php

namespace ConductorAppOrchestration;

class FileLayout implements FileLayoutAwareInterface
{
    use FileLayoutAwareTrait;

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
}
