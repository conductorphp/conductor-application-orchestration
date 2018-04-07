<?php
/**
 * @author Kirk Madera <kmadera@robofirm.com>
 */

namespace ConductorAppOrchestration\Deploy;

use ConductorAppOrchestration\Config\ApplicationConfig;
use ConductorAppOrchestration\Exception;
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

    public function deploySkeleton(): void {
        $origUmask = umask(0);
        $this->prepareFileLayout();
        $this->installAppFiles();
        umask($origUmask);
    }

    public function prepareFileLayout(): void
    {
        $this->prepareAppRootPath();
        $this->prepareCodePath();
        $this->prepareLocalPath();
        $this->prepareSharedPath();
        $this->prepareCurrentReleasePath();
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

    private function prepareCodePath(): void
    {
        $codePath = $this->applicationConfig->getCodePath();
        if ($codePath != $this->applicationConfig->getAppRoot()) {
            if (!file_exists($codePath)) {
                mkdir($codePath, $this->applicationConfig->getDefaultDirMode(), true);
                $this->logger->debug("Created \"{$codePath}\".");
            } else {
                $this->logger->debug("Skipped creating \"{$codePath}\". Already exists.");
            }
        }
    }

    private function prepareLocalPath(): void
    {
        $localPath = $this->applicationConfig->getLocalPath();
        if ($localPath != $this->applicationConfig->getCodePath() && !file_exists($localPath)) {
            if (!file_exists($localPath)) {
                mkdir($localPath, $this->applicationConfig->getDefaultDirMode(), true);
                $this->logger->debug("Created \"{$localPath}\".");
            } else {
                $this->logger->debug("Skipped creating \"{$localPath}\". Already exists.");
            }
        }
    }

    private function prepareSharedPath(): void
    {
        $sharedPath = $this->applicationConfig->getSharedPath();
        if ($sharedPath != $this->applicationConfig->getCodePath() && !file_exists($sharedPath)) {
            if (!file_exists($sharedPath)) {
                mkdir($sharedPath, $this->applicationConfig->getDefaultDirMode(), true);
                $this->logger->debug("Created \"{$sharedPath}\".");
            } else {
                $this->logger->debug("Skipped creating \"{$sharedPath}\". Already exists.");
            }
        }
    }

    private function prepareCurrentReleasePath(): void
    {
        if (FileLayoutInterface::STRATEGY_BLUE_GREEN == $this->applicationConfig->getFileLayoutStrategy()) {
            $appRoot = $this->applicationConfig->getAppRoot();
            $relativeCodePath = substr($this->applicationConfig->getCodePath(), strlen($appRoot) + 1);
            if (!file_exists("$appRoot/" . FileLayoutInterface::PATH_CURRENT)) {
                $this->logger->debug(
                    "Created symlink \"$appRoot/" . FileLayoutInterface::PATH_CURRENT
                    . "\" -> \"$relativeCodePath\"."
                );
                symlink($relativeCodePath, "$appRoot/" . FileLayoutInterface::PATH_CURRENT);
            } else {
                $this->logger->debug(
                    "Skipped creating symlink \"$appRoot/" . FileLayoutInterface::PATH_CURRENT
                    . "\" -> \"$relativeCodePath\". Already exists."
                );
            }
        }
    }

    public function installAppFiles(): void
    {
        $this->installDirectories();
        $this->installFiles();
        $this->installSymlinks();
    }

    private function installDirectories(): void
    {
        $directories = $this->applicationConfig->getSkeletonConfig()->getDirectories();
        if ($directories) {
            foreach ($directories as $filename => $directory) {
                if (empty($directory['location'])) {
                    throw new Exception\RuntimeException(
                        "Directory \"$filename\" must have \"location\" property set."
                    );
                }

                $resolvedFilename = $this->resolveFilename($directory['location'], $filename);
                $this->installDirectory($resolvedFilename, $filename, $directory);
            }
        }
    }

    /**
     * @param string      $resolvedFilename
     * @param string      $filename
     * @param array       $fileInfo
     */
    private function installDirectory(
        string $resolvedFilename,
        string $filename,
        array $fileInfo
    ): void {
        if (!empty($fileInfo['auto_symlink'])) {
            $symlinkResolvedFilename = $this->resolveFilename('code', $filename);
            $symlinkResolvedTargetFilename = $this->resolveFilename($fileInfo['location'], $filename);
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

    private function installFiles(): void
    {
        $files = $this->applicationConfig->getSkeletonConfig()->getFiles();
        if (!empty($files)) {
            foreach ($files as $filename => $file) {
                if (empty($file['location'])) {
                    throw new Exception\RuntimeException(
                        "Directory \"$filename\" must have \"location\" property set."
                    );
                }

                $resolvedFilename = $this->resolveFilename($file['location'], $filename);
                $this->installFile($resolvedFilename, $filename, $file);
            }
        }
    }

    /**
     * @param string      $resolvedFilename
     * @param string      $filename
     * @param array       $fileInfo
     */
    private function installFile(
        string $resolvedFilename,
        string $filename,
        array $fileInfo
    ): void {
        if ($fileInfo['auto_symlink']) {
            $symlinkResolvedFilename = $this->resolveFilename('code', $filename);
            $symlinkResolvedTargetFilename = $this->resolveFilename($fileInfo['location'], $filename);
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

    private function installSymlinks(): void
    {
        $symlinks = $this->applicationConfig->getSkeletonConfig()->getSymlinks();
        if (!empty($symlinks)) {
            foreach ($symlinks as $sourcePath => $symlink) {
                if (empty($symlink['location']) || empty($symlink['target_location']) || empty($symlink['target'])) {
                    throw new Exception\RuntimeException(
                        "Symlink \"$sourcePath\" must have \"location\", \"target_location\", and \"target\" properties set."
                    );
                }

                $resolvedFilename = $this->resolveFilename($symlink['location'], $sourcePath);
                $resolvedTargetFilename = $this->resolveFilename(
                    $symlink['target_location'],
                    $symlink['target']
                );
                $this->installSymlink($resolvedFilename, $resolvedTargetFilename);
            }
        }
    }

    /**
     * @param string      $location
     * @param string      $filename
     *
     * @return string
     */
    private function resolveFilename(string $location, string $filename): string
    {
        $path = $this->applicationConfig->getPath($location);
        return "$path/$filename";
    }

    /**
     * @param array       $fileInfo
     * @param array       $globalTemplateVars
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
