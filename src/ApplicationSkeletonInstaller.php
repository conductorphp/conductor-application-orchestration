<?php
/**
 * @author Kirk Madera <kmadera@robofirm.com>
 */

namespace ConductorAppOrchestration;

use ConductorCore\ShellCommandHelper;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Twig\Environment as TwigEnvironment;
use Twig\Loader\ArrayLoader as TwigArrayLoader;

/**
 * Class ApplicationSkeletonInstaller
 *
 * @package ConductorAppOrchestration
 */
class ApplicationSkeletonInstaller
{
    /**
     * @var ApplicationConfig
     */
    private $applicationConfig;
    /**
     * @var ShellCommandHelper
     */
    private $shellCommandHelper;
    /**
     * @var FileLayoutHelper
     */
    private $fileLayoutHelper;
    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * ApplicationSkeletonInstaller constructor.
     *
     * @param ShellCommandHelper   $shellCommandHelper
     * @param FileLayoutHelper     $fileLayoutHelper
     * @param LoggerInterface|null $logger
     */
    public function __construct(
        ApplicationConfig $applicationConfig,
        ShellCommandHelper $shellCommandHelper,
        FileLayoutHelper $fileLayoutHelper,
        LoggerInterface $logger = null
    ) {
        $this->applicationConfig = $applicationConfig;
        $this->shellCommandHelper = $shellCommandHelper;
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

    public function installSkeleton(
        string $branch,
        bool $replaceFiles = false
    ): void {
        $origUmask = umask(0);
        $this->prepareFileLayout();
        $this->installAppFiles($branch, $replaceFiles);
        umask($origUmask);
    }

    public function prepareFileLayout(): void
    {
        $this->logger->info('Preparing file layout.');
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
        if (FileLayoutAwareInterface::FILE_LAYOUT_BLUE_GREEN == $this->applicationConfig->getFileLayout()) {
            $appRoot = $this->applicationConfig->getAppRoot();
            $relativeCodePath = substr($this->applicationConfig->getCodePath(), strlen($appRoot) + 1);
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
     * @param string $branch
     * @param bool   $replace
     */
    public function installAppFiles(string $branch, bool $replace = false): void
    {
        $this->installDirectories($replace);
        $this->installFiles($branch, $replace);
        $this->installSymlinks($replace);
    }

    /**
     * @param bool $replace
     */
    private function installDirectories(bool $replace): void
    {
        $files = $this->applicationConfig->getFiles();
        if (!empty($files['directories'])) {
            foreach ($files['directories'] as $filename => $directory) {
                if (empty($directory['location'])) {
                    throw new Exception\RuntimeException(
                        "Directory \"$filename\" must have \"location\" property set."
                    );
                }

                $resolvedFilename = $this->resolveFilename($directory['location'], $filename);
                $this->installDirectory($resolvedFilename, $filename, $directory, $replace);
            }
        }
    }

    /**
     * @param string $resolvedFilename
     * @param string $filename
     * @param array  $fileInfo
     * @param bool   $replace
     */
    private function installDirectory(
        string $resolvedFilename,
        string $filename,
        array $fileInfo,
        bool $replace
    ): void {
        if ($fileInfo['auto_symlink']) {
            $symlinkResolvedFilename = $this->resolveFilename('code', $filename);
            $symlinkResolvedTargetFilename = $this->resolveFilename($fileInfo['location'], $filename);
            $this->installSymlink($symlinkResolvedFilename, $symlinkResolvedTargetFilename, $replace);
        }

        if (!$replace && file_exists($resolvedFilename)) {
            if (is_dir($resolvedFilename) && !is_link($resolvedFilename)) {
                $this->logger->debug("Skipped creating directory \"$resolvedFilename\". Already exists.");
                return;
            }

            $this->logger->error("\"$resolvedFilename\" exists, but is not a directory.");
            return;
        }

        $parentDir = dirname($resolvedFilename);
        $this->ensureDirExists($parentDir, $replace);

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
     * @param string $branch
     * @param bool   $replace
     */
    private function installFiles(string $branch, bool $replace): void
    {
        $files = $this->applicationConfig->getFiles();
        if (!empty($files['files'])) {
            foreach ($files['files'] as $filename => $file) {
                if (empty($file['location'])) {
                    throw new Exception\RuntimeException(
                        "Directory \"$filename\" must have \"location\" property set."
                    );
                }

                $resolvedFilename = $this->resolveFilename($file['location'], $filename);
                $this->installFile($resolvedFilename, $filename, $file, $branch, $replace);
            }
        }
    }

    /**
     * @param string $resolvedFilename
     * @param string $filename
     * @param array  $fileInfo
     * @param string $branch
     * @param bool   $replace
     */
    private function installFile(
        string $resolvedFilename,
        string $filename,
        array $fileInfo,
        string $branch,
        bool $replace
    ): void {
        if ($fileInfo['auto_symlink']) {
            $symlinkResolvedFilename = $this->resolveFilename('code', $filename);
            $symlinkResolvedTargetFilename = $this->resolveFilename($fileInfo['location'], $filename);
            $this->installSymlink($symlinkResolvedFilename, $symlinkResolvedTargetFilename, $replace);
        }

        if (!$replace && file_exists($resolvedFilename)) {
            if (is_file($resolvedFilename) && !is_link($resolvedFilename)) {
                $this->logger->debug("Skipped creating file \"$resolvedFilename\". Already exists.");
                return;
            }

            $this->logger->error("\"$resolvedFilename\" exists, but is not a file.");
            return;
        }

        $parentDir = dirname($resolvedFilename);
        $this->ensureDirExists($parentDir, $replace);

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
                    $this->shellCommandHelper->runShellCommand($command);
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

    /**
     * @param bool $replace
     */
    private function installSymlinks(bool $replace): void
    {
        $files = $this->applicationConfig->getFiles();
        if (!empty($files['symlinks'])) {
            foreach ($files['symlinks'] as $sourcePath => $symlink) {
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
                $this->installSymlink($resolvedFilename, $resolvedTargetFilename, $replace);
            }
        }
    }

    /**
     * @param string $location
     * @param string $filename
     *
     * @return string
     */
    private function resolveFilename(string $location, string $filename): string
    {
        $pathPrefix = $this->fileLayoutHelper->resolvePathPrefix($this->applicationConfig, $location);
        $resolvedFilename = $this->applicationConfig->getAppRoot();
        if ($pathPrefix) {
            $resolvedFilename .= "/$pathPrefix";
        }
        $resolvedFilename .= "/$filename";
        return $resolvedFilename;
    }

    /**
     * @param array  $fileInfo
     * @param array  $globalTemplateVars
     * @param string $branch
     *
     * @return string
     */
    private function renderContent(array $fileInfo, array $globalTemplateVars, string $branch): string
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
        if ('branch' == $this->applicationConfig->getFileLayout()) {
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
     * @param bool   $replace
     */
    private function installSymlink(string $resolvedFilename, string $resolvedTargetFilename, bool $replace): void
    {
        if ($resolvedFilename == $resolvedTargetFilename) {
            return;
        }

        if (!$replace && file_exists($resolvedFilename)) {
            if (is_link($resolvedFilename)) {
                $existingLinkTarget = readlink($resolvedFilename);
                if ($resolvedTargetFilename == $existingLinkTarget) {
                    $this->logger->debug(
                        "Skipped creating symlink \"$resolvedFilename\" pointed to \"$resolvedTargetFilename\". Already exists."
                    );
                    return;
                }

                $this->logger->error("\"$resolvedFilename\" is a symlink, but is pointed to \"$existingLinkTarget\".");
                return;
            }

            $this->logger->error("\"$resolvedFilename\" exists, but is not a symlink.");
            return;
        }

        $parentDir = dirname($resolvedFilename);
        $this->ensureDirExists($parentDir, $replace);

        $parentDir = dirname($resolvedTargetFilename);
        $this->ensureDirExists($parentDir, $replace);

        if (file_exists($resolvedFilename) || is_link($resolvedFilename)) {
            if (is_link($resolvedFilename) || is_file($resolvedFilename)) {
                unlink($resolvedFilename);
            } else {
                $command = 'rm -rf ' . escapeshellarg($resolvedFilename);
                $this->shellCommandHelper->runShellCommand($command);
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
     * @param bool   $force
     *
     * @throws Exception\RuntimeException if path already exists, but is not a directory
     */
    private function ensureDirExists(string $path, bool $force): void
    {
        if (!(is_dir($path))) {
            if (file_exists($path)) {
                if ($force) {
                    unlink($path);
                } else {
                    throw new Exception\RuntimeException("Path \"$path\" already exists, but is not a directory.");
                }
            }
            mkdir($path, $this->applicationConfig->getDefaultDirMode(), true);
        }
    }

}
