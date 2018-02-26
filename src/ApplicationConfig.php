<?php
/**
 * @author Kirk Madera <kmadera@robofirm.com>
 */

namespace ConductorAppOrchestration;

use ConductorAppOrchestration\Config\FilesystemConfig;
use ConductorAppOrchestration\Exception;

class ApplicationConfig
{
    const FILE_LAYOUT_BLUE_GREEN = 'blue_green';
    const FILE_LAYOUT_BRANCH = 'branch';
    const FILE_LAYOUT_DEFAULT = 'default';

    const PATH_CURRENT_RELEASE = 'current_release';
    const PATH_RELEASES = 'releases';
    const PATH_BRANCHES = 'branches';
    const PATH_CODE = 'code';
    const PATH_LOCAL = 'local';
    const PATH_SHARED = 'shared';

    /** @var array */
    protected $config;

    /**
     * AppConfig constructor.
     *
     * @param array $config
     */
    public function __construct(array $config)
    {
        $config = $this->filter($config);
        $this->config = $config;
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
     * @return array
     */
    public function getAssetGroups(): array
    {
        return $this->config['asset_groups'] ?? [];
    }

    /**
     * @return array
     */
    public function getAssets(): array
    {
        return $this->config['assets'] ?? [];
    }

    /**
     * @return array
     */
    public function getBuildExcludePaths(): array
    {
        return $this->config['build_exclude_paths'] ?? [];
    }

    /**
     * @return array|mixed
     */
    public function getBuildPlans(): array
    {
        return $this->config['build_plans'] ?? [];
    }

    /**
     * @return array
     */
    public function getDatabases(): array
    {
        return $this->config['databases'] ?? [];
    }

    /**
     * @return array
     */
    public function getDatabaseTableGroups(): array
    {
        return $this->config['database_table_groups'] ?? [];
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
    public function getDefaultSnapshotName(): string
    {
        return $this->config['default_snapshot_name'];
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
    public function getFileLayout(): string
    {
        return $this->config['file_layout'];
    }

    /**
     * @return array
     */
    public function getFiles(): array
    {
        return $this->config['files'] ?? [];
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
            'environment',
            'default_branch',
            'default_database_adapter',
            'default_database_importexport_adapter',
            'default_dir_mode',
            'default_file_mode',
            'default_filesystem_adapter',
            'default_snapshot_name',
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
                FileLayoutAwareInterface::FILE_LAYOUT_BLUE_GREEN,
                FileLayoutAwareInterface::FILE_LAYOUT_BRANCH,
                FileLayoutAwareInterface::FILE_LAYOUT_DEFAULT
            ]
        )) {
            throw new Exception\DomainException("Invalid file layout \"{$this->config['file_layout']}\".");
        }
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
     * @param string
     *
     * @return FilesystemConfig
     * @throws Exception\DomainException if filesystem doesn't exist in application configuration
     */
    public function getFilesystemConfig($filesystemName): FilesystemConfig
    {
        if (empty($this->config['file_systems'][$filesystemName])) {
            throw new Exception\DomainException(
                'Filesystem "' . $filesystemName . '" not defined in application configuration.'
            );
        }

        return new FilesystemConfig($this->config['file_systems'][$filesystemName]);
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
        switch ($this->getFileLayout()) {
            case FileLayoutAwareInterface::FILE_LAYOUT_BLUE_GREEN:
                return "$appRoot/" . FileLayoutAwareInterface::PATH_RELEASES . "/$branch";

            case FileLayoutAwareInterface::FILE_LAYOUT_BRANCH:
                return "$appRoot/" . FileLayoutAwareInterface::PATH_BRANCHES . "/$branch";

            case FileLayoutAwareInterface::FILE_LAYOUT_DEFAULT:
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
        switch ($this->getFileLayout()) {
            case FileLayoutAwareInterface::FILE_LAYOUT_BLUE_GREEN:
                return "$appRoot/" . FileLayoutAwareInterface::PATH_SHARED;

            case FileLayoutAwareInterface::FILE_LAYOUT_BRANCH:
                return "$appRoot/" . FileLayoutAwareInterface::PATH_BRANCHES . "/$branch";

            case FileLayoutAwareInterface::FILE_LAYOUT_DEFAULT:
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
        switch ($this->getFileLayout()) {
            case FileLayoutAwareInterface::FILE_LAYOUT_BLUE_GREEN:
                return "$appRoot/" . FileLayoutAwareInterface::PATH_SHARED;

            case FileLayoutAwareInterface::FILE_LAYOUT_BRANCH:
                return "$appRoot/" . FileLayoutAwareInterface::PATH_SHARED;

            case FileLayoutAwareInterface::FILE_LAYOUT_DEFAULT:
            default:
                return $appRoot;
        }
    }
}
