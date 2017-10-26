<?php

namespace DevopsToolAppOrchestration\MaintenanceStrategy;

interface MaintenanceStrategyInterface
{
    const PLATFORM_MAGENTO1 = 'magento1';
    const PLATFORM_MAGENTO2 = 'magento2';

    const STRATEGY_FILE = 'file';

    /**
     * @return void
     */
    public function enable();

    /**
     * @return void
     */
    public function disable();

    /**
     * @return bool
     */
    public function isEnabled();
}
