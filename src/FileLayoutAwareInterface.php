<?php
/**
 * @author Kirk Madera <kmadera@robofirm.com>
 */

namespace ConductorAppOrchestration;

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
    public function getAppRoot(): string;

    /**
     * @param boolean $userCurrentRelease
     *
     * @return string
     */
    public function getDocumentRoot(bool $useCurrentRelease = false): string;

    /**
     * @return string
     */
    public function getFileLayout(): string;

    /**
     * @return string
     */
    public function getBranch(): ?string;

    /**
     * @param string $path
     *
     */
    public function setCodePath(string $path): void;

    /**
     * @return string
     */
    public function getCodePath(): string;

    /**
     * @param string $path
     *
     */
    public function setLocalPath(string $path): void;

    /**
     * @return string
     */
    public function getLocalPath(): string;

    /**
     * @param string $path
     *
     */
    public function setSharedPath(string $path): void;

    /**
     * @return string
     */
    public function getSharedPath(): string;

}
