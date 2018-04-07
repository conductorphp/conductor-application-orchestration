<?php
/**
 * @author Kirk Madera <kmadera@robofirm.com>
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
