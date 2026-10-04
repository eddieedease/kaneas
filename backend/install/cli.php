<?php

/**
 * Kaneas CLI installer — used for the automatic development install.
 *
 *   php install/cli.php --from-env
 *
 * Reads KANEAS_DEV_* environment variables (see docker-compose.yml), waits for the
 * database, and installs when the tables don't exist yet. When the database was wiped
 * (docker compose down -v) a stale config.php is replaced. Safe to run on every start.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../api/src/bootstrap.php';
require __DIR__ . '/Installer.php';

use Kaneas\Core\Database;
use Kaneas\Install\InstallException;
use Kaneas\Install\Installer;

$env = static function (string $name, ?string $default = null): string {
    $value = getenv('KANEAS_DEV_' . $name);
    if ($value === false || $value === '') {
        if ($default === null) {
            fwrite(STDERR, "[kaneas] Missing environment variable KANEAS_DEV_$name\n");
            exit(1);
        }
        return $default;
    }
    return $value;
};

if (!in_array('--from-env', $argv, true)) {
    fwrite(STDOUT, "Usage: php install/cli.php --from-env\n");
    exit(1);
}

$db = [
    'host' => $env('DB_HOST'),
    'port' => (int) $env('DB_PORT', '3306'),
    'name' => $env('DB_NAME'),
    'user' => $env('DB_USER'),
    'pass' => $env('DB_PASS'),
    'prefix' => $env('DB_PREFIX', 'kb_'),
];

// Wait for MySQL (it may still be starting).
$pdo = null;
for ($attempt = 1; $attempt <= 30; $attempt++) {
    try {
        $pdo = Database::connect($db);
        break;
    } catch (PDOException $e) {
        fwrite(STDOUT, "[kaneas] Waiting for database ($attempt/30)...\n");
        sleep(2);
    }
}
if ($pdo === null) {
    fwrite(STDERR, "[kaneas] Database not reachable, skipping auto install.\n");
    exit(1);
}

$installer = new Installer(dirname(__DIR__));

if (Installer::tablesExist($pdo, $db['prefix'])) {
    if (!is_file($installer->configPath())) {
        fwrite(STDERR, "[kaneas] Tables exist but config.php is missing. Run `docker compose down -v` to start fresh.\n");
        exit(1);
    }
    fwrite(STDOUT, "[kaneas] Already installed.\n");
    exit(0);
}

if (is_file($installer->configPath())) {
    fwrite(STDOUT, "[kaneas] Database is empty, replacing stale config.php.\n");
    unlink($installer->configPath());
}

$users = [];
if ($env('USER_EMAIL', '-') !== '-') {
    $users[] = [
        'name' => $env('USER_NAME', 'Dev User'),
        'email' => $env('USER_EMAIL'),
        'password' => $env('USER_PASSWORD'),
        'role' => 'user',
        'locale' => 'nl',
    ];
}

try {
    $installer->install([
        'db' => $db,
        'admin' => [
            'name' => $env('ADMIN_NAME', 'Dev Admin'),
            'email' => $env('ADMIN_EMAIL'),
            'password' => $env('ADMIN_PASSWORD'),
            'locale' => 'nl',
        ],
        'users' => $users,
        'allow_registration' => true,
        'app_url' => $env('APP_URL', 'http://localhost:4200/'),
        'base_path' => '/',
        'debug' => true,
    ]);
} catch (InstallException $e) {
    fwrite(STDERR, "[kaneas] Auto install failed ({$e->reason}): {$e->getMessage()}\n");
    exit(1);
}

// The CLI runs as root in Docker; Apache must be able to read the config.
if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
    @chown($installer->configPath(), 'www-data');
    @chgrp($installer->configPath(), 'www-data');
}

fwrite(STDOUT, "[kaneas] Installed. Admin: {$env('ADMIN_EMAIL')}" . ($users ? ", user: {$users[0]['email']}" : '') . "\n");
