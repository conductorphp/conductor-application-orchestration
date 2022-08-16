<?php

namespace ConductorAppOrchestration\Build\Command;

use ConductorAppOrchestration\Config\ApplicationConfig;
use ConductorAppOrchestration\Config\ApplicationConfigAwareInterface;
use ConductorAppOrchestration\Exception;
use ConductorCore\Repository\RepositoryAdapterAwareInterface;
use ConductorCore\Repository\RepositoryAdapterInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class CloneRepoCommand
    implements BuildCommandInterface, ApplicationConfigAwareInterface, RepositoryAdapterAwareInterface,
    LoggerAwareInterface
{
    private LoggerInterface $logger;
    private ApplicationConfig $applicationConfig;
    private RepositoryAdapterInterface $repositoryAdapter;

    public function __construct()
    {
        $this->logger = new NullLogger();
    }

    public function run(string $repoReference, string $buildId, string $savePath, array $options = null): ?string
    {
        if (!isset($this->applicationConfig)) {
            throw new Exception\RuntimeException('$this->applicationConfig must be set.');
        }

        if (!isset($this->repositoryAdapter)) {
            throw new Exception\RuntimeException('$this->repositoryAdapter must be set.');
        }

        $this->logger->info(
            sprintf(
                'Cloning "%s:%s".',
                $this->applicationConfig->getRepoUrl(),
                $repoReference
            )
        );

        $shallow = array_key_exists('shallow', $options) ? $options['shallow'] : true;

        $this->repositoryAdapter->setPath(getcwd());
        $this->repositoryAdapter->setRepoUrl($this->applicationConfig->getRepoUrl());
        $this->repositoryAdapter->checkout($repoReference, $shallow);
        return null;
    }

    public function setApplicationConfig(ApplicationConfig $applicationConfig): void
    {
        $this->applicationConfig = $applicationConfig;
    }

    public function setRepositoryAdapter(RepositoryAdapterInterface $repositoryAdapter): void
    {
        $this->repositoryAdapter = $repositoryAdapter;
    }

    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }
}
