<?php

namespace ConductorAppOrchestration\Build\Command;

use ConductorAppOrchestration\Exception;
use ConductorCore\Repository\RepositoryAdapterAwareInterface;
use ConductorCore\Repository\RepositoryAdapterInterface;
use ConductorCore\Shell\Adapter\ShellAdapterAwareInterface;
use ConductorCore\Shell\Adapter\ShellAdapterInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Class ValidateCommandsCallableCommand
 *
 * @package ConductorAppOrchestration\Build\Command
 */
class ValidateCommandsCallableCommand
    implements BuildCommandInterface, ShellAdapterAwareInterface, LoggerAwareInterface
{
    /**
     * @var ShellAdapterInterface
     */
    private $shellAdapter;
    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct()
    {
        $this->logger = new NullLogger();
    }

    /**
     * @inheritdoc
     */
    public function run(string $repoReference, string $buildId, string $savePath, array $options = null): ?string
    {
        if (!($repoReference || $buildId)) {
            $this->logger->notice(
                'Add condition "code", "code-build", or "code-repo" to this step in your deployment plan. This step can only be run when '
                . 'deploying code. Skipped.'
            );
            return null;
        }

        if (empty($options['commands'])) {
            $this->logger->notice(
                'Option "commands" should be set. This step does nothing otherwise.'
            );
            return null;
        }

        if (!isset($this->shellAdapter)) {
            throw new Exception\RuntimeException('$this->shellAdapter must be set.');
        }

        foreach ($options['commands'] as $command) {
            if (!$this->shellAdapter->isCallable($command, $hosts)) {
                throw new Exception\RuntimeException(sprintf('Command "%s" is not callable.', $command));
            }
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
    public function setShellAdapter(ShellAdapterInterface $shellAdapter): void
    {
        $this->shellAdapter = $shellAdapter;
    }
}
