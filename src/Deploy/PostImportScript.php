<?php

declare(strict_types=1);

namespace ConductorAppOrchestration\Deploy;

use ConductorCore\Database\DatabaseAdapterInterface;
use Closure;
use Psr\Log\LoggerInterface;

/**
 * A post-import script written against {@see PostImportSupport}.
 *
 * A consumer declares two things — which tables it addresses, and what to change — and never
 * assembles SQL text, opens a connection, or touches the adapter. This class owns the wiring:
 * it builds the support object, registers the natural keys, runs the callable, and hands the
 * deployer the collected SQL — an empty string when nothing applied, which the deployer already
 * logs as a clean skip.
 *
 * The script is COMPOSED from a callable rather than extended from a base class. There is no
 * behavior for a consumer to override — the wiring is the same for every script — so inheritance
 * would buy nothing and cost the consumer a class declaration and two method signatures.
 *
 * ```php
 * // config/app/environments/qa/files/environment-settings.php
 * declare(strict_types=1);
 *
 * use ConductorAppOrchestration\Deploy\PostImportScript;
 * use ConductorAppOrchestration\Deploy\PostImportSupport;
 *
 * const DOMAIN = 'example.qa.test';
 *
 * return new PostImportScript(
 *     naturalKeys: ['system_setting' => 'path'],
 *     apply: static function (PostImportSupport $support): void {
 *         $support->setGlobalAttributes('system_setting', 'area/frontend/cookie_domain', [
 *             'value' => DOMAIN,
 *         ]);
 *     },
 * );
 * ```
 *
 * `$apply` is any callable, so a script that wants private helpers can pass an invokable object
 * instead of a closure and keep them together — still without extending anything:
 *
 * ```php
 * return new PostImportScript(['system_setting' => 'path'], new class {
 *     public function __invoke(PostImportSupport $support): void
 *     {
 *         $this->correctCookieDomains($support);
 *     }
 *
 *     private function correctCookieDomains(PostImportSupport $support): void
 *     {
 *         // ...
 *     }
 * });
 * ```
 */
final class PostImportScript implements PostImportScriptInterface
{
    /** @var Closure(PostImportSupport): void */
    private readonly Closure $apply;

    /**
     * @param array<string, string> $naturalKeys Natural key column per table this script addresses,
     *     e.g. `['system_setting' => 'path']`. The name is both the promoted column and the
     *     pre-promotion `attributes_global` JSON key — {@see PostImportSupport::keyPredicate()}
     *     picks whichever the snapshot actually has.
     * @param callable(PostImportSupport): void $apply What to change, described through the support
     *     object. It returns nothing: the statements are collected on the support object, not
     *     assembled by the caller.
     */
    public function __construct(
        private readonly array $naturalKeys,
        callable $apply,
    ) {
        // Normalized to a Closure because a property cannot be typed `callable`, while the parameter
        // stays `callable` so a consumer may pass an invokable object or [$object, 'method'].
        $this->apply = $apply instanceof Closure ? $apply : $apply(...);
    }

    /** @param array<string, mixed> $config */
    public function execute(
        DatabaseAdapterInterface $databaseAdapter,
        string $databaseName,
        array $config,
        LoggerInterface $logger,
    ): string {
        $support = new PostImportSupport($databaseAdapter, $databaseName, $config, $logger);
        $support->withNaturalKeys($this->naturalKeys);

        ($this->apply)($support);

        return $support->toSql();
    }
}
