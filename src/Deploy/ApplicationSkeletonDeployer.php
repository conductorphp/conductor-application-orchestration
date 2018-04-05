<?php
/**
 * @author Kirk Madera <kmadera@robofirm.com>
 */

namespace ConductorAppOrchestration\Deploy;

use ConductorAppOrchestration\Exception;
use ConductorAppOrchestration\Config\ApplicationConfig;
use ConductorAppOrchestration\FileLayoutInterface;
use ConductorCore\Shell\Adapter\LocalShellAdapter;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Twig\Environment as TwigEnvironment;
use Twig\Loader\ArrayLoader as TwigArrayLoader;

/**
 * Class ApplicationSkeletonDeployer
 *
 * @package ConductorAppOrchestration
 */
class ApplicationSkeletonDeployer implements LoggerAwareInterface
{
    /**
     * @var ApplicationConfig
     */
    private $applicationConfig;
    /**
     * @var LocalShellAdapter
     */
    private $shellAdapter;
    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * ApplicationSkeletonDeployer constructor.
     *
     * @param ApplicationConfig    $applicationConfig
     * @param LocalShellAdapter    $localShellAdapter
     * @param LoggerInterface|null $logger
     */
    public function __construct(
        ApplicationConfig $applicationConfig,
        LocalShellAdapter $localShellAdapter,
        LoggerInterface $logger = null
    ) {
        $this->applicationConfig = $applicationConfig;
        $this->shellAdapter = $localShellAdapter;
        if (is_null($logger)) {
            $logger = new NullLogger();
        }
        $this->logger = $logger;
    }

    /**
     * @param string|null $branch
     */
    public function deploySkeleton(
        string $branch = null
    ): void {
        if (FileLayoutInterface::STRATEGY_BRANCH == $this->applicationConfig->getFileLayoutStrategy() && !$branch) {
            throw new Exception\RuntimeException(
                '$branch must be set because the current environment is running the '
                . '"' . FileLayoutInterface::STRATEGY_BRANCH . '" file layout.'
            );
        }

        $origUmask = umask(0);
        $this->prepareFileLayout($branch);
        $this->installAppFiles($branch);
        umask($origUmask);
    }

    public function prepareFileLayout(string $branch = null): void
    {
        if (FileLayoutInterface::STRATEGY_BRANCH == $this->applicationConfig->getFileLayoutStrategy() && !$branch) {
            throw new Exception\RuntimeException(
                '$branch must be set because the current environment is running the '
                . '"' . FileLayoutInterface::STRATEGY_BRANCH . '" file layout.'
            );
        }

        $this->prepareAppRootPath();
        $this->prepareCodePath($branch);
        $this->prepareLocalPath($branch);
        $this->prepareSharedPath($branch);
        $this->prepareCurrentReleasePath($branch);
    }

    private function prepareAppRootPath(): void
    {
        $appRoot = $this->applicationConfig->getAppRoot();
        $defaultDirMode = $this->applicationConfig->getDefaultDirMode();
        if (!is_writable($appRoot)) {
            if (is_writable(dirname($appRoot))) {
                mkdir($appRoot, $defaultDirMode);
            } else {
                throw new Exception\RuntimeException("Project root \"$appRoot\" is not writable.");
            }
        }
    }

    private function prepareCodePath(string $branch = null): void
    {
        $codePath = $this->applicationConfig->getCodePath($branch);
        if ($codePath != $this->applicationConfig->getAppRoot()) {
            if (!file_exists($codePath)) {
                mkdir($codePath, $this->applicationConfig->getDefaultDirMode(), true);
                $this->logger->debug("Created \"{$codePath}\".");
            } else {
                $this->logger->debug("Skipped creating \"{$codePath}\". Already exists.");
            }
        }
    }

    private function prepareLocalPath(string $branch = null): void
    {
        $localPath = $this->applicationConfig->getLocalPath();
        if ($localPath != $this->applicationConfig->getCodePath($branch) && !file_exists($localPath)) {
            if (!file_exists($localPath)) {
                mkdir($localPath, $this->applicationConfig->getDefaultDirMode(), true);
                $this->logger->debug("Created \"{$localPath}\".");
            } else {
                $this->logger->debug("Skipped creating \"{$localPath}\". Already exists.");
            }
        }
    }

