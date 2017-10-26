<?php
/**
 * @author Kirk Madera <kmadera@robofirm.com>
 */

namespace DevopsToolAppOrchestration;

/**
 * Interface FileLayoutAwareInterface
 *
 * @package App
 */
interface FileLayoutAwareInterface
{
    const FILE_LAYOUT_BLUE_GREEN = 'blue_green';
    const FILE_LAYOUT_BRANCH = 'branch';
    const FILE_LAYOUT_DEFAULT = 'default';

    const PATH_CURRENT_RELEASE = 'current_release';
    const PATH_RELEASES = 'releases';
    const PATH_BRANCHES = 'branches';
    const PATH_CODE = 'code';
    const PATH_LOCAL = 'local';
    const PATH_SHARED = 'shared';

    /**
     * @return string
     */
    public function getAppRoot();

    /**
     * @param boolean $userCurrentRelease
     *
     * @return string
     */
    public function getDocumentRoot($useCurrentRelease = false);

    /**
     * @return string
     */
    public function getFileLayout();

    /**
     * @return string
     */
    public function getBranch();

    /**
     * @param string $path
     *
     * @return $this
     */
    public function setCodePath($path);

    /**
     * @return string
     */
    public function getCodePath();

    /**
     * @param string $path
     *
     * @return $this
     */
    public function setLocalPath($path);

    /**
     * @return string
     */
    public function getLocalPath();

    /**
     * @param string $path
     *
     * @return $this
     */
    public function setSharedPath($path);

    /**
     * @return string
     */
    public function getSharedPath();

}
