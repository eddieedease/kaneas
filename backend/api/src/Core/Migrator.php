<?php

declare(strict_types=1);

namespace Kaneas\Core;

use Kaneas\Services\Settings;

/**
 * Brings an existing database up to KANEAS_SCHEMA_VERSION by running
 * api/migrations/NNN_name.sql files in order. Runs automatically on the first
 * API request after uploading a new version, so no shell access is needed.
 */
final class Migrator
{
    private const LOCK_TIMEOUT = 10;

    public static function migrate(Database $db): void
    {
        if (self::currentVersion($db) >= KANEAS_SCHEMA_VERSION) {
            return;
        }

        $lock = 'kaneas_migrate_' . $db->prefix();
        if ((int) $db->value('SELECT GET_LOCK(?, ?)', [$lock, self::LOCK_TIMEOUT]) !== 1) {
            throw new HttpException(503, 'maintenance');
        }
        try {
            // Another request may have migrated while we waited for the lock.
            $version = self::currentVersion($db);
            foreach (self::pending($version) as $number => $file) {
                foreach (self::statements((string) file_get_contents($file), $db) as $statement) {
                    $db->query($statement);
                }
                Settings::set('schema_version', $number, $db);
                error_log("[kaneas] migrated database to schema version $number");
            }
        } finally {
            $db->value('SELECT RELEASE_LOCK(?)', [$lock]);
        }
    }

    /**
     * Splits a SQL file into statements (separated by ";" at the end of a line)
     * and expands `{prefix}`. Shared with the installer.
     *
     * @return list<string>
     */
    public static function statements(string $sql, ?Database $db = null, ?string $prefix = null): array
    {
        $sql = str_replace('{prefix}', $prefix ?? $db?->prefix() ?? '', $sql);
        $parts = preg_split('/;\s*$/m', $sql) ?: [];
        $statements = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if (preg_match('/\S/', (string) preg_replace('/^--.*$/m', '', $part))) {
                $statements[] = $part;
            }
        }
        return $statements;
    }

    private static function currentVersion(Database $db): int
    {
        return (int) Settings::get('schema_version', 1, $db);
    }

    /** @return array<int, string> version => file, ascending */
    private static function pending(int $current): array
    {
        $files = [];
        foreach (glob(dirname(__DIR__, 2) . '/migrations/*.sql') ?: [] as $file) {
            $number = (int) basename($file);
            if ($number > $current && $number <= KANEAS_SCHEMA_VERSION) {
                $files[$number] = $file;
            }
        }
        ksort($files);
        return $files;
    }
}