    private function prepareSharedPath(string $branch = null): void
    {
        $sharedPath = $this->applicationConfig->getSharedPath();
        if ($sharedPath != $this->applicationConfig->getCodePath($branch) && !file_exists($sharedPath)) {
            if (!file_exists($sharedPath)) {
                mkdir($sharedPath, $this->applicationConfig->getDefaultDirMode(), true);
                $this->logger->debug("Created \"{$sharedPath}\".");
            } else {
                $this->logger->debug("Skipped creating \"{$sharedPath}\". Already exists.");
            }
        }
    }

    private function prepareCurrentReleasePath(string $branch = null): void
    {
        if (FileLayoutInterface::STRATEGY_BLUE_GREEN == $this->applicationConfig->getFileLayoutStrategy()) {
            $appRoot = $this->applicationConfig->getAppRoot();
            $relativeCodePath = substr($this->applicationConfig->getCodePath($branch), strlen($appRoot) + 1);
            if (!file_exists("$appRoot/current_release")) {
                $this->logger->debug("Created symlink \"$appRoot/current_release\" -> \"$relativeCodePath\".");
                symlink($relativeCodePath, "$appRoot/current_release");
            } else {
                $this->logger->debug(
                    "Skipped creating symlink \"$appRoot/current_release\" -> \"$relativeCodePath\". Already exists."
                );
            }
        }
    }

    /**
     * @param string|null $branch
     */
    public function installAppFiles(string $branch = null): void
    {
        if (FileLayoutInterface::STRATEGY_BRANCH == $this->applicationConfig->getFileLayoutStrategy() && !$branch) {
            throw new Exception\RuntimeException(
                '$branch must be set because the current environment is running the '
                . '"' . FileLayoutInterface::STRATEGY_BRANCH . '" file layout.'
            );
        }

        $this->installDirectories($branch);
        $this->installFiles($branch);
        $this->installSymlinks($branch);
    }

    private function installDirectories(string $branch = null): void
    {
        $directories = $this->applicationConfig->getSkeletonConfig()->getDirectories();
        if ($directories) {
            foreach ($directories as $filename => $directory) {
                if (empty($directory['location'])) {
                    throw new Exception\RuntimeException(
                        "Directory \"$filename\" must have \"location\" property set."
                    );
                }

                $resolvedFilename = $this->resolveFilename($directory['location'], $filename, $branch);
                $this->installDirectory($resolvedFilename, $filename, $directory, $branch);
            }
        }
    }

    /**
     * @param string $resolvedFilename
     * @param string $filename
     * @param array  $fileInfo
     * @param string|null $branch
     */
    private function installDirectory(
        string $resolvedFilename,
        string $filename,
        array $fileInfo,
        string $branch = null
    ): void {
        if (!empty($fileInfo['auto_symlink'])) {
            $symlinkResolvedFilename = $this->resolveFilename('code', $filename, $branch);
            $symlinkResolvedTargetFilename = $this->resolveFilename($fileInfo['location'], $filename, $branch);
            $this->installSymlink($symlinkResolvedFilename, $symlinkResolvedTargetFilename);
        }

        $parentDir = dirname($resolvedFilename);
        $this->ensureDirExists($parentDir);

        $mode = $this->applicationConfig->getDefaultDirMode();
        if (isset($fileInfo['mode'])) {
            if (is_string($fileInfo['mode'])) {
                if (decoct(octdec($fileInfo['mode'])) != $fileInfo['mode']) {
                    $this->logger->notice(
                        "File mode for file \"$filename\" must be an octal. \"{$fileInfo['mode']}\" given. Used default of \"$mode\"."
                    );
                }
                $mode = octdec($fileInfo['mode']);
            } else {
                $mode = $fileInfo['mode'];
            }
        }
        $modeAsString = base_convert((string)$mode, 10, 8);

        if (file_exists($resolvedFilename)) {
            if (!is_dir($resolvedFilename) || is_link($resolvedFilename)) {
                unlink($resolvedFilename);
                mkdir($resolvedFilename, $mode);
            } else {
                chmod($resolvedFilename, $mode);
            }
            $this->logger->debug("Ensured \"$resolvedFilename\" is a directory and has permissions $modeAsString.");
        } else {
            mkdir($resolvedFilename, $mode, true);
            $this->logger->debug("Created directory \"$resolvedFilename\" with permissions $modeAsString.");
        }
    }

