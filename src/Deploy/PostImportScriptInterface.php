<?php

declare(strict_types=1);

namespace ConductorAppOrchestration\Deploy;

use ConductorAppOrchestration\Config\ApplicationConfig;
use ConductorCore\Database\DatabaseAdapterInterface;
use Psr\Log\LoggerInterface;

/**
 * A script run against a database after a snapshot is imported, returning SQL to execute.
 *
 * ## The config parameter is now typed (CTAP-1630)
 *
 * It used to be `array $config`. `ApplicationDatabaseDeployer` holds a fully built
 * {@see ApplicationConfig} and had to call `toArray()` on it purely to satisfy this signature, after
 * which every script re-derived the types it needed by hand — `$config['current_environment'] ?? null`
 * guarded by `is_string()`, and so on. The object is passed through intact instead.
 *
 * Note the environment is `$config->environment`, not `$config['current_environment']`: the array
 * form carried the environment under a different key than the config file uses, which was invisible
 * while everything was arrays.
 */
interface PostImportScriptInterface
{
    /**
     * @param DatabaseAdapterInterface $databaseAdapter Adapter for the database being deployed.
     * @param string                   $databaseName    Name of the database being deployed.
     * @param ApplicationConfig        $config          The application's validated configuration.
     * @param LoggerInterface          $logger          Logger for progress and skip reasons.
     * @return string SQL statements to execute, or an empty string to skip.
     */
    public function execute(
        DatabaseAdapterInterface $databaseAdapter,
        string $databaseName,
        ApplicationConfig $config,
        LoggerInterface $logger
    ): string;
}
