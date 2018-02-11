<?php
/**
 * @author Kirk Madera <kmadera@robofirm.com>
 */

namespace ConductorAppOrchestration;

use GitElephant\Repository;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Class ApplicationCodeInstaller
 *
 * @package ConductorAppOrchestration
 */
class ApplicationCodeInstaller
{
    /**
     * @var ApplicationConfig
     */
    private $applicationConfig;
    /**
     * @var FileLayoutHelper
     */
    private $fileLayoutHelper;
    /**
     * @var LoggerInterface
     */
    protected $logger;

    public function __construct(
        ApplicationConfig $applicationConfig,
        FileLayoutHelper $fileLayoutHelper,
        LoggerInterface $logger = null
    ) {
        $this->applicationConfig = $applicationConfig;
        $this->fileLayoutHelper = $fileLayoutHelper;
        if (is_null($logger)) {
            $logger = new NullLogger();
        }
        $this->logger = $logger;
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

    /**
     * @param string $branch
     * @param bool   $update
     *
     * @throws Exception\RuntimeException if app skeleton has not yet been installed
     */
    public function installCode(
        string $branch,
        $update = false
    ): void {
        $application = $this->applicationConfig;
        $fileLayout = new FileLayout(
            $application->getAppRoot(),
            $application->getFileLayout(),
            $application->getRelativeDocumentRoot()
        );
        $this->fileLayoutHelper->loadFileLayoutPaths($fileLayout);
        if (!$this->fileLayoutHelper->isFileLayoutInstalled($fileLayout)) {
            throw new Exception\RuntimeException(
                "App is not yet installed. Install app skeleton before refreshing code."
            );
        }

        $codePath = $application->getCodePath();
        $repoUrl = $application->getRepoUrl();
        if (!file_exists("{$codePath}/.git")) {
            $this->logger->info("Cloning repository \"$repoUrl:$branch\" to \"{$codePath}\".");
            $repo = new Repository($codePath);
            $repo->cloneFrom($repoUrl, $codePath);
            $repo->checkout($branch);
        } elseif ($update) {
            $this->logger->info("Pulling the latest code from \"$repoUrl:$branch\" to \"{$codePath}\".");
            $repo = new Repository($codePath);
            $repo->checkout($branch);
            $repo->pull('origin', $branch, false);
        } else {
            $this->logger->info(
                "Skipping clone/pull of code repository because it already exists and \$update was not specified."
            );
        }
    }
}
