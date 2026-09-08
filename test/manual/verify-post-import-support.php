<?php

/**
 * CTAP-1628 — manual verification for PostImportSupport against a LIVE database.
 *
 * Not a PHPUnit test, and deliberately not named *Test.php so no suite collects it: it needs a
 * reachable MySQL/MariaDB with rights to create a database, which CI here does not have.
 *
 * It exists because the interesting half of PostImportSupport cannot be meaningfully unit-tested.
 * The class picks its WHERE predicate by reading the live snapshot's schema, so a mocked
 * information_schema would assert only that the mock was configured — and the SQL it builds
 * (JSON_SET / JSON_REMOVE over scoped attribute bags) is either valid on the server or it is not.
 * So this builds both snapshot shapes for real, runs the generated SQL through the real adapter,
 * and asserts the resulting rows. Run it against every server version the package claims to
 * support before releasing a change to PostImportSupport.
 *
 * Covered here and nowhere else:
 *   - pre-promotion snapshot addressed by the `attributes_global` JSON natural key
 *   - post-promotion snapshot addressed by the promoted column
 *   - a null attribute value REMOVING the key rather than writing a JSON null
 *   - per-scope clearing discovering its website/view codes from the data, siblings preserved
 *   - JSON_SET on a plain JSON column preserving unrelated keys
 *   - the absent-row / absent-table / missing-column skip paths logging and emitting nothing
 *
 * The DB-free surface (statement collection, config accessors, PostImportScript wiring) is
 * covered by test/Deploy/PostImportScriptTest.php in the normal suite.
 *
 * Usage — from a container that can reach the database, with an autoloader that has this package:
 *
 *   php test/manual/verify-post-import-support.php [host] [user] [password]
 *
 * It finds an autoloader itself in the usual layouts (installed standalone, installed as a
 * dependency, or the conductor monorepo's tools/lib-tests harness). Set CONDUCTOR_AUTOLOAD to
 * override that.
 *
 * Defaults: host `127.0.0.1`, user `root`, password `root`. Exits 0 when every assertion passes,
 * 1 otherwise. It creates and DROPS the database `ctap1628_pis_test` on the target server.
 *
 * Requires conductor/mysql-database-support for a concrete DatabaseAdapter to run the generated
 * SQL through. That package is not a dependency of this one, which is fine for a manual script
 * but is why this cannot become part of the normal suite as written.
 *
 * Verified passing on PHP 8.4.22 and 8.5.7 against MySQL 8.0.46 and MariaDB 10.6.27.
 */

declare(strict_types=1);

use ConductorAppOrchestration\Deploy\PostImportScript;
use ConductorAppOrchestration\Deploy\PostImportSupport;
use ConductorMySqlSupport\Adapter\DatabaseAdapter;
use Psr\Log\AbstractLogger;

$autoloaders = array_filter([
    getenv('CONDUCTOR_AUTOLOAD') ?: null,
    __DIR__ . '/../../vendor/autoload.php',                                 // package installed standalone
    __DIR__ . '/../../../../autoload.php',                                  // installed as a dependency
    __DIR__ . '/../../../../tools/lib-tests/vendor-84/autoload.php',        // conductor monorepo, PHP 8.4
    __DIR__ . '/../../../../tools/lib-tests/vendor-85/autoload.php',        // conductor monorepo, PHP 8.5
]);
foreach ($autoloaders as $autoloader) {
    if (file_exists($autoloader)) {
        require $autoloader;
        break;
    }
}
if (! class_exists(PostImportSupport::class)) {
    fwrite(STDERR, 'No autoloader found that provides ' . PostImportSupport::class . ".\n"
        . "Point CONDUCTOR_AUTOLOAD at one:\n"
        . '  CONDUCTOR_AUTOLOAD=/path/to/vendor/autoload.php php ' . $argv[0] . " <host> [user] [password]\n");
    exit(2);
}

error_reporting(E_ALL);
$phpWarnings = [];
set_error_handler(static function (int $no, string $msg) use (&$phpWarnings): bool {
    $phpWarnings[] = $msg;
    return true;
});

final class CollectingLogger extends AbstractLogger
{
    /** @var list<string> */
    public array $lines = [];

    public function log($level, $message, array $context = []): void
    {
        $this->lines[] = strtoupper((string) $level) . ': ' . $message;
    }
}

$host     = $argv[1] ?? '127.0.0.1';
$user     = $argv[2] ?? 'root';
$password = $argv[3] ?? 'root';
$db       = 'ctap1628_pis_test';

