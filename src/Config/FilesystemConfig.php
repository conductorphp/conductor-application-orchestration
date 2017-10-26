<?php
/**
 * @author Kirk Madera <kmadera@robofirm.com>
 */

namespace DevopsToolAppOrchestration\Config;

use Exception;

class FilesystemConfig
{
    const FILESYSTEM_AWS_S3 = 'aws_s3';

    /** @var array */
    private $config;

    /**
     * AppConfig constructor.
     *
     * @param array $config
     */
    public function __construct(array $config)
    {
        $config = $this->filter($config);
        $this->validate($config);
        $this->config = $config;
    }

    /**
     * @param array $config
     *
     * @return array
     */
    private function filter(array $config)
    {
        return $config;
    }

    /**
     * @param array $config
     *
     * @throws Exception
     */
    private function validate(array $config)
    {
        $required = [
            'type',
        ];
        $missingRequired = [];
        foreach ($required as $name) {
            if (empty($config[$name])) {
                $missingRequired[] = $name;
            }
        }
        if ($missingRequired) {
            throw new Exception(
                'Missing required values for top level configuration key(s): "' . implode('", "', $missingRequired)
                . '"'
            );
        }
    }

    /**
     * @return string
     */
    public function getType()
    {
        return $this->config['type'];
    }

    /**
     * @return string
     */
    public function getSnapshotRoot()
    {
        return !empty($this->config['snapshot_root']) ? $this->config['snapshot_root'] : 'snapshots';
    }

    /**
     * @return string
     */
    public function getBuildRoot()
    {
        return !empty($this->config['build_root']) ? $this->config['build_root'] : 'builds';
    }

    /**
     * @return array
     */
    public function getTypeSpecificConfig()
    {
        return array_diff_key($this->config, array_flip(['type', 'snapshot_root', 'build_root']));
    }

}
