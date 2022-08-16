<?php

namespace ConductorAppOrchestration\Config;

use ConductorAppOrchestration\Exception;
use ConductorAppOrchestration\FileLayoutInterface;
use Psr\Log\LoggerInterface;

class ApplicationConfig
{
    private array $config;
    private BuildConfig $buildConfig;
    private DeployConfig $deployConfig;
    private SnapshotConfig $snapshotConfig;
    private SkeletonConfig $skeletonConfig;
    private ?LoggerInterface $logger;

    public function __construct(array $config, LoggerInterface $logger = null)
    {
        $this->buildConfig = new BuildConfig($config['build'] ?? []);
        $this->deployConfig = new DeployConfig($config['deploy'] ?? []);
        $this->snapshotConfig = new SnapshotConfig($config['snapshot'] ?? []);
        $this->skeletonConfig = new SkeletonConfig($config['skeleton'] ?? []);
        unset($config['build'], $config['deploy'], $config['snapshot'], $config['skeleton']);
        $config = $this->filter($config);
        $this->config = $config;
        $this->logger = $logger;
    }

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

    public function getLogger(): ?LoggerInterface
    {
        return $this->logger;
    }

    public function setLogger(?LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

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
     * @throws Exception\RuntimeException
     * @throws Exception\DomainException
     * @todo Validate by schema instead
     *
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

        if (!in_array($this->config['file_layout'], [
            FileLayoutInterface::STRATEGY_BLUE_GREEN,
            FileLayoutInterface::STRATEGY_DEFAULT,
        ], true)) {
            throw new Exception\DomainException("Invalid file layout \"{$this->config['file_layout']}\".");
        }

        $this->buildConfig->validate();
        $this->deployConfig->validate();
        $this->snapshotConfig->validate();
        $this->skeletonConfig->validate();
    }

    public function getAppName(): string
    {
        return $this->config['app_name'];
    }

    public function getDatabases(): ?array
    {
        $databases = $this->config['databases'] ?? [];
        $snapshotDatabases = $this->getSnapshotConfig()->getDatabases();
        if ($snapshotDatabases) {
            foreach ($snapshotDatabases as $name => $snapshotDatabase) {
                if (isset($snapshotDatabase['local_database_name'])) {
                    $this->logger->warning('Use of "local_database_name" in snapshot configuration is deprecated. '
                        . 'Use top level "databases" configuration instead.');
                    if (!isset($databases[$name]['alias'])) {
                        $databases[$name]['alias'] = $snapshotDatabase['local_database_name'];
                    }
                }

                if (isset($snapshotDatabase['adapter'])) {
                    $this->logger->warning('Use of "adapter" in snapshot configuration is deprecated. '
                        . 'Use top level "databases" configuration instead.');
                    if (!isset($databases[$name]['adapter'])) {
                        $databases[$name]['adapter'] = $snapshotDatabase['adapter'];
                    }
                }

                if (isset($snapshotDatabase['importexport_adapter'])) {
                    $this->logger->warning('Use of "importexport_adapter" in snapshot configuration is deprecated. '
                        . 'Use top level "databases" configuration instead.');
                    if (!isset($databases[$name]['importexport_adapter'])) {
                        $databases[$name]['importexport_adapter'] = $snapshotDatabase['importexport_adapter'];
                    }
                }
            }
        }

        return $databases;
    }

    public function getSnapshotConfig(): SnapshotConfig
    {
        return $this->snapshotConfig;
    }

    public function getBuildConfig(): BuildConfig
    {
        return $this->buildConfig;
    }

    public function getDeployConfig(): DeployConfig
    {
        return $this->deployConfig;
    }

    public function getSkeletonConfig(): SkeletonConfig
    {
        return $this->skeletonConfig;
    }

    public function getDefaultFilesystem(): string
    {
        return $this->config['default_filesystem'];
    }

    public function getDefaultBranch(): string
    {
        return $this->config['default_branch'];
    }

    public function getDefaultDatabaseAdapter(): string
    {
        return $this->config['default_database_adapter'];
    }