$pdo = new PDO("mysql:host=$host;port=3306", $user, $password, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    // 8.5 deprecated the PDO::MYSQL_* aliases; use the driver class where it exists.
    (PHP_VERSION_ID >= 80500 ? \Pdo\Mysql::ATTR_USE_BUFFERED_QUERY : PDO::MYSQL_ATTR_USE_BUFFERED_QUERY) => true,
]);

echo "================================================================\n";
echo "server: " . $pdo->getAttribute(PDO::ATTR_SERVER_VERSION) . "  (host $host)\n";
echo "================================================================\n\n";

$pdo->exec("DROP DATABASE IF EXISTS `$db`");
$pdo->exec("CREATE DATABASE `$db`");
$pdo->exec("USE `$db`");

// PRE-promotion snapshot: the natural key still lives in the attributes_global JSON bag.
$pdo->exec("CREATE TABLE `system_setting` (
    id INT AUTO_INCREMENT PRIMARY KEY,
    attributes_global JSON NULL,
    attributes_website JSON NULL,
    attributes_view JSON NULL
)");

// POST-promotion snapshot: the same key has been promoted to a real column.
$pdo->exec("CREATE TABLE `payment_method` (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(64) NOT NULL,
    attributes_global JSON NULL,
    attributes_website JSON NULL,
    attributes_view JSON NULL
)");

// A plain JSON column (unscoped) — the connector-endpoint shape.
$pdo->exec("CREATE TABLE `connector` (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(64) NOT NULL,
    settings JSON NULL
)");

