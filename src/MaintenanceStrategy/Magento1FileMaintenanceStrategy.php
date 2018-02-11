<?php

namespace ConductorAppOrchestration\MaintenanceStrategy;

use Exception;
use League\Flysystem\FilesystemInterface;

class Magento1FileMaintenanceStrategy implements MaintenanceStrategyInterface
{
    /**
     * @var FilesystemInterface[]
     */
    private $filesystems;

    /**
     * MagentoMaintenance constructor.
     *
     * @param FilesystemInterface[] $filesystems
     */
    public function __construct(array $filesystems)
    {
        if (!$filesystems) {
            throw new Exception('$filesystems must contain at least one filesystem.');
        }
        $this->filesystems = $filesystems;
    }

    /**
     * @return void
     */
    public function enable()
    {
        foreach ($this->filesystems as $filesystem) {
            $filesystem->put('maintenance.flag', '');
        }
    }

    /**
     * @return void
     */
    public function disable()
    {
        foreach ($this->filesystems as $filesystem) {
            if ($filesystem->has('maintenance.flag')) {
                $filesystem->delete('maintenance.flag');
            }
        }
    }

    /**
     * @return bool
     */
    public function isEnabled()
    {
        foreach ($this->filesystems as $filesystem) {
            if (!$filesystem->has('maintenance.flag')) {
                return false;
            }
        }

        // Only return true if all servers have the maintenance flag set
        return true;
    }
}
