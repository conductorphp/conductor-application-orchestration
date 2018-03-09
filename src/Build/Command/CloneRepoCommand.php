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

/**
 * Class CloneRepoCommand
 *
 * @todo    Remove git assumption and handle via RepositoryInterface
 * @package ConductorAppOrchestration\Build\Command
 */
class CloneRepoCommand
    implements BuildCommandInterface, ApplicationConfigAwareInterface, ShellAdapterAwareInterface, LoggerAwareInterface
{
    /**
     * @var LoggerInterface
     */
    private $logger;
    /**
     * @var ApplicationConfig
     */
    private $applicationConfig;
    /**
     * @var ShellAdapterInterface
     */
    private $shellAdapter;

    public function __construct()
    {
        $this->logger = new NullLogger();
    }

    /**
     * @inheritdoc
     */
    public function run(string $repoReference, string $buildId, string $savePath, array $options = null): void
    {
        if (!isset($this->applicationConfig)) {
            throw new Exception\RuntimeException('$this->applicationConfig must be set.');
        }

        if (!isset($this->shellAdapter)) {
            throw new Exception\RuntimeException('$this->shellAdapter must be set.');
        }

        $this->logger->info(
            sprintf(
                'Cloning "%s:%s".',
                $this->applicationConfig->getRepoUrl(),
                $repoReference
            )
        );

        $command = 'git clone ' . escapeshellarg($this->applicationConfig->getRepoUrl()) . ' ./ --branch '
            . escapeshellarg($repoReference)
            . ' --depth 1 --single-branch -v';
        $this->shellAdapter->runShellCommand($command);
    }

    /**
     * @inheritdoc
     */
    public function setApplicationConfig(ApplicationConfig $applicationConfig): void
    {
        $this->applicationConfig = $applicationConfig;
    }

    /**
     * @inheritdoc
     */
    public function setShellAdapter(ShellAdapterInterface $shellAdapter): void
    {
        $this->shellAdapter = $shellAdapter;
    }

    /**
     * @inheritdoc
     */
    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

}
