<?php

namespace ConductorAppOrchestration\Build\Command;

use ConductorAppOrchestration\Exception;
use ConductorCore\Filesystem\MountManager\MountManager;
use ConductorCore\Filesystem\MountManager\MountManagerAwareInterface;
use League\Flysystem\FilesystemException;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class SaveBuildCommand
    implements BuildCommandInterface, MountManagerAwareInterface, LoggerAwareInterface
{
    private LoggerInterface $logger;
    private MountManager $mountManager;

    public function __construct()
    {
        $this->logger = new NullLogger();
    }

    /**
     * @throws FilesystemException
     */
    public function run(string $repoReference, string $buildId, string $savePath, array $options = null): ?string
    {
        $relativeFilename = "$buildId.tgz";
        $filename = realpath($relativeFilename);
        if (false !== $filename) {
            $this->logger->info("Saving build to \"$savePath/$relativeFilename\".");
            $this->mountManager->copy("local://$filename", "$savePath/$relativeFilename");
            return null;
        }

        $filename = realpath('.');
        if (false === $filename) {
            throw new Exception\RuntimeException(sprintf(
                'Failed to load path %s.',
                getcwd()
            ));
        }

        $this->logger->info("Saving build to \"$savePath\".");
        if (str_starts_with($savePath, 'local://')) {
            exec('which rsync', $output, $result_code);
            if (0 === $result_code) {
                $this->syncLocallyViaRsync($filename, $savePath, $options);
                return null;
            }
        }

        $this->mountManager->sync("local://$filename", $savePath, $options);
        return null;
    }

    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    public function setMountManager(MountManager $mountManager): void
    {
        $this->mountManager = $mountManager;
    }

    private function syncLocallyViaRsync(string $buildPath, string $buildTarget, ?array $options = null): void
    {
        $buildTarget = '/' . substr($buildTarget, 8);
        $command = "rsync -rvp \"$buildPath/\" \"$buildTarget/\" ";

        if (!empty($options['excludes'])) {
            foreach ($options['excludes'] as $exclude) {
                $command .= sprintf('--exclude=%s ', escapeshellarg($exclude));
            }
        }

        if (!empty($options['includes'])) {
            foreach ($options['includes'] as $include) {
                $command .= sprintf('--include=%s ', escapeshellarg($include));
            }
        }

        passthru($command, $result);
        if ($result !== 0) {
            throw new Exception\RuntimeException(sprintf(
                'Failed to sync "%s" to "%s".',
                $buildTarget,
                $buildPath,
            ));
        }
    }
}
