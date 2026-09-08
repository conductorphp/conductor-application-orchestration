<?php

declare(strict_types=1);

namespace ConductorAppOrchestration\Config;

use ConductorAppOrchestration\Exception;
use ConductorAppOrchestration\FileLayoutInterface;
use ConductorCore\Config\ParsesConfigTrait;
use ConductorCore\Config\Schema\SchemaBuilder;
use ConductorCore\Config\Schema\SchemaInterface;
use Psr\Log\LoggerInterface;

use function array_diff_key;
use function array_flip;
use function file_exists;
use function is_array;
use function rtrim;

/**
 * The application being orchestrated.
 *
 * Schema-validated and immutable. Every value is checked once, at construction, so a command cannot
 * start work against a config that is missing `app_root` and discover it three steps into a deploy —
 * and a reader of this class can see the shape of the config without reverse-engineering it from
 * `?? null` chains.
 *
 * ## What replaced what
 *
 * The old `validate()` is gone. It checked twelve keys with `empty()` and threw on the first batch,
 * which had two problems: `empty()` also rejects `0` and `'0'` (wrong for anything numeric), and it
 * was a separate step every console command had to remember to call. The schema covers all of it, at
 * construction, and reports every problem at once. Callers should delete their `validate()` call.
 *
 * The old `filter()` is gone too. It quietly rewrote three values — `rtrim('/')` on two paths and an
 * `octdec()` on file modes — with nothing naming the transformation. Those are now schema
 * transformers, declared next to the field they apply to.
 *
 * `setLogger()` is gone because this object is readonly. Pass the logger to the constructor. It is
 * used only to warn about deprecated snapshot config, which now happens ONCE here rather than on
 * every call to `getDatabases()` — where it was also a latent null dereference, since the factory
 * never set a logger and nothing guarded the call.
 */
