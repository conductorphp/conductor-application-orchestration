<?php
/**
 * @author Kirk Madera <kmadera@robofirm.com>
 */

namespace ConductorAppOrchestration;

/**
 * Interface FileLayoutInterface
 *
 * @package App
 */
interface FileLayoutInterface
{
    const STRATEGY_BLUE_GREEN = 'blue_green';
    const STRATEGY_BRANCH = 'branch';
    const STRATEGY_DEFAULT = 'default';

    const PATH_PREVIOUS = 'previous';
    const PATH_CURRENT = 'current';
    const PATH_RELEASES = 'releases';
    const PATH_BRANCHES = 'branches';
    const PATH_CODE = 'code';
    const PATH_LOCAL = 'local';
    const PATH_SHARED = 'shared';
}