    /**
     * @param string|null $branch
     */
    private function installFiles(string $branch = null): void
    {
        $files = $this->applicationConfig->getSkeletonConfig()->getFiles();
        if (!empty($files)) {
            foreach ($files as $filename => $file) {
                if (empty($file['location'])) {
                    throw new Exception\RuntimeException(
                        "Directory \"$filename\" must have \"location\" property set."
                    );
                }

                $resolvedFilename = $this->resolveFilename($file['location'], $filename, $branch);
                $this->installFile($resolvedFilename, $filename, $file, $branch);
            }
        }
    }

    /**
     * @param string      $resolvedFilename
     * @param string      $filename
     * @param array       $fileInfo
     * @param string|null $branch
     */
    private function installFile(
        string $resolvedFilename,
        string $filename,
        array $fileInfo,
        string $branch = null
    ): void {
        if ($fileInfo['auto_symlink']) {
            $symlinkResolvedFilename = $this->resolveFilename('code', $filename, $branch);
            $symlinkResolvedTargetFilename = $this->resolveFilename($fileInfo['location'], $filename, $branch);
            $this->installSymlink($symlinkResolvedFilename, $symlinkResolvedTargetFilename);
        }

        $parentDir = dirname($resolvedFilename);
        $this->ensureDirExists($parentDir);

        $globalTemplateVars = $this->applicationConfig->getTemplateVars();
        $mode = $this->applicationConfig->getDefaultDirMode();
        if (isset($fileInfo['mode'])) {
            if (is_string($fileInfo['mode'])) {
                if (decoct(octdec($fileInfo['mode'])) != $fileInfo['mode']) {
                    $this->logger->notice(
                        "File mode for file \"$filename\" must be an octal. \"{$fileInfo['mode']}\" given. Used default of \"$mode\"."
                    );
                }
                $mode = octdec($fileInfo['mode']);
            } else {
                $mode = $fileInfo['mode'];
            }
        }
        $modeAsString = base_convert((string)$mode, 10, 8);

        $content = $this->renderContent($fileInfo, $globalTemplateVars, $branch);
        if (file_exists($resolvedFilename)) {
            if (!is_file($resolvedFilename) || is_link($resolvedFilename)) {
                if (is_dir($resolvedFilename)) {
                    $command = 'rm -rf ' . escapeshellarg($resolvedFilename);
                    $this->shellAdapter->runShellCommand($command);
                } else {
                    unlink($resolvedFilename);
                }
            }
            file_put_contents($resolvedFilename, $content);
            chmod($resolvedFilename, $mode);
            $this->logger->debug("Ensured \"$resolvedFilename\" is file and has permissions $modeAsString.");
        } else {

            file_put_contents($resolvedFilename, $content);
            chmod($resolvedFilename, $mode);
            $this->logger->debug("Created file \"$resolvedFilename\" with permissions $modeAsString.");
        }
    }

    private function installSymlinks(string $branch = null): void
    {
        $symlinks = $this->applicationConfig->getSkeletonConfig()->getSymlinks();
        if (!empty($symlinks)) {
            foreach ($symlinks as $sourcePath => $symlink) {
                if (empty($symlink['location']) || empty($symlink['target_location']) || empty($symlink['target'])) {
                    throw new Exception\RuntimeException(
                        "Symlink \"$sourcePath\" must have \"location\", \"target_location\", and \"target\" properties set."
                    );
                }

                $resolvedFilename = $this->resolveFilename($symlink['location'], $sourcePath, $branch);
                $resolvedTargetFilename = $this->resolveFilename(
                    $symlink['target_location'],
                    $symlink['target'],
                    $branch
                );
                $this->installSymlink($resolvedFilename, $resolvedTargetFilename);
            }
        }
    }

    /**
     * @param string      $location
     * @param string      $filename
     * @param string|null $branch
     *
     * @return string
     */
    private function resolveFilename(string $location, string $filename, string $branch = null): string
    {
        $path = $this->applicationConfig->getPath($location, $branch);
        return "$path/$filename";
    }

