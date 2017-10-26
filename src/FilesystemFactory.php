<?php
/**
 * @author Kirk Madera <kmadera@robofirm.com>
 */

namespace DevopsToolAppOrchestration;

use DevopsToolAppOrchestration\Config\FilesystemConfig;
use Aws\Sdk as AwsSdk;
use DevopsToolCore\Filesystem\FilesystemInterface;
use Exception;
use League\Flysystem\AwsS3v3\AwsS3Adapter;
use DevopsToolCore\Filesystem\Filesystem;

class FilesystemFactory
{
    /**
     * @param string $filesystemType
     * @param array  $config
     * @param string $prefixPath
     *
     * @return FilesystemInterface
     * @throws Exception
     */
    public static function create($filesystemType, $config, $prefixPath)
    {
        switch ($filesystemType) {

            case FilesystemConfig::FILESYSTEM_AWS_S3:
                if (empty($config['region']) || empty($config['bucket'])) {
                    throw new Exception(
                        "Config keys \"region\" and \"bucket\" must be set for \"" . FilesystemConfig::FILESYSTEM_AWS_S3
                        . "\" filesystem."
                    );
                }
                $sharedConfig = [
                    'region'  => $config['region'],
                    'version' => 'latest'
                ];

                if (!empty($config['profile'])) {
                    $sharedConfig['profile'] = $config['profile'];
                }
                $sdk = new AwsSdk($sharedConfig);
                $s3Client = $sdk->createS3();
                return new Filesystem(new AwsS3Adapter($s3Client, $config['bucket'], $prefixPath));
                break;

            default:
                throw new Exception("Unknown filesystem type \"$filesystemType\".");
                break;
        }
    }
}
