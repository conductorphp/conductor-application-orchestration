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
     * @param string|null $branch
     *
     * @return bool
     */
    public function codeDeployed(string $branch = null): bool;
}
