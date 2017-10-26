<?php
/**
 * @author Kirk Madera <kmadera@robofirm.com>
 */

namespace DevopsToolAppOrchestration;

use DevopsToolAppOrchestration\Exception\RuntimeException;
use DevopsToolCore\ShellCommandHelper;
use Exception;
use GitElephant\Repository;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Twig_Environment;
use Twig_Loader_Array;

/**
 * Class App
 *
 * @package App
 */
class AppInstall implements FileLayoutAwareInterface
{
    use FileLayoutAwareTrait;

    /**
     * @var AppSetupRepository
     */
    private $appSetupRepository;
    /**
     * @var LoggerInterface
     */
    private $logger;
    /**
     * @var null|ShellCommandHelper
     */
    private $shellCommandHelper;
    /**
     * @var AppRefreshAssets
     */
    private $appRefreshAssets;
    /**
     * @var AppRefreshDatabases
     */
    private $appRefreshDatabases;
    /**
     * @var FileLayoutHelper
     */
    private $fileLayoutHelper;
    /**
     * @var string
     */
    private $defaultDirMode;
    /**
     * @var string
     */
    private $defaultFileMode;
    /**
     * @var string
     */
    private $templateVars;
    /**
     * @var string
     */
    private $codeRepoUrl;
    /**
     * @var array
     */
    private $files;
    /**
     * @var array
     */
    private $postInstallScripts;

    /**
     * AppInstall constructor.
     *
     * @param AppSetupRepository      $appSetupRepository
     * @param AppRefreshAssets        $appRefreshAssets
     * @param AppRefreshDatabases     $appRefreshDatabases
     * @param string                  $appRoot
     * @param string                  $fileLayout
     * @param string                  $branch
     * @param string                  $codeRepoUrl
     * @param array                   $files
     * @param array                   $postInstallScripts
     * @param string                  $defaultDirMode
     * @param string                  $defaultFileMode
     * @param string                  $templateVars
     * @param FileLayoutHelper|null   $fileLayoutHelper
     * @param LoggerInterface|null    $logger
     * @param ShellCommandHelper|null $shellCommandHelper
     */
    public function __construct(
        AppSetupRepository $appSetupRepository,
        $appRoot,
        $fileLayout,
        $branch,
        $codeRepoUrl,
        $defaultDirMode,
        $defaultFileMode,
        AppRefreshAssets $appRefreshAssets = null,
        AppRefreshDatabases $appRefreshDatabases = null,
        array $files = null,
        array $postInstallScripts = null,
        $templateVars = null,
        FileLayoutHelper $fileLayoutHelper = null,
        LoggerInterface $logger = null,
        ShellCommandHelper $shellCommandHelper = null
    ) {
        $this->appSetupRepository = $appSetupRepository;
        $this->appRoot = $appRoot;
        $this->fileLayout = $fileLayout;
        $this->branch = $branch;
        $this->codeRepoUrl = $codeRepoUrl;
        $this->defaultDirMode = $defaultDirMode;
        $this->defaultFileMode = $defaultFileMode;
        $this->appRefreshAssets = $appRefreshAssets;
        $this->appRefreshDatabases = $appRefreshDatabases;
        $this->files = $files;
        $this->postInstallScripts = $postInstallScripts;
        $this->templateVars = $templateVars;
        if (is_null($fileLayoutHelper)) {
            $fileLayoutHelper = new FileLayoutHelper();
        }
        $this->fileLayoutHelper = $fileLayoutHelper;
        $this->fileLayoutHelper->loadFileLayoutPaths($this);
        if (is_null($logger)) {
            $logger = new NullLogger();
        }
        $this->logger = $logger;
        if (is_null($shellCommandHelper)) {
            $shellCommandHelper = new ShellCommandHelper();
        }
        $this->shellCommandHelper = $shellCommandHelper;
    }

    /**
     * @param bool $installCode
     * @param bool $installAssets
     * @param bool $installDatabases
     * @param bool $runPostInstallScripts
     * @param bool $reinstall
     *
     * @throws Exception
     */
    public function install(
        $installCode = true,
        $installAssets = true,
        $installDatabases = true,
        $runPostInstallScripts = true,
        $reinstall = false
    ) {
        $appRoot = $this->appRoot;
        $defaultDirMode = $this->defaultDirMode;
        $origUmask = umask(0);
        if (!is_writable($appRoot)) {
            if (is_writable(dirname($appRoot))) {
                mkdir($appRoot, $defaultDirMode);
            } else {
                throw new Exception("Project root \"$appRoot\" is not writable.");
            }
        }

        $isFirstRun = !file_exists("{$this->codePath}/.git");
        $this->prepareFileLayout();
        if ($installCode) {
            $this->installCode();
        }
        $this->installFiles($reinstall);
        if ($installAssets && $this->appRefreshAssets) {
            $this->appRefreshAssets->refreshAssets($reinstall);
        }

        if ($installDatabases && $this->appRefreshDatabases) {
            $this->appRefreshDatabases->refreshDatabases($reinstall);
        }

        if ($runPostInstallScripts) {
            $this->runPostInstallScripts($isFirstRun, $reinstall, $installCode, $installAssets, $installDatabases);
        }
        umask($origUmask);
        $this->branch = null;
    }