    /**
     * @param array       $fileInfo
     * @param array       $globalTemplateVars
     * @param string|null $branch
     *
     * @return string
     */
    private function renderContent(array $fileInfo, array $globalTemplateVars, string $branch = null): string
    {
        if (empty($fileInfo['source'])) {
            return '';
        }

        $filename = $this->applicationConfig->getSourceFile($fileInfo['source']);
        $content = file_get_contents($filename);

        if (!empty($fileInfo['template_vars'])) {
            if ($globalTemplateVars) {
                $templateVars = array_replace($globalTemplateVars, $fileInfo['template_vars']);
            } else {
                $templateVars = $fileInfo['template_vars'];
            }
        } else {
            $templateVars = $globalTemplateVars;
        }


        // Special use case to replace branch name in any configuration files in which it is needed with the branch
        // specific database name.
        if (FileLayoutInterface::STRATEGY_BRANCH == $this->applicationConfig->getFileLayoutStrategy()) {
            $databaseSanitizedBranchName = $this->sanitizeDatabaseName($branch);
            $urlSanitizedBranchName = $this->sanitizeUrl($branch);
            foreach ($templateVars as &$templateVar) {
                $templateVar = preg_replace(
                    '%\{\{\s*database_branch_suffix\s*\}\}%',
                    $databaseSanitizedBranchName,
                    $templateVar
                );
                $templateVar = preg_replace(
                    '%\{\{\s*url_branch_suffix\s*\}\}%',
                    "$urlSanitizedBranchName/",
                    $templateVar
                );
            }
            unset($templateVar);
        }

        if ($templateVars && '.twig' == substr($fileInfo['source'], -5)) {
            $twig = new TwigEnvironment(new TwigArrayLoader([]));
            $template = $twig->createTemplate($content);
            $content = $template->render($templateVars);
        }

        return $content;
    }

    /**
     * @param string $resolvedFilename
     * @param string $resolvedTargetFilename
     */
    private function installSymlink(string $resolvedFilename, string $resolvedTargetFilename): void
    {
        if ($resolvedFilename == $resolvedTargetFilename) {
            return;
        }

        $parentDir = dirname($resolvedFilename);
        $this->ensureDirExists($parentDir);

        $parentDir = dirname($resolvedTargetFilename);
        $this->ensureDirExists($parentDir);

        if (file_exists($resolvedFilename) || is_link($resolvedFilename)) {
            if (is_link($resolvedFilename) || is_file($resolvedFilename)) {
                unlink($resolvedFilename);
            } else {
                $command = 'rm -rf ' . escapeshellarg($resolvedFilename);
                $this->shellAdapter->runShellCommand($command);
            }
            symlink($resolvedTargetFilename, $resolvedFilename);
            $this->logger->debug("Ensured \"$resolvedFilename\" is a symlink pointed to \"$resolvedTargetFilename\".");
        } else {
            symlink($resolvedTargetFilename, $resolvedFilename);
            $this->logger->debug("Created symlink \"$resolvedFilename\" pointed to \"$resolvedTargetFilename\".");
        }
    }


    /**
     * @param string $name
     *
     * @return string
     */
    private function sanitizeDatabaseName(string $name): string
    {
        return strtolower(preg_replace('/[^a-z0-9_]/i', '_', $name));
    }

    /**
     * @param string $url
     *
     * @return string
     */
    private function sanitizeUrl(string $url): string
    {
        return strtolower(preg_replace('/[^a-z0-9\.-]/i', '-', $url));
    }

    /**
     * @param string $path
     */
    private function ensureDirExists(string $path): void
    {
        if (!(is_dir($path))) {
            if (file_exists($path)) {
                unlink($path);
            }
            mkdir($path, $this->applicationConfig->getDefaultDirMode(), true);
        }
    }

    /**
     * @inheritdoc
     */
    public function setLogger(LoggerInterface $logger): void
    {
        if ($this->shellAdapter instanceof LoggerAwareInterface) {
            $this->shellAdapter->setLogger($logger);
        }
        $this->logger = $logger;
    }

}
