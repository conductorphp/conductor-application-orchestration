<?php
/**
 * @author Kirk Madera <kmadera@robofirm.com>
 */

namespace ConductorAppOrchestration;

use ConductorAppOrchestration\GitElephant\Repository;
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
     * @param bool   $replace
     *
     * @throws Exception\RuntimeException if app skeleton has not yet been installed
     */
    public function installCode(
        string $branch,
        $replace = false
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
                "Application skeleton is not yet installed. Run app:install first."
            );
        }

        $codePath = $application->getCodePath();
        $repoUrl = $application->getRepoUrl();

        if (!file_exists("{$codePath}/.git")) {
            $this->logger->info("Cloning repository \"$repoUrl:$branch\" to \"{$codePath}\".");
            $repo = new Repository($codePath);
            try {
                $repo->cloneFrom($repoUrl, $codePath);
            } catch (\RuntimeException $e) {

                if (false === strpos($e->getMessage(), 'already exists and is not an empty directory')) {
                    throw $e;
                }

                throw new Exception\RuntimeException('Code must be installed initially with skeleton. To install code '
                    . 'with this command you must first remove all files from "' . $application->getCodePath() . '" or '
                    . 'run app:destroy.');
            }
            $repo->checkout($branch);
        } elseif ($replace) {
            $this->logger->info("Stashing file changes and pulling the latest code from \"$repoUrl:$branch\" to \"{$codePath}\".");
            $repo = new Repository($codePath);
            $repo->stash('Conductor app:install:code code stash', true);
            $repo->checkout($branch);
            $repo->pull('origin', $branch, false);
        } else {
            $this->logger->info(
                "Skipping clone/pull of code repository because it already exists and \$replace is false."
            );
        }
    }
}
