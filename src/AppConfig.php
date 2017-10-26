<?php
/**
 * @author Kirk Madera <kmadera@robofirm.com>
 */

namespace DevopsToolAppOrchestration;

use DevopsToolAppOrchestration\Config\FilesystemConfig;
use Exception;

class AppConfig
{
    const DATABASE_SNAPSHOT_FORMAT_MYDUMPER = 'mydumper';
    const DATABASE_SNAPSHOT_FORMAT_SQL = 'sql';
    const DATABASE_SNAPSHOT_FORMAT_TAB_DELIMITED = 'tab_delimited';

    /** @var array */
    protected $config;

    /**
     * AppConfig constructor.
     *
     * @param array $config
     */
    public function __construct(array $config)
    {
        $config['repo_revision_type'] = 'branch';
        $config = $this->filter($config);
        $this->validate($config);
        $this->config = $config;
    }

    /**
     * @param array $config
     *
     * @return array
     */
    private function filter(array $config)
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
     * @param array $config
     *
     * @throws Exception
     */
    private function validate(array $config)
    {
        $required = [
            'app_name',
            'app_root',
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
            throw new Exception(
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
            throw new Exception("Invalid file layout \"{$config['file_layout']}\".");
        }

        if (!in_array(
            $config['database_snapshot_format'],
            [
                self::DATABASE_SNAPSHOT_FORMAT_MYDUMPER,
                self::DATABASE_SNAPSHOT_FORMAT_SQL,
                self::DATABASE_SNAPSHOT_FORMAT_TAB_DELIMITED,
            ]
        )) {
            throw new Exception("Invalid database_snapshot_format \"{$config['default_snapshot_name']}\".");
        }
    }

    /**
     * @return array
     */
    public function getArrayCopy()
    {
        return $this->config;
    }

    /**
     * @return string
     */
    public function getAppName()
    {
        return $this->config['app_name'];
    }

    /**
     * @return string
     */
    public function getAppRoot()
    {
        return $this->config['app_root'];
    }

    /**
     * @return string
     */
    public function getEnvironment()
    {
        return $this->config['environment'];
    }

    /**
     * @return string|null
     */
    public function getRelativeDocumentRoot()
    {
        return isset($this->config['relative_document_root']) ? $this->config['relative_document_root'] : null;
    }

    /**
     * @return string
     */
    public function getPlatform()
    {
        return $this->config['platform'];
    }

    /**
     * @return string
     */
    public function getDatabaseSnapshotFormat()
    {
        return $this->config['database_snapshot_format'];
    }

    /**
     * @return string
     */
    public function getMaintenanceStrategy()
    {
        return $this->config['maintenance_strategy'];
    }

    /**
     * @return string
     */
    public function getDefaultFileMode()
    {
        return $this->config['default_file_mode'];
    }

    /**
     * @return string
     */
    public function getDefaultDirMode()
    {
        return $this->config['default_dir_mode'];
    }

    /**
     * @return string
     */
    public function getFileLayout()
    {
        return $this->config['file_layout'];
    }

    /**
     * @return string
     */
    public function getRepoUrl()
    {
        return $this->config['repo_url'];
    }

    /**
     * @return string
     */
    public function getDefaultBranch()
    {
        return $this->config['default_branch'];
    }

    /**
     * @return string
     */
    public function getDefaultSnapshotName()
    {
        return $this->config['default_snapshot_name'];
    }

    /**
     * @return string
     */
    public function getDefaultFilesystem()
    {
        return $this->config['default_filesystem'];
    }

    /**
     * @return string
     */
    public function getRepoRevisionType()
    {
        return $this->config['repo_revision_type'];
    }

    /**
     * @param string
     *
     * @return FilesystemConfig|null
     */
    public function getFilesystemConfig($filesystemName)
    {
        return !empty($this->config['file_systems'][$filesystemName]) ? new FilesystemConfig(
            $this->config['file_systems'][$filesystemName]
        ) : null;
    }

    /**
     * @return string|null
     */
    public function getWorkingDir()
    {
        return !empty($this->config['working_dir']) ? $this->config['working_dir'] : null;
    }

    /**
     * @return array
     */
    public function getTemplateVars()
    {
        return !empty($this->config['template_vars']) ? $this->config['template_vars'] : [];
    }

    /**
     * @return array
     */
    public function getFiles()
    {
        return !empty($this->config['files']) ? $this->config['files'] : [];
    }

    /**
     * @return array
     */
    public function getAssets()
    {
        return !empty($this->config['assets']) ? $this->config['assets'] : [];
    }

    /**
     * @return array
     */
    public function getAssetGroups()
    {
        return !empty($this->config['asset_groups']) ? $this->config['asset_groups'] : [];
    }

    /**
     * @return string|null
     */
    public function getMySqlHost()
    {
        return !empty($this->config['mysql_host']) ? $this->config['mysql_host'] : null;
    }

    /**
     * @return int|null
     */
    public function getMySqlPort()
    {
        return !empty($this->config['mysql_port']) ? (int)$this->config['mysql_port'] : null;
    }

    /**
     * @return string|null
     */
    public function getMySqlUser()
    {
        return !empty($this->config['mysql_user']) ? $this->config['mysql_user'] : null;
    }

    /**
     * @return string|null
     */
    public function getMySqlPassword()
    {
        return !empty($this->config['mysql_password']) ? $this->config['mysql_password'] : null;
    }

    /**
     * @return string|null
     */
    public function getMySqlSslCa()
    {
        return !empty($this->config['mysql_ssl_ca']) ? $this->config['mysql_ssl_ca'] : null;
    }

    /**
     * @return bool|null
     */
    public function getMySqlSslVerifyPeer()
    {
        return isset($this->config['mysql_ssl_verify_peer']) ? $this->config['mysql_ssl_verify_peer'] : null;
    }

    /**
     * @return array
     */
    public function getDatabases()
    {
        return !empty($this->config['databases']) ? $this->config['databases'] : [];
    }

    /**
     * @return array
     */
    public function getDatabaseTableGroups()
    {
        return !empty($this->config['database_table_groups']) ? $this->config['database_table_groups'] : [];
    }

    /**
     * @return array
     */
    public function getPostInstallScripts()
    {
        return !empty($this->config['post_install_scripts']) ? $this->config['post_install_scripts'] : [];
    }

    /**
     * @return array
     */
    public function getServers()
    {
        return !empty($this->config['servers']) ? $this->config['servers'] : [];
    }

    /**
     * @return array
     */
    public function getSshDefaults()
    {
        return !empty($this->config['ssh_defaults']) ? $this->config['ssh_defaults'] : [];
    }

    /**
     * @return array|mixed
     */
    public function getBuildPlans()
    {
        return !empty($this->config['build_plans']) ? $this->config['build_plans'] : [];
    }
}
