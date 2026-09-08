<?php

declare(strict_types=1);

namespace ConductorAppOrchestration\Config;

use ConductorCore\Config\ParsesConfigTrait;
use ConductorCore\Config\Schema\SchemaBuilder;
use ConductorCore\Config\Schema\SchemaInterface;

/**
 * The `skeleton` section: the directories, files and symlinks the skeleton deployer creates.
 *
 * Entry interiors are raw arrays. `ApplicationSkeletonDeployer` reads their keys directly and this
 * object never looks inside, so a schema here would describe a shape for no reader — see
 * {@see \ConductorCore\Config\Schema\Node\RawNode}. What the schema does assert is that each section
 * is a map of names to arrays, which is what every consumer assumes and nothing checked before.
 */
final readonly class SkeletonConfig
{
    use ParsesConfigTrait;

    public const CONFIG_KEY = 'application_orchestration.application.skeleton';

    /** @var array<string, mixed> */
    public array $directories;

    /** @var array<string, mixed> */
    public array $files;

    /** @var array<string, mixed> */
    public array $symlinks;

    /** @param array<string, mixed>|null $config */
    public function __construct(?array $config)
    {
        $parsed = $this->parseConfig($config, $this->schema(), self::CONFIG_KEY);

        $this->directories = $parsed['directories'] ?? [];
        $this->files       = $parsed['files'] ?? [];
        $this->symlinks    = $parsed['symlinks'] ?? [];
    }

    private function schema(): SchemaInterface
    {
        $sb = new SchemaBuilder();

        return $sb->map([
            'directories' => $sb->collection($sb->raw())->default([]),
            'files'       => $sb->collection($sb->raw())->default([]),
            'symlinks'    => $sb->collection($sb->raw())->default([]),
        ]);
    }

    /** @return array<string, mixed> */
    public function getDirectories(): array
    {
        return $this->directories;
    }

    /** @return array<string, mixed> */
    public function getFiles(): array
    {
        return $this->files;
    }

    /** @return array<string, mixed> */
    public function getSymlinks(): array
    {
        return $this->symlinks;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'directories' => $this->directories,
            'files'       => $this->files,
            'symlinks'    => $this->symlinks,
        ];
    }
}
