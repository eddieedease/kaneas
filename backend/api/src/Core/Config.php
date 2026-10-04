<?php

declare(strict_types=1);

namespace Kaneas\Core;

/**
 * Reads the config file written by the web installer (api/config/config.php).
 */
final class Config
{
    private static ?array $data = null;

    public static function path(): string
    {
        return dirname(__DIR__, 2) . '/config/config.php';
    }

    public static function isInstalled(): bool
    {
        return is_file(self::path());
    }

    public static function load(): void
    {
        $data = require self::path();
        if (!is_array($data)) {
            throw new \RuntimeException('Invalid configuration file.');
        }
        self::$data = $data;
    }

    public static function isLoaded(): bool
    {
        return self::$data !== null;
    }

    /** Dot-notation lookup, e.g. Config::get('jwt.secret'). */
    public static function get(string $key, mixed $default = null): mixed
    {
        $value = self::$data ?? [];
        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }
        return $value;
    }
}
