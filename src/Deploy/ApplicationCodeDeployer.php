<?php
/**
 * @author Kirk Madera <kirk.madera@rmgmedia.com>
 */

namespace ConductorAppOrchestration\Deploy;

use ConductorAppOrchestration\Exception;
use ConductorAppOrchestration\Config\ApplicationConfig;
use ConductorAppOrchestration\FileLayoutInterface;
use ConductorCore\Filesystem\MountManager\MountManager;
use ConductorCore\Repository\RepositoryAdapterInterface;
use ConductorCore\Shell\Adapter\ShellAdapterInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Class ApplicationCodeDeployer
 *
 * @package ConductorAppOrchestration
 */
class ApplicationCodeDeployer
{
    /**
     * @var ApplicationConfig
     */
    private $applicationConfig;
    /**
     * @var RepositoryAdapterInterface
     */
    private $repositoryAdapter;
    /**
     * @var ShellAdapterInterface
     */
    private $shellAdapter;
    /**
     * @var MountManager
     */
    private $mountManager;
    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * ApplicationCodeDeployer constructor.
     *
     * @param ApplicationConfig          $applicationConfig
     * @param RepositoryAdapterInterface $repositoryAdapter
     * @param ShellAdapterInterface      $shellAdapter
     * @param MountManager               $mountManager
     * @param LoggerInterface|null       $logger
     */
    public function __construct(
        ApplicationConfig $applicationConfig,
        RepositoryAdapterInterface $repositoryAdapter,
        ShellAdapterInterface $shellAdapter,
        MountManager $mountManager,
        LoggerInterface $logger = null
    ) {
        $this->applicationConfig = $applicationConfig;
        $this->repositoryAdapter = $repositoryAdapter;
        $this->shellAdapter = $shellAdapter;
        $this->mountManager = $mountManager;
        if (is_null($logger)) {
            $logger = new NullLogger();
        }
        $this->logger = $logger;
    }

    /**
     * @param string $buildId
     * @param string $buildPath
     * @param string $repoReference
     *
     * @throws Exception\RuntimeException if app skeleton has not yet been installed
     */
    public function deployCode(
        string $buildId = null,
        string $buildPath = null,
        string $repoReference = null
    ): void {
        if (!($buildId || $repoReference)) {
            throw new Exception\BadMethodCallException('$buildId or $repoReference must be set.');
        }

        if ($buildId) {
            $this->deployFromBuild($buildId, $buildPath);
        } else {
            $this->deployFromRepo($repoReference);
        }
    }

    /**
     * @param string $repoReference
     */
    private function deployFromRepo(string $repoReference): void
    {
        $codePath = $this->applicationConfig->getCodePath();
        $repoUrl = $this->applicationConfig->getRepoUrl();

        $this->logger->debug("Checking out \"$repoUrl:$repoReference\" to \"{$codePath}\".");
        $this->repositoryAdapter->setRepoUrl($repoUrl);
        $this->repositoryAdapter->setPath($codePath);
        $this->repositoryAdapter->checkout($repoReference);
        $this->repositoryAdapter->pull();
    }

    /**
     * @param string      $buildId
     * @param string      $buildPath
     */
    private function deployFromBuild(string $buildId, string $buildPath): void
    {
        if (!$buildPath) {
            throw new Exception\BadMethodCallException('$buildPath must be set if $buildId is set.');
        }

        $codePath = $this->applicationConfig->getCodePath($buildId);
        // Deal with blue_green file layout here
//        if (FileLayoutInterface::STRATEGY_BLUE_GREEN == $this->applicationConfig->getFileLayoutStrategy()) {
//            $codePath .= "/$buildId";
//        }
        $cwd = getcwd();
        $this->logger->debug(
            "Downloading build file from \"$buildPath/$buildId.tgz\" to \"local://$cwd/$buildId.tgz\"."
        );
        $this->mountManager->copy("$buildPath/$buildId.tgz", "local://$cwd/$buildId.tgz");

        $this->logger->debug("Extracting \"local://$cwd/$buildId.tgz\" to \"$codePath\".");
        $command = 'tar -xzvf ' . escapeshellarg("$cwd/$buildId.tgz") . ' --directory ' . escapeshellarg($codePath);
        $this->shellAdapter->runShellCommand($command);
    }

    /**
     * @inheritdoc
     */
    public function setLogger(LoggerInterface $logger): void
    {
        $this->mountManager->setLogger($logger);
        if ($this->repositoryAdapter instanceof LoggerAwareInterface) {
            $this->repositoryAdapter->setLogger($logger);
        }

        if ($this->shellAdapter instanceof LoggerAwareInterface) {
            $this->shellAdapter->setLogger($logger);
        }

        $this->logger = $logger;
    }
}
