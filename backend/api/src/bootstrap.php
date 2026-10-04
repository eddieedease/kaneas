<?php

declare(strict_types=1);

const KANEAS_VERSION = '0.1.0';
const KANEAS_SCHEMA_VERSION = 1;
const KANEAS_MIN_PHP = '8.2.0';

spl_autoload_register(static function (string $class): void {
    $prefix = 'Kaneas\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
