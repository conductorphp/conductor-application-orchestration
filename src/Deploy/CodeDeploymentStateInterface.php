<?php
/**
 * @author Kirk Madera <kirk.madera@rmgmedia.com>
 */

namespace ConductorAppOrchestration\Deploy;

/**
 * Class CodeDeploymentStateInterface
 *
 * @package ConductorAppOrchestration\Deploy
 */
interface CodeDeploymentStateInterface
{
    /**
     * @return bool
     */
    public function codeDeployed(): bool;
}
