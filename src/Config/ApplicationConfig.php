<?php
/**
 * @author Kirk Madera <kmadera@robofirm.com>
 */

namespace ConductorAppOrchestration\Config;

use ConductorAppOrchestration\Exception;
use ConductorAppOrchestration\FileLayoutInterface;

class ApplicationConfig
{
    /**
     * @var array
     */
    private $config;
    /**
     * @var BuildConfig
     */
    private $buildConfig;
    /**
     * @var DeployConfig
     */
    private $deployConfig;
    /**
     * @var SnapshotConfig
     */
    private $snapshotConfig;
    /**
     * @var SkeletonConfig
     */
    private $skeletonConfig;

    /**
     * ApplicationConfig constructor.
     *
     * @param array $config
     */
    public function __construct(array $config)
    {
        $this->buildConfig = new BuildConfig($config['build'] ?? []);
        $this->deployConfig = new DeployConfig($config['deploy'] ?? []);
        $this->snapshotConfig = new SnapshotConfig($config['snapshot'] ?? []);
        $this->skeletonConfig = new SkeletonConfig($config['skeleton'] ?? []);
        unset($config['build'], $config['deploy'], $config['snapshot'], $config['skeleton']);
        $config = $this->filter($config);
        $this->config = $config;
    }

    /**
     * @return array
     */
    public function toArray(): array
    {
        return array_merge(
            $this->config,
            ['build' => $this->buildConfig->toArray()],
            ['deploy' => $this->deployConfig->toArray()],
            ['snapshot' => $this->snapshotConfig->toArray()],
            ['skeleton' => $this->skeletonConfig->toArray()]
        );
    }

    /**
     * @todo Validate by schema instead
     *
     * @throws Exception\RuntimeException
     * @throws Exception\DomainException
     */
    public function validate(): void
    {
        $required = [
            'app_root',
            'app_name',
            'default_filesystem',
            'environment',
            'default_branch',
            'default_database_adapter',
            'default_database_importexport_adapter',
            'default_dir_mode',
            'default_file_mode',
            'platform',
            'relative_document_root',
            'repo_url',
        ];
        $missingRequired = [];
        foreach ($required as $name) {
            if (empty($this->config[$name])) {
                $missingRequired[] = $name;
            }
        }
        if ($missingRequired) {
            throw new Exception\RuntimeException(
                'Missing required values for top level configuration key(s): "' . implode('", "', $missingRequired)
                . '"'
            );
        }

        if (!in_array(
            $this->config['file_layout'],
            [
                FileLayoutInterface::STRATEGY_BLUE_GREEN,
                FileLayoutInterface::STRATEGY_DEFAULT
            ]
        )) {
            throw new Exception\DomainException("Invalid file layout \"{$this->config['file_layout']}\".");
        }

        $this->buildConfig->validate();
        $this->deployConfig->validate();
        $this->snapshotConfig->validate();
        $this->skeletonConfig->validate();
    }

    /**
     * @return string
     */
    public function getAppName(): string
    {
        return $this->config['app_name'];
    }

    /**
     * @return string
     */
    public function getAppRoot(): string
    {
        return $this->config['app_root'];
    }

    /**
     * @return BuildConfig
     */
    public function getBuildConfig(): BuildConfig
    {
        return $this->buildConfig;
    }

    /**
     * @return DeployConfig
     */
    public function getDeployConfig(): DeployConfig
    {
        return $this->deployConfig;
    }

    /**
     * @return SnapshotConfig
     */
    public function getSnapshotConfig(): SnapshotConfig
    {
        return $this->snapshotConfig;
    }

    /**
     * @return SkeletonConfig
     */
    public function getSkeletonConfig(): SkeletonConfig
    {
        return $this->skeletonConfig;
    }

    /**
     * @return string
     */
    public function getDefaultFilesystem(): string
    {
        return $this->config['default_filesystem'];
    }

    /**
     * @return string
     */
    public function getDefaultBranch(): string
    {
        return $this->config['default_branch'];
    }

    /**
     * @return string
     */
    public function getDefaultDatabaseAdapter(): string
    {
        return $this->config['default_database_adapter'];
    }

    /**
     * @return string
     */
    public function getDefaultDatabaseImportExportAdapter(): string
    {
        return $this->config['default_database_importexport_adapter'];
    }

    /**
     * @return string
     */
    public function getDefaultDirMode(): string
    {
        return $this->config['default_dir_mode'];
    }

    /**
     * @return string
     */
    public function getDefaultFileMode(): string
    {
        return $this->config['default_file_mode'];
    }

    /**
     * @return string
     */
    public function getFileLayoutStrategy(): string
    {
        return $this->config['file_layout'];
    }

    /**
     * @return array
     */
    public function getMaintenanceShellAdapters(): array
    {
        return $this->config['maintenance']['shell_adapters'] ?? ['local'];
    }

    /**
     * @return array
     */
    public function getMaintenanceFilesystemAdapters(): array
    {
        return $this->config['maintenance']['filesystem_adapters'] ?? ['local'];
    }

    /**
     * @return string
     */
    public function getPlatform(): string
    {
        return $this->config['platform'];
    }

    /**
     * @return string
     */
    public function getRepoUrl(): string
    {
        return $this->config['repo_url'];
    }

    /**
     * @return string|null
     */
    public function getRelativeDocumentRoot(): ?string
    {
        return $this->config['relative_document_root'];
    }

    /**
     * @param string|null $buildId
     *
     * @return null|string
     */
    public function getDocumentRoot(string $buildId = null): ?string
    {
        $documentRoot = $this->getCodePath($buildId);

        if ($this->getRelativeDocumentRoot()) {
            $documentRoot .= '/' . $this->getRelativeDocumentRoot();
        }

        return $documentRoot;
    }

