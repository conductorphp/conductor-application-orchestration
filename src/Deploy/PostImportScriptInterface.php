<?php

namespace ConductorAppOrchestration\Deploy;

use ConductorCore\Database\DatabaseAdapterInterface;
use Psr\Log\LoggerInterface;

/**
 * Interface for post-import database scripts
 *
 * Scripts that implement this interface can be executed after database import
 * and have access to database connection, logger, and application configuration.
 */
interface PostImportScriptInterface
{
    /**
     * Execute the post-import script and return SQL to be executed
     *
     * @param DatabaseAdapterInterface $databaseAdapter The database adapter
     * @param string $databaseName The database name being deployed
     * @param array $config Application configuration array
     * @param LoggerInterface $logger Logger for output
     * @return string SQL statements to execute (or empty string to skip)
     */
    public function execute(
        DatabaseAdapterInterface $databaseAdapter,
        string $databaseName,
        array $config,
        LoggerInterface $logger
    ): string;
}
