<?php
/**
 * @author Kirk Madera <kmadera@robofirm.com>
 */

namespace DevopsToolAppOrchestration;

use Exception;

/**
 * Class FileLayoutHelper
 *
 * @package App
 */
class FileLayoutHelper
{
    /**
     * @todo Make this more complete
     * @return bool
     */
    public function isFileLayoutInstalled(FileLayoutAwareInterface $fileLayoutAware)
    {
        $appRoot = $fileLayoutAware->getAppRoot();
        if (is_dir($appRoot)) {
            $files = scandir($appRoot);
            $ignoredFiles = ['.', '..', 'shared', 'projectsync'];
            if (array_diff($files, $ignoredFiles)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param FileLayoutAwareInterface $fileLayoutAware
     */
    public function loadFileLayoutPaths(FileLayoutAwareInterface $fileLayoutAware)
    {
        $appRoot = $fileLayoutAware->getAppRoot();
        $fileLayout = $fileLayoutAware->getFileLayout();
        $branch = $fileLayoutAware->getBranch();
        if (!$appRoot || !$fileLayout || !$branch) {
            throw new Exception('appRoot, fileLayout, and branch must be set in $fileLayoutAware.');
        }

        if ($branch) {
            $branch = $this->sanitizeBranchForFilepath($branch);
        }

        switch ($fileLayout) {
            case FileLayoutAwareInterface::FILE_LAYOUT_BLUE_GREEN:
                $fileLayoutAware->setCodePath("$appRoot/" . FileLayoutAwareInterface::PATH_RELEASES . "/$branch");
                $fileLayoutAware->setLocalPath("$appRoot/" . FileLayoutAwareInterface::PATH_LOCAL);
                $fileLayoutAware->setSharedPath("$appRoot/" . FileLayoutAwareInterface::PATH_SHARED);
                break;

            case FileLayoutAwareInterface::FILE_LAYOUT_BRANCH:
                $fileLayoutAware->setCodePath("$appRoot/" . FileLayoutAwareInterface::PATH_BRANCHES . "/$branch");
                $fileLayoutAware->setLocalPath("$appRoot/" . FileLayoutAwareInterface::PATH_BRANCHES . "/$branch");
                $fileLayoutAware->setSharedPath("$appRoot/" . FileLayoutAwareInterface::PATH_SHARED);
                break;

            case FileLayoutAwareInterface::FILE_LAYOUT_DEFAULT:
            default:
                $fileLayoutAware->setCodePath($appRoot);
                $fileLayoutAware->setLocalPath($appRoot);
                $fileLayoutAware->setSharedPath($appRoot);
                break;
        }
    }

    /**
     * @param string $branch
     *
     * @return string
     */
    private function sanitizeBranchForFilepath($branch)
    {
        return strtolower(preg_replace('/[^a-z0-9\.-]/i', '-', $branch));
    }

    /**
     * @param FileLayoutAwareInterface $fileLayoutAware
     * @param string                   $location
     *
     * @return string
     */
    public function resolvePathPrefix(FileLayoutAwareInterface $fileLayoutAware, $location)
    {
        $appRootLength = strlen($fileLayoutAware->getAppRoot());
        $pathPrefix = null;
        switch ($location) {
            case FileLayoutAwareInterface::PATH_CODE:
                if (FileLayoutAwareInterface::FILE_LAYOUT_BLUE_GREEN == $fileLayoutAware->getFileLayout()) {
                    $pathPrefix = FileLayoutAwareInterface::PATH_CURRENT_RELEASE;
                } else {
                    $pathPrefix = ltrim(substr($fileLayoutAware->getCodePath(), $appRootLength + 1));
                }
                break;
            case FileLayoutAwareInterface::PATH_LOCAL:
                $pathPrefix = ltrim(substr($fileLayoutAware->getLocalPath(), $appRootLength + 1));
                break;
            case FileLayoutAwareInterface::PATH_SHARED:
                $pathPrefix = ltrim(substr($fileLayoutAware->getSharedPath(), $appRootLength + 1));
                break;
            default:
                throw new Exception(
                    sprintf(
                        'Invalid location "%s". Must %s, %s, or %s.',
                        $location,
                        FileLayoutAwareInterface::PATH_CODE,
                        FileLayoutAwareInterface::PATH_LOCAL,
                        FileLayoutAwareInterface::PATH_SHARED
                    )
                );
        }

        return $pathPrefix;
    }

}
