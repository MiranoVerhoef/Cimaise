<?php

declare(strict_types=1);

use App\Services\ContainerMigrationService;
use App\Support\Database;
use Dotenv\Dotenv;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
Dotenv::createImmutable($root)->safeLoad();
// Connect without loading plugins: their code may need the migrated schema.
$env = static fn (string $key, string $default): string => (string) (
    $_ENV[$key] ?? $_SERVER[$key] ?? (getenv($key) !== false ? getenv($key) : $default)
);

$lock = fopen($root . '/storage/tmp/database_migration.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX)) {
    fwrite(STDERR, "[cimaise] cannot lock database migrations\n");
    exit(1);
}
try {
    if ($env('DB_CONNECTION', 'sqlite') === 'sqlite') {
        $path = $env('DB_DATABASE', 'database/database.sqlite');
        $path = str_starts_with($path, '/') ? $path : $root . '/' . $path;
        // A partial/fresh install must still be able to reach the installer.
        if (!is_file($path)) {
            exit(0);
        }
        $db = new Database(database: $path, isSqlite: true);
        $installed = $db->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'settings'")->fetchColumn();
    } else {
        $db = new Database(
            host: $env('DB_HOST', '127.0.0.1'), port: (int) $env('DB_PORT', '3306'),
            database: $env('DB_DATABASE', 'cimaise'), username: $env('DB_USERNAME', 'root'),
            password: $env('DB_PASSWORD', ''), charset: $env('DB_CHARSET', 'utf8mb4'),
            collation: $env('DB_COLLATION', 'utf8mb4_unicode_ci'),
        );
        $installed = $db->query("SHOW TABLES LIKE 'settings'")->fetchColumn();
    }
    if ($installed === false) {
        exit(0);
    }
    $version = json_decode((string) file_get_contents($root . '/version.json'), true);
    $service = new ContainerMigrationService($db, $argv[1] ?? '/usr/share/cimaise/migrations');
    $executed = $service->migrate((string) ($version['version'] ?? ''));
    fwrite(STDOUT, '[cimaise] database ready; migrations applied: ' . count($executed) . "\n");
} catch (Throwable $e) {
    fwrite(STDERR, '[cimaise] ' . $e->getMessage() . "\n");
    exit(1);
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}
