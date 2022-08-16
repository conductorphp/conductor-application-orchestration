<?php
/**
 * @author Kirk Madera <kirk.madera@rmgmedia.com>
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
    const STRATEGY_DEFAULT = 'default';

    const PATH_BUILDS = 'builds';
    const PATH_ABSOLUTE = 'absolute';
    const PATH_CODE = 'code';
    const PATH_CURRENT = 'current';
    const PATH_LOCAL = 'local';
    const PATH_PREVIOUS = 'previous';
    const PATH_SHARED = 'shared';
}
