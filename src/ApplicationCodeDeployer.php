<?php
/**
 * @author Kirk Madera <kmadera@robofirm.com>
 */

namespace ConductorAppOrchestration;

use ConductorAppOrchestration\Config\ApplicationConfig;
use ConductorAppOrchestration\GitElephant\Repository;
use ConductorCore\Filesystem\MountManager\MountManager;
use ConductorCore\Shell\Adapter\ShellAdapterInterface;
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

    public function __construct(
        ApplicationConfig $applicationConfig,
        MountManager $mountManager,
        ShellAdapterInterface $shellAdapter,
        LoggerInterface $logger = null
    ) {
        $this->applicationConfig = $applicationConfig;
        $this->mountManager = $mountManager;
        $this->shellAdapter = $shellAdapter;
        if (is_null($logger)) {
            $logger = new NullLogger();
        }
        $this->logger = $logger;
    }

    /**
     * @param string $buildId
     * @param string $buildPath
     * @param string $branch
     *
     * @throws Exception\RuntimeException if app skeleton has not yet been installed
     */
    public function deployCode(
        string $buildId = null,
        string $buildPath = null,
        string $branch = null
    ): void {
        if (!($buildId || $branch)) {
            throw new Exception\BadMethodCallException('$buildId or $branch must be set.');
        }

        if ($buildId) {
            $this->deployFromBuild($buildId, $buildPath);
        } else {
            $this->deployFromRepo($branch);
        }
    }

    /**
     * @param string $repoReference
     */
    private function deployFromRepo(string $repoReference): void
    {
        $codePath = $this->applicationConfig->getCodePath();
        $repoUrl = $this->applicationConfig->getRepoUrl();

        if (!file_exists("{$codePath}/.git")) {
            $this->logger->debug("Cloning repository \"$repoUrl:$repoReference\" to \"{$codePath}\".");
            $repo = new Repository($codePath);
            $repo->cloneFrom($repoUrl, $codePath);
            $repo->checkout($repoReference);
        } else {
            $repo = new Repository($codePath);
            if ($repo->isDirty()) {
                throw new Exception\RuntimeException(
                    'Code path "' . $codePath . '" is dirty. Clean path before deploying code from repo.'
                );
            }
            $this->logger->debug("Pulling the latest code from \"$repoUrl:$repoReference\" to \"{$codePath}\".");
            $repo->checkout($repoReference);
            $repo->pull('origin', $repoReference, false);
        }
    }

    /**
     * @param string $buildId
     * @param string $buildPath
     */
    private function deployFromBuild(string $buildId, string $buildPath): void
    {
        if (!$buildPath) {
            throw new Exception\BadMethodCallException('$buildPath must be set if $buildId is set.');
        }

        $codePath = $this->applicationConfig->getCodePath();
        $cwd = getcwd();
        $this->logger->debug(
            "Downloading build file from \"$buildPath/$buildId.tgz\" to \"local://$cwd/$buildId.tgz\"."
        );
        $this->mountManager->copy("$buildPath/$buildId.tgz", "local://$cwd/$buildId.tgz");


        // Deal with branch and blue/green file layouts here
        $this->logger->debug("Extracting \"local://$cwd/$buildId.tgz\" to \"$codePath\".");
        $command = 'tar -xzvf ' . escapeshellarg("$cwd/$buildId.tgz") . ' --directory ' . escapeshellarg($codePath);
        $this->shellAdapter->runShellCommand($command);
    }

    /**
     * @param LoggerInterface $logger
     *
     * @return void
     */
    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }
}