$pdo->exec("INSERT INTO `system_setting` (attributes_global, attributes_website, attributes_view) VALUES
    (JSON_OBJECT('path', 'area/frontend/cookie_domain', 'value', 'www.production.example'), NULL, NULL)");

// The AUTHORIZE_NET E00007 shape: global holds the corrected credential, and stale
// website AND view copies shadow it. `title` is an unrelated sibling that must survive.
$pdo->exec("INSERT INTO `payment_method` (code, attributes_global, attributes_website, attributes_view) VALUES
    ('AUTHORIZE_NET',
     JSON_OBJECT('login_id', 'PROD_LOGIN', 'is_enabled', true),
     JSON_OBJECT('base',    JSON_OBJECT('login_id', 'STALE_WEBSITE_LOGIN', 'title', 'Credit Card')),
     JSON_OBJECT('default', JSON_OBJECT('login_id', 'STALE_VIEW_LOGIN',    'title', 'Card', 'is_enabled', false)))");

$pdo->exec("INSERT INTO `connector` (code, settings) VALUES
    ('magento', JSON_OBJECT('endpoint', 'https://prod.example/api', 'oauth', JSON_OBJECT('key', 'KEEPME')))");

$config = [
    'current_environment' => 'qa',
    'environment_vars'    => ['BASE_URL' => 'https://qa.example'],
    'database'            => ['adapters' => ['default' => ['arguments' => [
        'host'     => $host,
        'port'     => 3306,
        'username' => $user,
        'password' => $password,
    ]]]],
];

$logger  = new CollectingLogger();
$adapter = new DatabaseAdapter($user, $password, $host, 3306);

$script = new PostImportScript(
    naturalKeys: [
        'system_setting' => 'path',
        'payment_method' => 'code',
        'connector'      => 'code',
        'absent_table'   => 'code',
    ],
    apply: static function (PostImportSupport $support): void {
        // 1. PRE-promotion table, addressed by its JSON natural key.
        $support->setGlobalAttributes('system_setting', 'area/frontend/cookie_domain', [
            'value' => 'qa.example',
        ]);

        // 2. POST-promotion table, addressed by the promoted column. A null REMOVES.
        $support->setGlobalAttributes('payment_method', 'AUTHORIZE_NET', [
            'login_id'   => 'QA_LOGIN',
            'is_enabled' => null,
        ]);

        // 3. Per-scope clearing: discovers 'base' / 'default' from the data, keeps `title`.
        $support->clearScopedAttribute('payment_method', 'AUTHORIZE_NET', 'login_id');

        // 4. Plain JSON column: JSON_SET must preserve the sibling oauth.key.
        $support->setJsonKeys('connector', 'magento', 'settings', [
            'endpoint' => 'https://qa.example/api',
        ]);

        // 5. Skip paths — each must log and emit nothing.
        $support->setGlobalAttributes('payment_method', 'NO_SUCH_METHOD', ['value' => 'x']); // absent row
        $support->setGlobalAttributes('absent_table', 'anything', ['value' => 'x']);         // absent table
        $support->setGlobalAttributes('connector', 'magento', ['value' => 'x']);             // ok, has attributes_global? no
    },
);

echo "--- predicate selection -------------------------------------------\n";
$support = new PostImportSupport($adapter, $db, $config, $logger);
$support->withNaturalKeys(['system_setting' => 'path', 'payment_method' => 'code']);
echo "pre-promotion  (system_setting): " . $support->keyPredicate('system_setting', 'area/frontend/cookie_domain') . "\n";
echo "post-promotion (payment_method): " . $support->keyPredicate('payment_method', 'AUTHORIZE_NET') . "\n";
echo "hasTable(system_setting)=" . var_export($support->hasTable('system_setting'), true)
    . " hasTable(nope)=" . var_export($support->hasTable('nope'), true)
    . " hasColumn(payment_method,code)=" . var_export($support->hasColumn('payment_method', 'code'), true) . "\n\n";

echo "--- generated SQL -------------------------------------------------\n";
$sql = $script->execute($adapter, $db, $config, $logger);
echo $sql . "\n\n";

echo "--- log ------------------------------------------------------------\n";
foreach ($logger->lines as $line) {
    echo "  $line\n";
}
echo "\n";

echo "--- EXECUTING the generated SQL through the real adapter ----------\n";
$adapter->run($sql, $db);
echo "executed without error\n\n";

echo "--- resulting rows -------------------------------------------------\n";
foreach (['system_setting' => 'attributes_global', 'payment_method' => 'attributes_global'] as $t => $c) {
    foreach ($pdo->query("SELECT * FROM `$t`")->fetchAll(PDO::FETCH_ASSOC) as $row) {
        echo "$t: " . json_encode($row) . "\n";
    }
}
foreach ($pdo->query("SELECT * FROM `connector`")->fetchAll(PDO::FETCH_ASSOC) as $row) {
    echo "connector: " . json_encode($row) . "\n";
}
echo "\n";

echo "--- assertions -----------------------------------------------------\n";
$fail = 0;
$check = static function (string $label, bool $ok) use (&$fail): void {
    echo ($ok ? '  PASS  ' : '  FAIL  ') . $label . "\n";
    if (! $ok) {
        $fail++;
    }
};

$ss = $pdo->query("SELECT attributes_global FROM system_setting")->fetchColumn();
$ssD = json_decode((string) $ss, true);
$check('pre-promotion row updated via JSON key predicate', ($ssD['value'] ?? null) === 'qa.example');
$check('pre-promotion natural key preserved', ($ssD['path'] ?? null) === 'area/frontend/cookie_domain');

$pm  = $pdo->query("SELECT attributes_global, attributes_website, attributes_view FROM payment_method")->fetch(PDO::FETCH_ASSOC);
$g   = json_decode((string) $pm['attributes_global'], true);
$w   = json_decode((string) $pm['attributes_website'], true);
$v   = json_decode((string) $pm['attributes_view'], true);
$check('global login_id set via promoted column predicate', ($g['login_id'] ?? null) === 'QA_LOGIN');
$check('null value REMOVED is_enabled (not JSON null)', ! array_key_exists('is_enabled', $g));
$check('stale WEBSITE login_id override removed', ! array_key_exists('login_id', $w['base'] ?? []));
$check('stale VIEW login_id override removed', ! array_key_exists('login_id', $v['default'] ?? []));
$check('website sibling `title` survived', ($w['base']['title'] ?? null) === 'Credit Card');
$check('view sibling `title` survived', ($v['default']['title'] ?? null) === 'Card');
$check('view sibling `is_enabled` survived', array_key_exists('is_enabled', $v['default'] ?? []));

$cn  = json_decode((string) $pdo->query("SELECT settings FROM connector")->fetchColumn(), true);
$check('plain JSON column endpoint set', ($cn['endpoint'] ?? null) === 'https://qa.example/api');
$check('plain JSON column sibling oauth.key preserved', ($cn['oauth']['key'] ?? null) === 'KEEPME');

$log = implode("\n", $logger->lines);
$check('absent row logged a skip', str_contains($log, 'no row for "NO_SUCH_METHOD"'));
$check('absent table logged a skip', str_contains($log, '`absent_table` is not in the snapshot'));
$check('per-scope clear was logged', str_contains($log, 'dropping `payment_method`.login_id'));
$check('no SQL comments emitted', ! str_contains($sql, '--') && ! str_contains($sql, '/*'));
$check('no PHP warnings/notices raised', $phpWarnings === []);
foreach ($phpWarnings as $wn) {
    echo "    ! $wn\n";
}

$pdo->exec("DROP DATABASE `$db`");

echo "\n" . ($fail === 0 ? "ALL ASSERTIONS PASSED" : "$fail ASSERTION(S) FAILED") . "\n";
exit($fail === 0 ? 0 : 1);
