<?php
/**
 * @author Kirk Madera <kmadera@robofirm.com>
 */

namespace DevopsToolAppOrchestration;

use DevopsToolAppOrchestration\Config\FilesystemConfig;
use DevopsToolAppOrchestration\Exception;

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
        // @todo Move this default to somewhere more obvious? Where are other defaults?
        $config['repo_revision_type'] = 'branch';
        $config = $this->filter($config);
        $this->validate($config);
        $this->config = $config;
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
    public function getAppRoot(): string
    {
        return $this->config['app_root'];
    }

    /**
     * @return string
     */
    public function getAppName(): string
    {
        return $this->config['app_name'];
    }

    /**
     * @return string|null
     */
    public function getRelativeDocumentRoot(): ?string
    {
        return $this->config['relative_document_root'] ?? '.';
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
    public function getDefaultDatabaseAdapter(): string
    {
        return $this->config['default_database_adapter'] ?? 'default';
    }

    /**
     * @return string
     */
    public function getDefaultDatabaseImportExportAdapter(): string
    {
        return $this->config['default_database_importexport_adapter'] ?? null;
    }

    /**
     * @return string
     */
    public function getDatabaseSnapshotFormat(): string
    {
        return $this->config['database_snapshot_format'];
    }

    /**
     * @return string
     */
    public function getMaintenanceStrategy(): string
    {
        return $this->config['maintenance_strategy'];
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
    public function getDefaultDirMode(): string
    {
        return $this->config['default_dir_mode'];
    }

    /**
     * @return string
     */
    public function getFileLayout(): string
    {
        return $this->config['file_layout'];
    }

    /**
     * @return string
     */
    public function getRepoUrl(): string
    {
        return $this->config['repo_url'];
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
    public function getRepoRevisionType(): string
    {
        return $this->config['repo_revision_type'];
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
     * @return string|null
     */
    public function getWorkingDir(): ?string
    {
        return !empty($this->config['working_dir']) ? $this->config['working_dir'] : null;
    }

    /**
     * @return array
     */
    public function getTemplateVars(): array
    {
        return !empty($this->config['template_vars']) ? $this->config['template_vars'] : [];
    }

    /**
     * @return array
     */
    public function getFiles(): array
    {
        return !empty($this->config['files']) ? $this->config['files'] : [];
    }

    /**
     * @return array
     */
    public function getAssets(): array
    {
        return !empty($this->config['assets']) ? $this->config['assets'] : [];
    }

    /**
     * @return array
     */
    public function getAssetGroups(): array
    {
        return !empty($this->config['asset_groups']) ? $this->config['asset_groups'] : [];
    }

    /**
     * @return string|null
     */
    public function getMySqlHost(): ?string
    {
        return !empty($this->config['mysql_host']) ? $this->config['mysql_host'] : null;
    }

    /**
     * @return int|null
     */
    public function getMySqlPort(): ?int
    {
        return !empty($this->config['mysql_port']) ? (int)$this->config['mysql_port'] : null;
    }

    /**
     * @return string|null
     */
    public function getMySqlUser(): ?string
    {
        return !empty($this->config['mysql_user']) ? $this->config['mysql_user'] : null;
    }

    /**
     * @return string|null
     */
    public function getMySqlPassword(): ?string
    {
        return !empty($this->config['mysql_password']) ? $this->config['mysql_password'] : null;
    }

    /**
     * @return string|null
     */
    public function getMySqlSslCa(): ?string
    {
        return !empty($this->config['mysql_ssl_ca']) ? $this->config['mysql_ssl_ca'] : null;
    }

    /**
     * @return bool|null
     */
    public function getMySqlSslVerifyPeer(): ?string
    {
        return isset($this->config['mysql_ssl_verify_peer']) ? $this->config['mysql_ssl_verify_peer'] : null;
    }

    /**
     * @return array
     */
    public function getDatabases(): array
    {
        return !empty($this->config['databases']) ? $this->config['databases'] : [];
    }

    /**
     * @return array
     */
    public function getDatabaseTableGroups(): array
    {
        return !empty($this->config['database_table_groups']) ? $this->config['database_table_groups'] : [];
    }

    /**
     * @return array
     */
    public function getPostInstallScripts(): array
    {
        return !empty($this->config['post_install_scripts']) ? $this->config['post_install_scripts'] : [];
    }

    /**
     * @return array
     */
    public function getServers(): array
    {
        return !empty($this->config['servers']) ? $this->config['servers'] : [];
    }

    /**
     * @return array
     */
    public function getSshDefaults(): array
    {
        return !empty($this->config['ssh_defaults']) ? $this->config['ssh_defaults'] : [];
    }

    /**
     * @return array|mixed
     */
    public function getBuildPlans(): array
    {
        return !empty($this->config['build_plans']) ? $this->config['build_plans'] : [];
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
     * @param array $config
     *
     * @throws Exception\RuntimeException
     * @throws Exception\DomainException
     */
    private function validate(array $config): void
    {
        $required = [
            'app_root',
            'app_name',
            'environment',
            'platform',
            'database_snapshot_format',
            'maintenance_strategy',
            'default_file_mode',
            'default_dir_mode',
            'default_branch',
            'default_snapshot_name',
            'file_layout',
            'repo_url',
            'repo_revision_type'
        ];
        $missingRequired = [];
        foreach ($required as $name) {
            if (empty($config[$name])) {
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
            $config['file_layout'],
            [
                FileLayoutAwareInterface::FILE_LAYOUT_BLUE_GREEN,
                FileLayoutAwareInterface::FILE_LAYOUT_BRANCH,
                FileLayoutAwareInterface::FILE_LAYOUT_DEFAULT
            ]
        )) {
            throw new Exception\DomainException("Invalid file layout \"{$config['file_layout']}\".");
        }
    }


}
