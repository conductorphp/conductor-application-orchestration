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
     * @return bool
     */
    public function isFileLayoutInstalled(FileLayoutAwareInterface $fileLayoutAware): bool
    {
        $appRoot = $fileLayoutAware->getAppRoot();
        if (!is_dir($appRoot)) {
            return false;
        }

        if (FileLayoutAwareInterface::FILE_LAYOUT_DEFAULT == $fileLayoutAware->getFileLayout()) {
            return true;
        }

        $files = scandir($appRoot);
        if (FileLayoutAwareInterface::FILE_LAYOUT_BRANCH == $fileLayoutAware->getFileLayout()) {
            $expectedPaths = [
                FileLayoutAwareInterface::PATH_BRANCHES,
                FileLayoutAwareInterface::PATH_SHARED,
            ];
        } else { // FileLayoutAwareInterface::FILE_LAYOUT_BLUE_GREEN
            $expectedPaths = [
                FileLayoutAwareInterface::PATH_CURRENT_RELEASE,
                FileLayoutAwareInterface::PATH_LOCAL,
                FileLayoutAwareInterface::PATH_RELEASES,
                FileLayoutAwareInterface::PATH_SHARED,
            ];
        }

        return count(array_intersect($expectedPaths, $files)) == count($expectedPaths);
    }

    /**
     * @todo Deprecate this method?
     * @param FileLayoutAwareInterface $fileLayoutAware
     */
    public function loadFileLayoutPaths(FileLayoutAwareInterface $fileLayoutAware): void
    {
        $appRoot = $fileLayoutAware->getAppRoot();
        $fileLayout = $fileLayoutAware->getFileLayout();
        $branch = $fileLayoutAware->getBranch();
        if (!$appRoot || !$fileLayout) {
            throw new Exception('appRoot and fileLayout must be set in $fileLayoutAware.');
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
    private function sanitizeBranchForFilepath(string $branch): string
    {
        return strtolower(preg_replace('/[^a-z0-9\.-]/i', '-', $branch));
    }

    /**
     * @param ApplicationConfig $application
     * @param string                   $location
     *
     * @return string
     */
    public function resolvePathPrefix(ApplicationConfig $application, string $location): string
    {
        $appRootLength = strlen($application->getAppRoot());
        $pathPrefix = null;
        switch ($location) {
            case FileLayoutAwareInterface::PATH_CODE:
                if (FileLayoutAwareInterface::FILE_LAYOUT_BLUE_GREEN == $application->getFileLayout()) {
                    $pathPrefix = FileLayoutAwareInterface::PATH_CURRENT_RELEASE;
                } else {
                    $pathPrefix = ltrim(substr($application->getCodePath(), $appRootLength + 1));
                }
                break;
            case FileLayoutAwareInterface::PATH_LOCAL:
                $pathPrefix = ltrim(substr($application->getLocalPath(), $appRootLength + 1));
                break;
            case FileLayoutAwareInterface::PATH_SHARED:
                $pathPrefix = ltrim(substr($application->getSharedPath(), $appRootLength + 1));
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