    public function getDefaultDatabaseImportExportAdapter(): string
    {
        return $this->config['default_database_importexport_adapter'];
    }

    public function getDefaultDirMode(): string
    {
        return $this->config['default_dir_mode'];
    }

    public function getDefaultFileMode(): string
    {
        return $this->config['default_file_mode'];
    }

    public function getMaintenanceShellAdapters(): array
    {
        return $this->config['maintenance']['shell_adapters'] ?? ['local'];
    }

    public function getMaintenanceFilesystemAdapters(): array
    {
        return $this->config['maintenance']['filesystem_adapters'] ?? ['local'];
    }

    public function getPlatform(): string
    {
        return $this->config['platform'];
    }

    public function getRepoUrl(): string
    {
        return $this->config['repo_url'];
    }

    public function getDocumentRoot(string $buildId = null): ?string
    {
        $documentRoot = $this->getCodePath($buildId);

        if ($this->getRelativeDocumentRoot()) {
            $documentRoot .= '/' . $this->getRelativeDocumentRoot();
        }

        return $documentRoot;
    }

    public function getCodePath(string $buildId = null): string
    {
        $appRoot = $this->getAppRoot();
        switch ($this->getFileLayoutStrategy()) {
            case FileLayoutInterface::STRATEGY_BLUE_GREEN:
                if ($buildId) {
                    return "$appRoot/" . FileLayoutInterface::PATH_BUILDS . "/$buildId";
                }
                return "$appRoot/" . FileLayoutInterface::PATH_CURRENT;

            case FileLayoutInterface::STRATEGY_DEFAULT:
            default:
                return $appRoot;
        }
    }

    public function getAppRoot(): string
    {
        return $this->config['app_root'];
    }

    public function getFileLayoutStrategy(): string
    {
        return $this->config['file_layout'];
    }

    public function getRelativeDocumentRoot(): ?string
    {
        return $this->config['relative_document_root'];
    }

    public function getSshDefaults(): array
    {
        return $this->config['ssh_defaults'] ?? [];
    }

    public function getSourceFilePaths(): array
    {
        return $this->config['source_file_paths'] ?? [];
    }

    public function getTemplateVars(): array
    {
        return $this->config['template_vars'] ?? [];
    }

    public function getCurrentEnvironment(): string
    {
        return $this->config['environment'];
    }

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

    public function getPath(string $type, string $buildId = null): string
    {
        return match ($type) {
            FileLayoutInterface::PATH_ABSOLUTE => '',
            FileLayoutInterface::PATH_CODE => $this->getCodePath($buildId),
            FileLayoutInterface::PATH_CURRENT => $this->getCurrentPath(),
            FileLayoutInterface::PATH_LOCAL => $this->getLocalPath(),
            FileLayoutInterface::PATH_SHARED => $this->getSharedPath(),
            default => throw new Exception\RuntimeException('Invalid path type "' . $type . '" given.'),
        };
    }

    public function getCurrentPath(): string
    {
        $path = $this->getAppRoot();
        if (FileLayoutInterface::STRATEGY_BLUE_GREEN === $this->getFileLayoutStrategy()) {
            $path .= '/' . FileLayoutInterface::PATH_CURRENT;
        }
        return $path;
    }

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

    public function getSharedPath(): string
    {
        if (!empty($this->config['shared_path'])) {
            return $this->config['shared_path'];
        }

        $appRoot = $this->getAppRoot();
        return match ($this->getFileLayoutStrategy()) {
            FileLayoutInterface::STRATEGY_BLUE_GREEN => "$appRoot/" . FileLayoutInterface::PATH_SHARED,
            default => $appRoot,
        };
    }

    public function getPreviousPath(): string
    {
        $path = $this->getAppRoot();
        if (FileLayoutInterface::STRATEGY_BLUE_GREEN === $this->getFileLayoutStrategy()) {
            $path .= '/' . FileLayoutInterface::PATH_PREVIOUS;
        }
        return $path;
    }
}
