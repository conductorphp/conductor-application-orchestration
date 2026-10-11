<?php

declare(strict_types=1);

namespace ConductorAppOrchestration\Exception;

/**
 * `application.platform` names a platform that no installed platform-support module registers.
 */
class PlatformNotInstalledException extends RuntimeException
{
}
