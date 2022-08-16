<?php

namespace ConductorAppOrchestration;

interface FileLayoutInterface
{
    public const STRATEGY_BLUE_GREEN = 'blue_green';
    public const STRATEGY_DEFAULT = 'default';

    public const PATH_BUILDS = 'builds';
    public const PATH_ABSOLUTE = 'absolute';
    public const PATH_CODE = 'code';
    public const PATH_CURRENT = 'current';
    public const PATH_LOCAL = 'local';
    public const PATH_PREVIOUS = 'previous';
    public const PATH_SHARED = 'shared';
}
