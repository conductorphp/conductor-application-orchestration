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
            'default_filesystem_adapter',
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
                FileLayoutInterface::STRATEGY_BRANCH,
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
    public function getDefaultSnapshotPlan(): string
    {
        return $this->config['snapshot']['default_plan'] ?? 'default';
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
     * @return array
     */
    public function getArrayCopy(): array
    {
        return $this->config;
    }

    /**
     * @return string
     */
    public function getCurrentEnvironment(): string
    {
        return $this->config['environment'];
    }

    /**
     * @param string $branch
     *
     * @return string
     */
    private function sanitizeBranchForFilepath(string $branch): string
    {
        return strtolower(preg_replace('/[^a-z0-9\.-]/i', '-', $branch));
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
     * @param string|null $branch
     *
     * @return string
     */
    public function getCodePath(string $branch = null): string
    {
        if ($branch) {
            $branch = $this->sanitizeBranchForFilepath($branch);
        }
        $appRoot = $this->getAppRoot();
        switch ($this->getFileLayoutStrategy()) {
            case FileLayoutInterface::STRATEGY_BLUE_GREEN:
                return "$appRoot/" . FileLayoutInterface::PATH_RELEASES . "/$branch";

            case FileLayoutInterface::STRATEGY_BRANCH:
                return "$appRoot/" . FileLayoutInterface::PATH_BRANCHES . "/$branch";

            case FileLayoutInterface::STRATEGY_DEFAULT:
            default:
                return $appRoot;
        }
    }

    /**
     * @param string|null $branch
     *
     * @return string
     */
    public function getLocalPath(string $branch = null): string
    {
        if ($branch) {
            $branch = $this->sanitizeBranchForFilepath($branch);
        }
        $appRoot = $this->getAppRoot();
        switch ($this->getFileLayoutStrategy()) {
            case FileLayoutInterface::STRATEGY_BLUE_GREEN:
                return "$appRoot/" . FileLayoutInterface::PATH_SHARED;

            case FileLayoutInterface::STRATEGY_BRANCH:
                return "$appRoot/" . FileLayoutInterface::PATH_BRANCHES . "/$branch";

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
        $appRoot = $this->getAppRoot();
        switch ($this->getFileLayoutStrategy()) {
            case FileLayoutInterface::STRATEGY_BLUE_GREEN:
                return "$appRoot/" . FileLayoutInterface::PATH_SHARED;

            case FileLayoutInterface::STRATEGY_BRANCH:
                return "$appRoot/" . FileLayoutInterface::PATH_SHARED;

            case FileLayoutInterface::STRATEGY_DEFAULT:
            default:
                return $appRoot;
        }
    }

    /**
     * @param string      $type
     * @param string|null $branch
     *
     * @return string
     */
    public function getPath(string $type, string $branch = null): string
    {
        switch ($type) {
            case FileLayoutInterface::PATH_CODE:
                return $this->getCodePath($branch);
            case FileLayoutInterface::PATH_LOCAL:
                return $this->getLocalPath($branch);
            case FileLayoutInterface::PATH_SHARED:
                return $this->getSharedPath();
            default:
                throw new Exception\RuntimeException('Invalid path type "' . $type . '" given.');
        }
    }
}