readonly class ApplicationConfig
{
    use ParsesConfigTrait;

    public const CONFIG_KEY = 'application_orchestration.application';

    /** Keys this class assigns to a property; anything else goes to {@see $passthrough}. */
    private const DECLARED_KEYS = [
        'app_name',
        'app_root',
        'platform',
        'repo_url',
        'environment',
        'file_layout',
        'default_branch',
        'default_filesystem',
        'default_database_adapter',
        'default_database_importexport_adapter',
        'default_dir_mode',
        'default_file_mode',
        'relative_document_root',
        'shared_path',
        'databases',
        'servers',
        'ssh_defaults',
        'source_file_paths',
        'template_vars',
        'environment_vars',
        'maintenance',
    ];

    public string $appName;
    public string $appRoot;
    public string $platform;
    public string $repoUrl;
    public string $environment;
    public string $fileLayout;
    public string $defaultBranch;
    public string $defaultFilesystem;
    public string $defaultDatabaseAdapter;
    public string $defaultDatabaseImportExportAdapter;

    /** Permission modes as the int `mkdir()` and `chmod()` take — see {@see SchemaBuilder::fileMode()}. */
    public int $defaultDirMode;
    public int $defaultFileMode;

    public ?string $relativeDocumentRoot;
    public ?string $sharedPath;

    /** @var array<string, array<string, mixed>> */
    public array $databases;

    /** @var array<string, mixed> */
    public array $servers;

    /** @var array<string, mixed> */
    public array $sshDefaults;

    /** @var list<string> */
    public array $sourceFilePaths;

    /** @var array<string, mixed> */
    public array $templateVars;

    /**
     * `${VAR}` values interpolated into deploy-time strings, e.g. a replacement's `to`.
     *
     * Read by {@see \ConductorAppOrchestration\Deploy\DatabaseReplacementScript} and
     * {@see \ConductorAppOrchestration\Deploy\PostImportSupport}. It was never declared in
     * conductor's own defaults and survived only because the old `toArray()` returned the raw config
     * array, unknown keys included — which is precisely the kind of undeclared-but-load-bearing key
     * a schema is meant to surface.
     *
     * @var array<string, string>
     */
    public array $environmentVars;

    /**
     * Config keys this class does not model, kept so nothing is silently dropped.
     *
     * Conductor merges config from its own defaults, a platform-support package, the project and the
     * environment. Reconstructing `toArray()` from declared properties alone would quietly discard
     * whatever a platform package contributes, and `conductor app:config:show` would stop showing it.
     * Declaring a key is still the goal; this only ensures the cost of not having declared one is a
     * missing type, not a missing value.
     *
     * @var array<string, mixed>
     */
    private array $passthrough;

    /** @var list<string> */
    public array $maintenanceShellAdapters;

    /** @var list<string> */
    public array $maintenanceFilesystemAdapters;

    public BuildConfig $buildConfig;
    public DeployConfig $deployConfig;
    public SnapshotConfig $snapshotConfig;
    public SkeletonConfig $skeletonConfig;

    /** @param array<string, mixed> $config */
    public function __construct(array $config, ?LoggerInterface $logger = null)
    {
        $this->buildConfig    = new BuildConfig($config['build'] ?? null);
        $this->deployConfig   = new DeployConfig($config['deploy'] ?? null);
        $this->snapshotConfig = new SnapshotConfig($config['snapshot'] ?? null);
        $this->skeletonConfig = new SkeletonConfig($config['skeleton'] ?? null);

        unset($config['build'], $config['deploy'], $config['snapshot'], $config['skeleton']);

        $parsed = $this->parseConfig($config, $this->schema(), self::CONFIG_KEY);

        $this->appName                            = $parsed['app_name'];
        $this->appRoot                            = $parsed['app_root'];
        $this->platform                           = $parsed['platform'];
        $this->repoUrl                            = $parsed['repo_url'];
        $this->environment                        = $parsed['environment'];
        $this->fileLayout                         = $parsed['file_layout'];
        $this->defaultBranch                      = $parsed['default_branch'];
        $this->defaultFilesystem                  = $parsed['default_filesystem'];
        $this->defaultDatabaseAdapter             = $parsed['default_database_adapter'];
        $this->defaultDatabaseImportExportAdapter = $parsed['default_database_importexport_adapter'];
        $this->defaultDirMode                     = $parsed['default_dir_mode'];
        $this->defaultFileMode                    = $parsed['default_file_mode'];
        $this->relativeDocumentRoot               = $parsed['relative_document_root'] ?? null;
        $this->sharedPath                         = $parsed['shared_path'] ?? null;
        $this->servers                            = $parsed['servers'] ?? [];
        $this->sshDefaults                        = $parsed['ssh_defaults'] ?? [];
        $this->sourceFilePaths                    = $parsed['source_file_paths'] ?? [];
        $this->templateVars                       = $parsed['template_vars'] ?? [];
        $this->environmentVars                    = $parsed['environment_vars'] ?? [];
        $this->maintenanceShellAdapters           = $parsed['maintenance']['shell_adapters'] ?? ['local'];
        $this->maintenanceFilesystemAdapters      = $parsed['maintenance']['filesystem_adapters'] ?? ['local'];

        $this->databases = $this->mergeDeprecatedSnapshotDatabaseKeys(
            $parsed['databases'] ?? [],
            $logger,
        );

        $this->passthrough = array_diff_key($parsed, array_flip(self::DECLARED_KEYS));
    }

    private function schema(): SchemaInterface
    {
        $sb = new SchemaBuilder();

        return $sb->map([
            // Required. These were the twelve `empty()` checks in the old validate().
            'app_name'                              => $sb->string()->notEmpty()->required(),
            'app_root'                              => $sb->string()->notEmpty()->required()
                ->withTransformer(static fn(string $path): string => rtrim($path, '/')),
            'repo_url'                              => $sb->string()->notEmpty()->required(),
            'environment'                           => $sb->string()->notEmpty()->required(),
            'platform'                              => $sb->string()->notEmpty()->default('custom'),
            'default_branch'                        => $sb->string()->notEmpty()->default('master'),
            'default_filesystem'                    => $sb->string()->notEmpty()->default('local'),
            'default_database_adapter'              => $sb->string()->notEmpty()->default('default'),
            'default_database_importexport_adapter' => $sb->string()->notEmpty()->default('default'),
            'default_dir_mode'                      => $sb->fileMode()->default(0750),
            'default_file_mode'                     => $sb->fileMode()->default(0640),

            // The old validate() threw `Invalid file layout "x".` without saying what was valid.
            'file_layout'                           => $sb->enum([
                FileLayoutInterface::STRATEGY_DEFAULT,
                FileLayoutInterface::STRATEGY_BLUE_GREEN,
            ])->default(FileLayoutInterface::STRATEGY_DEFAULT),

            'relative_document_root'                => $sb->string()->nullable()->default('.')
                ->withTransformer(static fn(?string $path): ?string => $path === null ? null : rtrim($path, '/')),
            'shared_path'                           => $sb->string()->notEmpty()->nullable(),

            // Validated but deliberately still ARRAYS, not DTOs. Plan options are merged into these
            // entries at run time with array_replace_recursive() — `conductor app:deploy` can
            // override a database's adapter or excludes from a plan — so a DTO here would have to
            // reimplement recursive merging to gain nothing the schema does not already give. The
            // value is in catching a misspelled key or a string where a list belongs, which this
            // does. See CTAP-1630 notes for the follow-up.
            'databases'                             => $sb->collection($sb->map([
                'adapter'              => $sb->string()->notEmpty()->nullable(),
                'importexport_adapter' => $sb->string()->notEmpty()->nullable(),
                'alias'                => $sb->string()->notEmpty()->nullable(),
                'post_import_scripts'  => $sb->collection($sb->string()->notEmpty())->default([]),
                'excludes'             => $sb->collection($sb->string()->notEmpty())->default([]),
            ]))->default([]),

            'servers'                               => $sb->collection($sb->raw())->default([]),
            'ssh_defaults'                          => $sb->raw()->default([]),
            'source_file_paths'                     => $sb->collection($sb->string()->notEmpty())->default([]),
            'template_vars'                         => $sb->collection($sb->any())->default([]),
            'environment_vars'                      => $sb->collection($sb->string())->default([]),
            'maintenance'                           => $sb->map([
                'shell_adapters'      => $sb->collection($sb->string()->notEmpty())->default(['local']),
                'filesystem_adapters' => $sb->collection($sb->string()->notEmpty())->default(['local']),
            ])->default([]),
        ]);
    }

    /**
     * Fold the deprecated per-snapshot database keys into the top-level `databases` config.
     *
     * `local_database_name`, `adapter` and `importexport_adapter` used to be accepted under
     * `snapshot.databases.<name>`. Warning about them was previously done inside `getDatabases()`,
     * so it fired on every call — and dereferenced a logger the factory never set. It happens once,
     * here, and tolerates having no logger.
     *
     * @param array<string, array<string, mixed>> $databases
     * @return array<string, array<string, mixed>>
     */
    private function mergeDeprecatedSnapshotDatabaseKeys(array $databases, ?LoggerInterface $logger): array
    {
        $deprecated = [
            'local_database_name'  => 'alias',
            'adapter'              => 'adapter',
            'importexport_adapter' => 'importexport_adapter',
        ];

        foreach ($this->snapshotConfig->databases as $name => $snapshotDatabase) {
            if (! is_array($snapshotDatabase)) {
                continue;
            }

            foreach ($deprecated as $oldKey => $newKey) {
                if (! isset($snapshotDatabase[$oldKey])) {
                    continue;
                }

                $logger?->warning(
                    "Use of \"$oldKey\" in snapshot configuration is deprecated. Use top level "
                    . '"databases" configuration instead.'
                );

                $databases[$name][$newKey] ??= $snapshotDatabase[$oldKey];
            }
        }

        return $databases;
    }

    // ------------------------------------------------------------------ simple accessors

    public function getAppName(): string
    {
        return $this->appName;
    }

    public function getAppRoot(): string
    {
        return $this->appRoot;
    }

    public function getPlatform(): string
    {
        return $this->platform;
    }

    public function getRepoUrl(): string
    {
        return $this->repoUrl;
    }

    public function getCurrentEnvironment(): string
    {
        return $this->environment;
    }

    public function getFileLayoutStrategy(): string
    {
        return $this->fileLayout;
    }

    public function getDefaultBranch(): string
    {
        return $this->defaultBranch;
    }

    public function getDefaultFilesystem(): string
    {
        return $this->defaultFilesystem;
    }

    public function getDefaultDatabaseAdapter(): string
    {
        return $this->defaultDatabaseAdapter;
    }

    public function getDefaultDatabaseImportExportAdapter(): string
    {
        return $this->defaultDatabaseImportExportAdapter;
    }

    /** The mode as an int, which is what `mkdir()` and `chmod()` take. Was declared `string`. */
    public function getDefaultDirMode(): int
    {
        return $this->defaultDirMode;
    }

    /** The mode as an int, which is what `chmod()` takes. Was declared `string`. */
    public function getDefaultFileMode(): int
    {
        return $this->defaultFileMode;
    }

    public function getRelativeDocumentRoot(): ?string
    {
        return $this->relativeDocumentRoot;
    }

    /** @return array<string, array<string, mixed>> */
    public function getDatabases(): array
    {
        return $this->databases;
    }

    /** @return array<string, mixed> */
    public function getServers(): array
    {
        return $this->servers;
    }

    /** @return array<string, mixed> */
    public function getSshDefaults(): array
    {
        return $this->sshDefaults;
    }

    /** @return list<string> */
    public function getSourceFilePaths(): array
    {
        return $this->sourceFilePaths;
    }

    /** @return array<string, mixed> */
    public function getTemplateVars(): array
    {
        return $this->templateVars;
    }

    /** @return array<string, string> */
    public function getEnvironmentVars(): array
    {
        return $this->environmentVars;
    }

    /** @return list<string> */
    public function getMaintenanceShellAdapters(): array
    {
        return $this->maintenanceShellAdapters;
    }

    /** @return list<string> */
    public function getMaintenanceFilesystemAdapters(): array
    {
        return $this->maintenanceFilesystemAdapters;
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

    // ------------------------------------------------------------------ derived paths

    public function getDocumentRoot(?string $buildId = null): ?string
    {
        $documentRoot = $this->getCodePath($buildId);

        if ($this->relativeDocumentRoot) {
            $documentRoot .= '/' . $this->relativeDocumentRoot;
        }

        return $documentRoot;
    }

    public function getCodePath(?string $buildId = null): string
    {
        if ($this->fileLayout !== FileLayoutInterface::STRATEGY_BLUE_GREEN) {
            return $this->appRoot;
        }

        return $buildId
            ? $this->appRoot . '/' . FileLayoutInterface::PATH_BUILDS . "/$buildId"
            : $this->appRoot . '/' . FileLayoutInterface::PATH_CURRENT;
    }

    public function getCurrentPath(): string
    {
        return $this->blueGreenPath(FileLayoutInterface::PATH_CURRENT);
    }

    public function getLocalPath(): string
    {
        return $this->blueGreenPath(FileLayoutInterface::PATH_LOCAL);
    }

    public function getPreviousPath(): string
    {
        return $this->blueGreenPath(FileLayoutInterface::PATH_PREVIOUS);
    }

    public function getSharedPath(): string
    {
        return $this->sharedPath ?? $this->blueGreenPath(FileLayoutInterface::PATH_SHARED);
    }

    /** Under blue/green each of these is its own directory; on the default layout they are app root. */
    private function blueGreenPath(string $subdirectory): string
    {
        return $this->fileLayout === FileLayoutInterface::STRATEGY_BLUE_GREEN
            ? $this->appRoot . '/' . $subdirectory
            : $this->appRoot;
    }

    /** @throws Exception\RuntimeException on an unknown path type */
    public function getPath(string $type, ?string $buildId = null): string
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

    /** @throws Exception\RuntimeException when the file is in none of the source file paths */
    public function getSourceFile(string $relativeFilename): string
    {
        foreach ($this->sourceFilePaths as $path) {
            if (file_exists("$path/$relativeFilename")) {
                return "$path/$relativeFilename";
            }
        }

        throw new Exception\RuntimeException(
            "Source file \"$relativeFilename\" not found in configuration."
        );
    }

    // ------------------------------------------------------------------ output

    /**
     * The whole config as a nested array, for `conductor app:config:show`.
     *
     * Reconstructed from the typed properties, so what it prints is what the application actually
     * resolved — defaults applied, modes normalized — rather than the raw input.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'app_name'                              => $this->appName,
            'app_root'                              => $this->appRoot,
            'platform'                              => $this->platform,
            'repo_url'                              => $this->repoUrl,
            'environment'                           => $this->environment,
            'file_layout'                           => $this->fileLayout,
            'default_branch'                        => $this->defaultBranch,
            'default_filesystem'                    => $this->defaultFilesystem,
            'default_database_adapter'              => $this->defaultDatabaseAdapter,
            'default_database_importexport_adapter' => $this->defaultDatabaseImportExportAdapter,
            'default_dir_mode'                      => $this->defaultDirMode,
            'default_file_mode'                     => $this->defaultFileMode,
            'relative_document_root'                => $this->relativeDocumentRoot,
            'shared_path'                           => $this->sharedPath,
            'databases'                             => $this->databases,
            'servers'                               => $this->servers,
            'ssh_defaults'                          => $this->sshDefaults,
            'source_file_paths'                     => $this->sourceFilePaths,
            'template_vars'                         => $this->templateVars,
            'environment_vars'                      => $this->environmentVars,
            'maintenance'                           => [
                'shell_adapters'      => $this->maintenanceShellAdapters,
                'filesystem_adapters' => $this->maintenanceFilesystemAdapters,
            ],
            'build'                                 => $this->buildConfig->toArray(),
            'deploy'                                => $this->deployConfig->toArray(),
            'snapshot'                              => $this->snapshotConfig->toArray(),
            'skeleton'                              => $this->skeletonConfig->toArray(),
        ] + $this->passthrough;
    }
}
