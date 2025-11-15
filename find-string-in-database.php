#!/usr/bin/env php
<?php
/**
 * Find all tables and columns containing a specific string
 *
 * Usage: php find-string-in-database.php "https://www.crispiusa.com"
 */

if ($argc < 2) {
    echo "Usage: php find-string-in-database.php \"search-string\" [database-name]\n";
    exit(1);
}

$searchString = $argv[1];
$databaseName = $argv[2] ?? 'crispipwa_magento';

echo "Searching for: {$searchString}\n";
echo "Database: {$databaseName}\n\n";

// Database connection
$host = getenv('DB_HOST') ?: 'magento-mysql';
$port = getenv('DB_PORT') ?: 3306;
$username = getenv('DB_USER') ?: 'root';
$password = getenv('DB_PASS') ?: 'password';

try {
    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$databaseName};charset=utf8mb4",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (PDOException $e) {
    echo "Error connecting to database: " . $e->getMessage() . "\n";
    exit(1);
}

// Get all text-based columns
$stmt = $pdo->prepare("
    SELECT
        TABLE_NAME,
        COLUMN_NAME,
        DATA_TYPE
    FROM
        INFORMATION_SCHEMA.COLUMNS
    WHERE
        TABLE_SCHEMA = ?
        AND DATA_TYPE IN ('varchar', 'text', 'mediumtext', 'longtext', 'char', 'json')
    ORDER BY
        TABLE_NAME, COLUMN_NAME
");

$stmt->execute([$databaseName]);
$columns = $stmt->fetchAll();

echo "Found " . count($columns) . " searchable columns\n";
echo "Searching (this may take a while)...\n\n";

$results = [];
$tablesSearched = 0;
$columnsSearched = 0;

foreach ($columns as $column) {
    $tableName = $column['TABLE_NAME'];
    $columnName = $column['COLUMN_NAME'];

    $columnsSearched++;

    // Show progress
    if ($columnsSearched % 50 === 0) {
        echo "Progress: {$columnsSearched}/" . count($columns) . " columns searched\r";
    }

    try {
        // Search for the string in this column
        $searchStmt = $pdo->prepare("
            SELECT COUNT(*) as count
            FROM `{$tableName}`
            WHERE `{$columnName}` LIKE ?
        ");

        $searchStmt->execute(['%' . $searchString . '%']);
        $result = $searchStmt->fetch();

        if ($result['count'] > 0) {
            $results[] = [
                'table' => $tableName,
                'column' => $columnName,
                'count' => $result['count'],
                'data_type' => $column['DATA_TYPE'],
            ];
        }
    } catch (PDOException $e) {
        // Skip columns that can't be searched (e.g., binary)
        continue;
    }
}

echo "\n\n";
echo "=== RESULTS ===\n\n";

if (empty($results)) {
    echo "No matches found.\n";
} else {
    echo "Found matches in " . count($results) . " table/column combinations:\n\n";

    // Group by table
    $byTable = [];
    foreach ($results as $result) {
        $byTable[$result['table']][] = $result;
    }

    foreach ($byTable as $tableName => $columns) {
        echo "Table: {$tableName}\n";
        foreach ($columns as $col) {
            echo "  - {$col['column']} ({$col['data_type']}): {$col['count']} row(s)\n";
        }
        echo "\n";
    }

    echo "\n=== YAML CONFIG FORMAT ===\n\n";
    echo "replacements:\n";
    foreach ($byTable as $tableName => $columns) {
        echo "  {$tableName}:\n";
        foreach ($columns as $col) {
            if (!isset($printed[$tableName][$col['column']])) {
                echo "    {$col['column']}:\n";
                echo "      base_url:\n";
                echo "        from: '{$searchString}'\n";
                echo "        to: 'https://\${FRONTEND_DOMAIN}'\n";
                $printed[$tableName][$col['column']] = true;
            }
        }
    }
}

echo "\nSearch complete!\n";
echo "Searched {$columnsSearched} columns in {$databaseName}\n";
