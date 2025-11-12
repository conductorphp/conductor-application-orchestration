<?php

namespace ConductorAppOrchestration\Build\Command;

use ConductorAppOrchestration\Config\ApplicationConfig;
use ConductorAppOrchestration\Config\ApplicationConfigAwareInterface;
use ConductorAppOrchestration\Exception;
use ConductorCore\Shell\Adapter\ShellAdapterAwareInterface;
use ConductorCore\Shell\Adapter\ShellAdapterInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class PackageBuildCommand
    implements BuildCommandInterface, ApplicationConfigAwareInterface, ShellAdapterAwareInterface, LoggerAwareInterface
{
    private LoggerInterface $logger;
    private ApplicationConfig $applicationConfig;
    private ShellAdapterInterface $shellAdapter;

    public function __construct()
    {
        $this->logger = new NullLogger();
    }

    public function run(string $repoReference, string $buildId, string $savePath, array $options = null): ?string
    {
        if (!isset($this->applicationConfig)) {
            throw new Exception\RuntimeException('$this->applicationConfig must be set.');
        }

        if (!isset($this->shellAdapter)) {
            throw new Exception\RuntimeException('$this->shellAdapter must be set.');
        }

        $this->logger->info(
            sprintf(
                'Packaging build as "%s.tgz".',
                $buildId
            )
        );

        $tarFilename = "$buildId.tgz";
        $command = 'tar -cz --exclude-vcs ';

        if (!empty($options['excludes'])) {
            foreach ($options['excludes'] as $excludePath) {
                if (0 === strpos($excludePath, '/')) {
                    $excludePath = '.' . $excludePath; // Tar expects . for paths relative to root of tarball
                }
                $command .= '--exclude ' . escapeshellarg($excludePath) . ' ';
            }
        }

        $command .= '-f ' . escapeshellarg($tarFilename) . ' ./*';

        return $this->shellAdapter->runShellCommand($command);
    }

    public function setApplicationConfig(ApplicationConfig $applicationConfig): void
    {
        $this->applicationConfig = $applicationConfig;
    }

    public function setShellAdapter(ShellAdapterInterface $shellAdapter): void
    {
        $this->shellAdapter = $shellAdapter;
    }

    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }
}
