<?php

declare(strict_types=1);

namespace ConductorAppOrchestration\Config;

use ConductorCore\Config\ParsesConfigTrait;
use ConductorCore\Config\Schema\SchemaBuilder;
use ConductorCore\Config\Schema\SchemaInterface;

use function array_map;
use function array_values;
use function explode;

/**
 * The `deploy` section: plans, and the per-database find-and-replace rules applied to a restored
 * snapshot.
 *
 * `plans` is left as a raw array. Its interior is consumed by {@see \ConductorAppOrchestration\PlanRunner},
 * not by this object, so describing it here would be a schema written for a reader that does not
 * exist — see {@see \ConductorCore\Config\Schema\Node\RawNode}. Typing it belongs with whatever
 * change makes PlanRunner read a typed plan.
 *
 * `databases.<name>.replacements` IS described, because
 * {@see \ConductorAppOrchestration\Deploy\DatabaseReplacementScript} used to validate it by hand —
 * roughly forty lines of `is_array()` / `isset()` / `count(explode(...))` checks, each arm logging a
 * warning and skipping that one entry. A schema states the same rules once, reports every problem at
 * once, and hands over typed {@see ReplacementConfig} objects.
 */
final readonly class DeployConfig
{
    use ParsesConfigTrait;

    public const CONFIG_KEY = 'application_orchestration.application.deploy';

    /** @var array<string, mixed> */
    public array $plans;

    public string $defaultPlan;

    /** @var array<string, list<ReplacementConfig>> database name => its replacements */
    public array $replacements;

    /** @param array<string, mixed>|null $config */
    public function __construct(?array $config)
    {
        $parsed = $this->parseConfig($config, $this->schema(), self::CONFIG_KEY);

        $this->plans       = $parsed['plans'] ?? [];
        $this->defaultPlan = $parsed['default_plan'] ?? 'default';

        $replacements = [];
        foreach ($parsed['databases'] ?? [] as $databaseName => $database) {
            $replacements[$databaseName] = array_values($database['replacements'] ?? []);
        }
        $this->replacements = $replacements;
    }

    private function schema(): SchemaInterface
    {
        $sb = new SchemaBuilder();

        // A target is written `table.column`. Extra dot-separated parts are ignored, as before, so a
        // qualified `schema.table.column` is not an error — but a target with no column now IS one,
        // reported with every other config problem rather than warned about at generation time.
        $target = $sb->string()->notEmpty()
            ->assert(static function (string $value): ?string {
                $parts = explode('.', $value);

                return isset($parts[1]) && $parts[0] !== '' && $parts[1] !== ''
                    ? null
                    : "must be 'table.column', got '$value'";
            })
            ->withTransformer(static function (string $value): ReplacementTarget {
                $parts = explode('.', $value);

                return new ReplacementTarget($parts[0], $parts[1]);
            });

        $replacement = $sb->map([
            'from'    => $sb->string()->notEmpty()->required(),
            // Optional: no `to` means the replacement is disabled, not misconfigured.
            'to'      => $sb->string()->nullable(),
            'regex'   => $sb->bool()->default(false),
            'targets' => $sb->collection($target)->required(),
        ]);

        return $sb->map([
            'plans'        => $sb->collection($sb->raw())->default([]),
            'default_plan' => $sb->string()->notEmpty()->default('default'),
            'databases'    => $sb->collection($sb->map([
                'replacements' => $sb->collection($replacement)->default([])->withTransformer(
                    static function (array $replacements): array {
                        $configs = [];
                        foreach ($replacements as $name => $replacement) {
                            $configs[] = new ReplacementConfig(
                                (string) $name,
                                $replacement['from'],
                                $replacement['to'] ?? null,
                                $replacement['regex'],
                                array_values($replacement['targets']),
                            );
                        }

                        return $configs;
                    }
                ),
            ]))->default([]),
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

    /** @return list<ReplacementConfig> */
    public function getReplacements(string $databaseName): array
    {
        return $this->replacements[$databaseName] ?? [];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $databases = [];
        foreach ($this->replacements as $databaseName => $replacements) {
            foreach ($replacements as $replacement) {
                $databases[$databaseName]['replacements'][$replacement->name] = [
                    'from'    => $replacement->from,
                    'to'      => $replacement->to,
                    'regex'   => $replacement->regex,
                    'targets' => array_map('strval', $replacement->targets),
                ];
            }
        }

        return [
            'plans'        => $this->plans,
            'default_plan' => $this->defaultPlan,
            'databases'    => $databases,
        ];
    }
}