    /**
     * @return array
     */
    public function getSshDefaults(): array
    {
        return $this->config['ssh_defaults'] ?? [];
    }

    /**
     * @return array
     */
    public function getSourceFilePaths(): array
    {
        return $this->config['source_file_paths'] ?? [];
    }

    /**
     * @return array
     */
    public function getTemplateVars(): array
    {
        return $this->config['template_vars'] ?? [];
    }

    /**
     * @return string
     */
    public function getCurrentEnvironment(): string
    {
        return $this->config['environment'];
    }

    /**
     * @param string $string
     *
     * @return string
     */
    private function sanitizeForFilepath(string $string): string
    {
        return strtolower(preg_replace('/[^a-z0-9\.-]/i', '-', $string));
    }

    /**
     * @param array $config
     *
     * @return array
     */
    private function filter(array $config): array
    {
        $filteredConfig = $config;
        if (!empty($config['app_root'])) {
            $filteredConfig['app_root'] = rtrim($config['app_root'], '/');
        }

        if (!empty($config['relative_document_root'])) {
            $filteredConfig['relative_document_root'] = rtrim($config['relative_document_root'], '/');
        }

        if (!empty($config['default_file_mode'])) {
            if (is_string($config['default_file_mode'])) {
                $filteredConfig['default_file_mode'] = octdec($config['default_file_mode']);
            } else {
                $filteredConfig['default_file_mode'] = (int)$config['default_file_mode'];
            }
        }

        if (!empty($config['default_dir_mode'])) {
            if (is_string($config['default_dir_mode'])) {
                $filteredConfig['default_dir_mode'] = octdec($config['default_dir_mode']);
            } else {
                $filteredConfig['default_dir_mode'] = (int)$config['default_dir_mode'];
            }
        }
        return $filteredConfig;
    }


    /**
     * @param string $relativeFilename
     *
     * @return string
     */
    public function getSourceFile(string $relativeFilename): string
    {
        $filename = '';
        if (!empty($this->config['source_file_paths']) && is_array($this->config['source_file_paths'])) {
            foreach ($this->config['source_file_paths'] as $path) {
                $filename = "$path/$relativeFilename";
                if (file_exists("$path/$relativeFilename")) {
                    $filename = "$path/$relativeFilename";
                    break;
                }
            }
        }

        if (!$filename) {
            throw new Exception\RuntimeException(
                "Source file \"$relativeFilename\" not found in configuration."
            );
        }

        return $filename;
    }

    /**
     * @return string
     */
    public function getCodePath(string $buildId = null): string
    {
        $appRoot = $this->getAppRoot();
        switch ($this->getFileLayoutStrategy()) {
            case FileLayoutInterface::STRATEGY_BLUE_GREEN:
                if($buildId){
                    return "$appRoot/" . FileLayoutInterface::PATH_BUILDS . "/$buildId";
                }
                return "$appRoot/" . FileLayoutInterface::PATH_CURRENT ;

            case FileLayoutInterface::STRATEGY_DEFAULT:
            default:
                return $appRoot;
        }
    }

    /**
     * @return string
     */
    public function getLocalPath(): string
    {
        $appRoot = $this->getAppRoot();
        switch ($this->getFileLayoutStrategy()) {
            case FileLayoutInterface::STRATEGY_BLUE_GREEN:
                return "$appRoot/" . FileLayoutInterface::PATH_LOCAL;

            case FileLayoutInterface::STRATEGY_DEFAULT:
            default:
                return $appRoot;
        }
    }

    /**
     * @return string
     */
    public function getSharedPath(): string
    {
        if (!empty($this->config['shared_path'])) {
            return $this->config['shared_path'];
        }

        $appRoot = $this->getAppRoot();
        switch ($this->getFileLayoutStrategy()) {
            case FileLayoutInterface::STRATEGY_BLUE_GREEN:
                return "$appRoot/" . FileLayoutInterface::PATH_SHARED;

            case FileLayoutInterface::STRATEGY_DEFAULT:
            default:
                return $appRoot;
        }
    }

    /**
     * @param string      $type
     * @param string|null $buildId
     *
     * @return string
     */
    public function getPath(string $type, string $buildId = null): string
    {
        switch ($type) {
            case FileLayoutInterface::PATH_ABSOLUTE:
                return '';
            case FileLayoutInterface::PATH_CODE:
                return $this->getCodePath($buildId);
            case FileLayoutInterface::PATH_CURRENT:
                return $this->getCurrentPath();
            case FileLayoutInterface::PATH_LOCAL:
                return $this->getLocalPath();
            case FileLayoutInterface::PATH_SHARED:
                return $this->getSharedPath();
            default:
                throw new Exception\RuntimeException('Invalid path type "' . $type . '" given.');
        }
    }

    /**
     * @return string
     */
    public function getCurrentPath(): string
    {
        $path = $this->getAppRoot();
        if (FileLayoutInterface::STRATEGY_BLUE_GREEN == $this->getFileLayoutStrategy()) {
            $path .= '/' . FileLayoutInterface::PATH_CURRENT;
        }
        return $path;
    }

    /**
     * @return string
     */
    public function getPreviousPath(): string
    {
        $path = $this->getAppRoot();
        if (FileLayoutInterface::STRATEGY_BLUE_GREEN == $this->getFileLayoutStrategy()) {
            $path .= '/' . FileLayoutInterface::PATH_PREVIOUS;
        }
        return $path;
    }
}
