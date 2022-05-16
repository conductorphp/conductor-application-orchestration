<?php
/**
 * @author Kirk Madera <kirk.madera@rmgmedia.com>
 */

namespace ConductorAppOrchestration\Deploy;

use ConductorAppOrchestration\Config\ApplicationConfig;
use ConductorAppOrchestration\Exception;
use ConductorAppOrchestration\FileLayoutInterface;
use ConductorAppOrchestration\Twig\Extension\VarExportExtension;
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
     * @param string|null $buildId
     */
    public function deploySkeleton(string $buildId = null): void
    {
        $origUmask = umask(0);
        $this->prepareFileLayout($buildId);
        $this->installAppFiles($buildId);
        umask($origUmask);
    }

    /**
     * @param string|null $buildId
     */
    public function prepareFileLayout(string $buildId = null): void
    {
        $this->prepareAppRootPath();
        if (FileLayoutInterface::STRATEGY_BLUE_GREEN == $this->applicationConfig->getFileLayoutStrategy()) {
            $this->prepareLocalPath();
            $this->prepareSharedPath();
            if ($buildId) {
                $this->prepareCodePath($buildId);
            }
        }
    }

    private function prepareAppRootPath(): void
    {
        $appRoot = $this->applicationConfig->getAppRoot();
        $defaultDirMode = $this->applicationConfig->getDefaultDirMode();

        if (!is_writable($appRoot)) {
            if (!is_dir($appRoot) && is_writable(dirname($appRoot))) {
                mkdir($appRoot, $defaultDirMode);
            } else {
                throw new Exception\RuntimeException("Project root \"$appRoot\" is not writable.");
            }
        }
    }

    /**
     * @param string|null $buildId
     */
    private function prepareCodePath(string $buildId): void
    {
        $codePath = $this->applicationConfig->getCodePath($buildId);
        if (!file_exists($codePath)) {
            mkdir($codePath, $this->applicationConfig->getDefaultDirMode(), true);
            $this->logger->debug("Created \"{$codePath}\".");
        } else {
            $this->logger->debug("Skipped creating \"{$codePath}\". Already exists.");
        }
    }

    private function prepareLocalPath(): void
    {
        $localPath = $this->applicationConfig->getLocalPath();
        if (!file_exists($localPath)) {
            mkdir($localPath, $this->applicationConfig->getDefaultDirMode(), true);
            $this->logger->debug("Created \"{$localPath}\".");
        } else {
            $this->logger->debug("Skipped creating \"{$localPath}\". Already exists.");
        }
    }

    private function prepareSharedPath(): void
    {
        $sharedPath = $this->applicationConfig->getSharedPath();
        if (!file_exists($sharedPath)) {
            mkdir($sharedPath, $this->applicationConfig->getDefaultDirMode(), true);
            $this->logger->debug("Created \"{$sharedPath}\".");
        } else {
            $this->logger->debug("Skipped creating \"{$sharedPath}\". Already exists.");
        }
    }

    /**
     * @param string|null $buildId
     */
    public function makeBuildCurrent(string $buildId): void
    {
        $appRoot = $this->applicationConfig->getAppRoot();
        $codePath = $this->applicationConfig->getCodePath($buildId);
        $relativeCodePath = substr($codePath, strlen($appRoot) + 1);
        $currentPath = $this->applicationConfig->getCurrentPath();
        if (!file_exists($currentPath)) {
            symlink($relativeCodePath, $currentPath);
            $this->logger->debug(
                "Created symlink \"$currentPath\" -> \"$relativeCodePath\"."
            );
        } else {
            if (realpath($currentPath) != $codePath) {
                $previousPath = $this->applicationConfig->getPreviousPath();
                $previousRelativeCodePath = substr(realpath($currentPath), strlen($appRoot) + 1);
                if (file_exists($previousPath)) {
                    unlink($previousPath);
                }
                symlink($previousRelativeCodePath, $previousPath);
                $this->logger->debug(
                    "Created symlink \"$previousPath\" -> \"$previousRelativeCodePath\"."
                );

                unlink($currentPath);
                symlink($relativeCodePath, $currentPath);
                $this->logger->debug(
                    "Created symlink \"$currentPath\" -> \"$relativeCodePath\"."
                );
            } else {
                $this->logger->debug(
                    "Skipped creating symlink \"$currentPath\" -> \"$relativeCodePath\". Already exists."
                );
            }
        }
    }

    /**
     * @param string|null $buildId
     */
    public function installAppFiles(string $buildId = null): void
    {
        if (FileLayoutInterface::STRATEGY_BLUE_GREEN == $this->applicationConfig->getFileLayoutStrategy()
            && !$buildId
        ) {
            $currentPath = $this->applicationConfig->getCurrentPath();
            if (file_exists($currentPath)) {
                // Get buildId based on current symlink
                $target = readlink($currentPath);
                $parts = explode(DIRECTORY_SEPARATOR, $target);
                $buildId = array_pop($parts);
            } else {
                $this->logger->notice(
                    sprintf(
                        "Skipped deploying skeleton because file layout strategy is \"%s\" and build has not yet been "
                        . "deployed. Deploy a build to deploy the skeleton.",
                        FileLayoutInterface::STRATEGY_BLUE_GREEN
                    )
                );
                return;
            }
        }

        $this->installDirectories($buildId);
        $this->installFiles($buildId);
        $this->installSymlinks($buildId);
    }

    /**
     * @param string|null $buildId
     */
    private function installDirectories(string $buildId = null): void
    {
        $directories = $this->applicationConfig->getSkeletonConfig()->getDirectories();
        if ($directories) {
            foreach ($directories as $filename => $directory) {
                if (is_null($directory)) {
                    continue;
                }

                if (empty($directory['location'])) {
                    throw new Exception\RuntimeException(
                        "Directory \"$filename\" must have \"location\" property set."
                    );
                }

                $resolvedFilename = $this->resolveFilename($filename, $directory['location'], $buildId);
                $this->installDirectory($resolvedFilename, $filename, $directory, $buildId);
            }
        }
    }

    /**
     * @param string      $resolvedFilename
     * @param string      $filename
     * @param array       $fileInfo
     * @param string|null $buildId
     */
    private function installDirectory(
        string $resolvedFilename,
        string $filename,
        array $fileInfo,
        string $buildId = null
    ): void {
        if (!empty($fileInfo['auto_symlink'])) {
            $symlinkResolvedFilename = $this->resolveFilename($filename, 'code', $buildId);
            $symlinkResolvedTargetFilename = $this->resolveFilename($filename, $fileInfo['location'], $buildId);
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

        if (is_link($resolvedFilename) || is_file($resolvedFilename)) {
            unlink($resolvedFilename);
            mkdir($resolvedFilename, $mode);
            chmod($resolvedFilename, $mode);
        } elseif (!file_exists($resolvedFilename)) {
            mkdir($resolvedFilename, $mode);
            chmod($resolvedFilename, $mode);
        }
        $this->logger->debug("Ensured \"$resolvedFilename\" is a directory and has permissions $modeAsString.");
    }

    private function installFiles(string $buildId = null): void
    {
        $files = $this->applicationConfig->getSkeletonConfig()->getFiles();
        if (!empty($files)) {
            foreach ($files as $filename => $file) {
                if (empty($file['location'])) {
                    throw new Exception\RuntimeException(
                        "Directory \"$filename\" must have \"location\" property set."
                    );
                }

                $resolvedFilename = $this->resolveFilename($filename, $file['location'], $buildId);
                $this->installFile($resolvedFilename, $filename, $file, $buildId);
            }
        }
    }

    /**
     * @param string      $resolvedFilename
     * @param string      $filename
     * @param array       $fileInfo
     * @param string|null $buildId
     */
    private function installFile(
        string $resolvedFilename,
        string $filename,
        array $fileInfo,
        string $buildId = null
    ): void {
        if (!empty($fileInfo['auto_symlink'])) {
            $symlinkResolvedFilename = $this->resolveFilename($filename, 'code', $buildId);
            $symlinkResolvedTargetFilename = $this->resolveFilename($filename, $fileInfo['location'], $buildId);
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

        $content = $this->renderContent($fileInfo, $globalTemplateVars);
        if (is_link($resolvedFilename)) {
            unlink($resolvedFilename);
        } elseif (is_dir($resolvedFilename)) {
            $command = 'rm -rf ' . escapeshellarg($resolvedFilename);
            $this->shellAdapter->runShellCommand($command);
        }

        file_put_contents($resolvedFilename, $content);
        chmod($resolvedFilename, $mode);
        $this->logger->debug("Ensured \"$resolvedFilename\" is file and has permissions $modeAsString.");
    }

    /**
     * @param string|null $buildId
     */
    private function installSymlinks(string $buildId = null): void
    {
        $symlinks = $this->applicationConfig->getSkeletonConfig()->getSymlinks();
        if (!empty($symlinks)) {
            foreach ($symlinks as $sourcePath => $symlink) {
                if (empty($symlink['location']) || empty($symlink['target_location']) || empty($symlink['target'])) {
                    throw new Exception\RuntimeException(
                        "Symlink \"$sourcePath\" must have \"location\", \"target_location\", and \"target\" properties set."
                    );
                }

                $resolvedFilename = $this->resolveFilename($sourcePath, $symlink['location'], $buildId);
                $resolvedTargetFilename = $this->resolveFilename(
                    $symlink['target'],
                    $symlink['target_location'],
                    $buildId
                );
                $this->installSymlink($resolvedFilename, $resolvedTargetFilename);
            }
        }
    }

    /**
     * @param string      $filename
     * @param string      $location
     * @param string|null $buildId
     *
     * @return string
     */
    private function resolveFilename(string $filename, string $location, string $buildId = null): string
    {
        $path = $this->applicationConfig->getPath($location, $buildId);
        if ($path) {
            return "$path/$filename";
        }
        return $filename;
    }

    /**
     * @param array $fileInfo
     * @param array $globalTemplateVars
     *
     * @return string
     */
    private function renderContent(array $fileInfo, array $globalTemplateVars): string
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

        if ($templateVars && '.twig' == substr($fileInfo['source'], -5)) {
            $twig = new TwigEnvironment(new TwigArrayLoader([]), [
                'debug' => true,
            ]);
            $twig->addExtension(new \Twig\Extension\DebugExtension());
            $twig->addExtension(new VarExportExtension());
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

        if (is_file($resolvedFilename) || is_link($resolvedFilename)) {
            unlink($resolvedFilename);
        } elseif (is_dir($resolvedFilename)) {
            $command = 'rm -rf ' . escapeshellarg($resolvedFilename);
            $this->shellAdapter->runShellCommand($command);
        }

        symlink($resolvedTargetFilename, $resolvedFilename);
        $this->logger->debug("Ensured \"$resolvedFilename\" is a symlink pointed to \"$resolvedTargetFilename\".");
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
