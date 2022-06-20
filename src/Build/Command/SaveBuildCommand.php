<?php

namespace ConductorAppOrchestration\Build\Command;

use ConductorCore\Filesystem\MountManager\MountManager;
use ConductorCore\Filesystem\MountManager\MountManagerAwareInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Class SaveBuildCommand
 *
 * @package ConductorAppOrchestration\Build\Command
 */
class SaveBuildCommand
    implements BuildCommandInterface, MountManagerAwareInterface, LoggerAwareInterface
{
    /**
     * @var LoggerInterface
     */
    private $logger;
    /**
     * @var MountManager
     */
    private $mountManager;

    public function __construct()
    {
        $this->logger = new NullLogger();
    }

    /**
     * @inheritdoc
     */
    public function run(string $repoReference, string $buildId, string $savePath, array $options = null): ?string
    {
        $tarFilename = "$buildId.tgz";
        $filename = realpath($tarFilename);
        $this->logger->info("Saving build to \"$savePath/$tarFilename\".");
        $result = $this->mountManager->putFile("local://$filename", "$savePath/$tarFilename");
        if ($result === false) {
            throw new Exception\RuntimeException(sprintf(
                'Failed to push code build "%s" to "%s".',
                $filename,
                "$savePath/$tarFilename"
            ));
        }
        return null;
    }

    /**
     * @inheritdoc
     */
    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * @inheritdoc
     */
    public function setMountManager(MountManager $mountManager): void
    {
        $this->mountManager = $mountManager;
    }
}
