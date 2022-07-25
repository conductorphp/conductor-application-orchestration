<?php

namespace ConductorAppOrchestration\Build\Command;

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
        $tarFilename = "$buildId.tgz";
        $filename = realpath($tarFilename);
        $this->logger->info("Saving build to \"$savePath/$tarFilename\".");
        $this->mountManager->copy("local://$filename", "$savePath/$tarFilename");
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
}