    /**
     * @throws Exception
     */
    private function prepareFileLayout()
    {
        $this->logger->info('Preparing file layout...');
        $appRoot = $this->appRoot;
        $defaultDirMode = $this->defaultDirMode;
        $fileLayout = $this->fileLayout;
        if ($this->codePath != $appRoot) {
            if (!file_exists($this->codePath)) {
                mkdir($this->codePath, $defaultDirMode, true);
                $this->logger->debug("Created \"{$this->codePath}\".");
            } else {
                $this->logger->debug("Skipped creating \"{$this->codePath}\". Already exists.");
            }
        }

        if ($this->localPath != $this->codePath && !file_exists($this->localPath)) {
            if (!file_exists($this->localPath)) {
                mkdir($this->localPath, $defaultDirMode, true);
                $this->logger->debug("Created \"{$this->localPath}\".");
            } else {
                $this->logger->debug("Skipped creating \"{$this->localPath}\". Already exists.");
            }
        }

        if ($this->sharedPath != $this->codePath && !file_exists($this->sharedPath)) {
            if (!file_exists($this->sharedPath)) {
                mkdir($this->sharedPath, $defaultDirMode, true);
                $this->logger->debug("Created \"{$this->sharedPath}\".");
            } else {
                $this->logger->debug("Skipped creating \"{$this->sharedPath}\". Already exists.");
            }
        }

        if (self::FILE_LAYOUT_BLUE_GREEN == $fileLayout) {
            $relativeCodePath = substr($this->codePath, strlen($appRoot) + 1);
            if (!file_exists("$appRoot/current_release")) {
                $this->logger->debug("Created symlink \"$appRoot/current_release\" -> \"$relativeCodePath\".");
                symlink($relativeCodePath, "$appRoot/current_release");
            } else {
                $this->logger->debug(
                    "Skipped creating symlink \"$appRoot/current_release\" -> \"$relativeCodePath\". Already exists."
                );
            }
        }
        $this->fileLayoutInstalled = true;
    }

    private function installCode()
    {
        if (!file_exists("{$this->codePath}/.git")) {
            $repoUrl = $this->codeRepoUrl;
            $this->logger->info("Cloning repository \"$repoUrl:$this->branch\" to \"{$this->codePath}\"...");
            $repo = new Repository($this->codePath);
            $repo->cloneFrom($repoUrl, $this->codePath);
            $repo->checkout($this->branch);
            $repo->addGlobalConfig('core.filemode', false);
        } else {
            $repoUrl = $this->codeRepoUrl;
            $this->logger->info("Pulling the latest code from \"$repoUrl:$this->branch\" to \"{$this->codePath}\"...");
            $repo = new Repository($this->codePath);
            $repo->pull('origin', $this->branch, false);
        }
    }

    /**
     * @param bool $reinstall
     *
     * @throws Exception
     */
    private function installFiles($reinstall)
    {
        if (!$this->files) {
            return;
        }

        if (!empty($this->files['directories'])) {
            foreach ($this->files['directories'] as $filename => $directory) {
                if (empty($directory['location'])) {
                    throw new Exception("Directory \"$filename\" must have \"location\" property set.");
                }

                $resolvedFilename = $this->resolveFilename($this, $directory['location'], $filename);
                $this->installDirectory($resolvedFilename, $filename, $directory, $reinstall);
            }
        }

        if (!empty($this->files['files'])) {
            foreach ($this->files['files'] as $filename => $file) {
                if (empty($file['location'])) {
                    throw new Exception("Directory \"$filename\" must have \"location\" property set.");
                }

                $resolvedFilename = $this->resolveFilename($this, $file['location'], $filename);
                $this->installFile($resolvedFilename, $filename, $file, $reinstall);
            }
        }

        if (!empty($this->files['symlinks'])) {
            foreach ($this->files['symlinks'] as $sourcePath => $symlink) {
                if (empty($symlink['location']) || empty($symlink['target_location']) || empty($symlink['target'])) {
                    throw new Exception(
                        "Symlink \"$sourcePath\" must have \"location\", \"target_location\", and \"target\" properties set."
                    );
                }

                $resolvedFilename = $this->resolveFilename($this, $symlink['location'], $sourcePath);
                $resolvedTargetFilename = $this->resolveFilename(
                    $this,
                    $symlink['target_location'],
                    $symlink['target']
                );
                $this->installSymlink($resolvedFilename, $resolvedTargetFilename, $reinstall);
            }
        }
    }

