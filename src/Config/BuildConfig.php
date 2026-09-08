<?php

declare(strict_types=1);

namespace ConductorAppOrchestration\Config;

use ConductorCore\Config\ParsesConfigTrait;
use ConductorCore\Config\Schema\SchemaBuilder;
use ConductorCore\Config\Schema\SchemaInterface;

/**
 * The `build` section: which plans exist and which one runs by default.
 *
 * `plans` is left as a raw array. Its interior is consumed by {@see \ConductorAppOrchestration\PlanRunner},
 * not by this object, so describing it here would be a schema written for a reader that does not
 * exist — see {@see \ConductorCore\Config\Schema\Node\RawNode}. Typing it belongs with whatever
 * change makes PlanRunner read a typed plan.
 */
final readonly class BuildConfig
{
    use ParsesConfigTrait;

    public const CONFIG_KEY = 'application_orchestration.application.build';

    /** @var array<string, mixed> */
    public array $plans;

    public string $defaultPlan;

    /** @param array<string, mixed>|null $config */
    public function __construct(?array $config)
    {
        $parsed = $this->parseConfig($config, $this->schema(), self::CONFIG_KEY);

        $this->plans       = $parsed['plans'] ?? [];
        $this->defaultPlan = $parsed['default_plan'] ?? 'default';
    }

    private function schema(): SchemaInterface
    {
        $sb = new SchemaBuilder();

        return $sb->map([
            'plans'        => $sb->collection($sb->raw())->default([]),
            'default_plan' => $sb->string()->notEmpty()->default('default'),
        ]);
    }

    /** @return array<string, mixed> */
    public function getPlans(): array
    {
        return $this->plans;
    }

    public function getDefaultPlan(): string
    {
        return $this->defaultPlan;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'plans'        => $this->plans,
            'default_plan' => $this->defaultPlan,
        ];
    }
}
