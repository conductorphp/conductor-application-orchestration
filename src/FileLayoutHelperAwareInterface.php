<?php
/**
 * @author Kirk Madera <kmadera@robofirm.com>
 */

namespace ConductorAppOrchestration;


interface FileLayoutHelperAwareInterface
{
    /**
     * @param FileLayoutHelper $fileLayoutHelper
     */
    public function setFileLayoutHelper(FileLayoutHelper $fileLayoutHelper): void;
}