    /**
     * @param string $resolvedFilename
     * @param string $filename
     * @param array  $fileInfo
     * @param bool   $reinstall
     *
     * @throws Exception
     */
    private function installFile($resolvedFilename, $filename, $fileInfo, $reinstall)
    {
        if ($fileInfo['auto_symlink']) {
            $symlinkResolvedFilename = $this->resolveFilename($this, 'code', $filename);
            $symlinkResolvedTargetFilename = $this->resolveFilename($this, $fileInfo['location'], $filename);
            $this->installSymlink($symlinkResolvedFilename, $symlinkResolvedTargetFilename, $reinstall);
        }

        if (!$reinstall && file_exists($resolvedFilename)) {
            if (is_file($resolvedFilename) && !is_link($resolvedFilename)) {
                $this->logger->debug("Skipped creating file \"$resolvedFilename\". Already exists.");
                return;
            }

            $this->logger->err("\"$resolvedFilename\" exists, but is not a file.");
            return;
        }

        $parentDir = dirname($resolvedFilename);
        $this->ensureParentDirExists($parentDir, $reinstall);

        $globalTemplateVars = $this->templateVars;
        $mode = $this->defaultFileMode;
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

        $content = $this->renderContent($fileInfo, $globalTemplateVars, $resolvedFilename);
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
     * @param FileLayoutAwareInterface $fileLayoutAware
     * @param string                   $location
     * @param string                   $filename
     */
    private function resolveFilename(FileLayoutAwareInterface $fileLayoutAware, $location, $filename)
    {
        $pathPrefix = $this->fileLayoutHelper->resolvePathPrefix($fileLayoutAware, $location);
        $resolvedFilename = $this->appRoot;
        if ($pathPrefix) {
            $resolvedFilename .= "/$pathPrefix";
        }
        $resolvedFilename .= "/$filename";
        return $resolvedFilename;
    }

    /**
     * @param array  $fileInfo
     * @param array  $globalTemplateVars
     * @param string $resolvedFilename
     *
     * @return bool|string
     */
    private function renderContent(array $fileInfo, array $globalTemplateVars, $resolvedFilename)
    {
        if (!empty($fileInfo['source'])) {
            $content = $this->appSetupRepository->getFileContentsInHierarchy("files/{$fileInfo['source']}");
            if (false === $content) {
                $config = $this->appSetupRepository->getConfig();
                $localFilename = $config['file_root'] . '/' . $fileInfo['source'];
                if (!is_readable($localFilename)) {
                    throw new Exception(
                        "Error creating file \"$resolvedFilename\". Source file \"{$fileInfo['source']}\" not found or readable in setup repo or devops files."
                    );
                }
                $content = file_get_contents($localFilename);
            }

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
// @todo Add this conditional back in once branch strategy is fully considered for M2. 
//       Also, replace branch with the constant from FileLayoutAwareInterface
// @see https://robofirm.atlassian.net/browse/DEVOPS-545
//            if ('branch' == $this->fileLayout) {
            $databaseSanitizedBranchName = $this->sanitizeDatabaseName($this->branch);
            $urlSanitizedBranchName = $this->sanitizeUrl($this->branch);
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
//            }

            if ($templateVars && '.twig' == substr($fileInfo['source'], -5)) {
                $twig = new Twig_Environment(new Twig_Loader_Array([]));
                $template = $twig->createTemplate($content);
                $content = $template->render($templateVars);
            }
        } else {
            $content = '';
        }
        return $content;
    }

    /**
     * @param string $resolvedFilename
     * @param string $filename
     * @param array  $fileInfo
     * @param bool   $reinstall
     */
    private function installDirectory($resolvedFilename, $filename, $fileInfo, $reinstall)
    {
        if ($fileInfo['auto_symlink']) {
            $symlinkResolvedFilename = $this->resolveFilename($this, 'code', $filename);
            $symlinkResolvedTargetFilename = $this->resolveFilename($this, $fileInfo['location'], $filename);
            $this->installSymlink($symlinkResolvedFilename, $symlinkResolvedTargetFilename, $reinstall);
        }

        if (!$reinstall && file_exists($resolvedFilename)) {
            if (is_dir($resolvedFilename) && !is_link($resolvedFilename)) {
                $this->logger->debug("Skipped creating directory \"$resolvedFilename\". Already exists.");
                return;
            }

            $this->logger->err("\"$resolvedFilename\" exists, but is not a directory.");
            return;
        }

        $parentDir = dirname($resolvedFilename);
        $this->ensureParentDirExists($parentDir, $reinstall);

        $mode = $this->defaultDirMode;
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
     * @param string $resolvedFilename
     * @param string $resolvedTargetFilename
     * @param bool   $reinstall
     *
     * @throws Exception
     */
    private function installSymlink($resolvedFilename, $resolvedTargetFilename, $reinstall)
    {
        if ($resolvedFilename == $resolvedTargetFilename) {
            return;
        }

        if (!$reinstall && file_exists($resolvedFilename)) {
            if (is_link($resolvedFilename)) {
                $existingLinkTarget = readlink($resolvedFilename);
                if ($resolvedTargetFilename == $existingLinkTarget) {
                    $this->logger->debug(
                        "Skipped creating symlink \"$resolvedFilename\" pointed to \"$resolvedTargetFilename\". Already exists."
                    );
                    return;
                }

                $this->logger->err("\"$resolvedFilename\" is a symlink, but is pointed to \"$existingLinkTarget\".");
                return;
            }

            $this->logger->err("\"$resolvedFilename\" exists, but is not a symlink.");
            return;
        }

        $parentDir = dirname($resolvedFilename);
        $this->ensureParentDirExists($parentDir, $reinstall);

        $parentDir = dirname($resolvedTargetFilename);
        $this->ensureParentDirExists($parentDir, $reinstall);

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
     * @param $isFirstRun
     * @param $reinstall
     * @param $installCode
     * @param $installAssets
     * @param $installDatabases
     *
     * @throws Exception
     */
    private function runPostInstallScripts($isFirstRun, $reinstall, $installCode, $installAssets, $installDatabases)
    {
        $postInstallScripts = $this->postInstallScripts;
        if (!empty($postInstallScripts)) {
            $this->logger->info('Running post install scripts...');
            foreach ($postInstallScripts as $scriptName => $script) {
                if (empty($script['cmd'])) {
                    throw new Exception("No cmd set for post install script \"$scriptName\".");
                }

                $fullReinstall = $reinstall && $installCode && $installAssets && $installDatabases;
                if (!empty($script['run_once']) && !$isFirstRun && !$fullReinstall) {
                    $this->logger->debug(
                        "Skipped running post install script \"$scriptName\" since it set as run_once and this is not the first run."
                    );
                    continue;
                }

                if (!empty($script['triggers'])) {
                    $triggers = (array)$script['triggers'];
                    $shouldRun = ((in_array('code', $triggers) && $installCode)
                        || (in_array('assets', $triggers) && $installAssets && ($isFirstRun || $reinstall))
                        || (in_array('databases', $triggers) && $installDatabases && ($isFirstRun || $reinstall))
                    );
                    if (!$shouldRun) {
                        $this->logger->debug(
                            "Skipped running post install script \"$scriptName\" because triggers " . implode(
                                ', ',
                                $triggers
                            ) . " were not matched."
                        );
                        continue;
                    }
                }

                $this->logger->debug("Running post install script \"$scriptName\".");
                $workingDir = (!empty($script['working_dir'])) ? $script['working_dir'] : $this->codePath;
                $command = 'cd ' . escapeshellarg($workingDir) . '; ' . $script['cmd'];
                try {
                    $this->shellCommandHelper->runShellCommand($command);
                } catch (\Exception $e) {
                    throw new RuntimeException("Post install script \"$scriptName\" failed.", $e->getCode(), $e);
                }
            }
        } else {
            $this->logger->info('No post install scripts to run.');
        }
    }

    /**
     * @param string $name
     *
     * @return string
     */
    private function sanitizeDatabaseName($name)
    {
        return strtolower(preg_replace('/[^a-z0-9_]/i', '_', $name));
    }

    /**
     * @param string $url
     *
     * @return string
     */
    private function sanitizeUrl($url)
    {
        return strtolower(preg_replace('/[^a-z0-9\.-]/i', '-', $url));
    }

    /**
     * @param string $parentDir
     * @param bool   $force
     *
     * @throws Exception
     */
    private function ensureParentDirExists($parentDir, $force)
    {
        if (!(is_dir($parentDir))) {
            if (file_exists($parentDir)) {
                if ($force) {
                    unlink($parentDir);
                } else {
                    throw new Exception("Parent directory \"$parentDir\" already exists, but is not a directory.");
                }
            }
            mkdir($parentDir, $this->defaultDirMode, true);
        }
    }

}
