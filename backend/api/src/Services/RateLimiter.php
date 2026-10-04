<?php

declare(strict_types=1);

namespace Kaneas\Services;

use Kaneas\Core\Database;
use Kaneas\Core\HttpException;

/** Database backed sliding window rate limiter (no Redis/APCu on shared hosting). */
final class RateLimiter
{
    /** @throws HttpException 429 when the bucket already holds $max hits within the window. */
    public static function ensure(string $bucket, int $max, int $windowSeconds): void
    {
        $count = (int) Database::get()->value(
            'SELECT COUNT(*) FROM {rate_limits} WHERE bucket = ? AND created_at > UTC_TIMESTAMP() - INTERVAL ? SECOND',
            [$bucket, $windowSeconds],
        );
        if ($count >= $max) {
            header('Retry-After: ' . $windowSeconds);
            throw new HttpException(429, 'too_many_attempts');
        }
    }

    public static function hit(string $bucket): void
    {
        $db = Database::get();
        $db->query('INSERT INTO {rate_limits} (bucket) VALUES (?)', [$bucket]);
        if (random_int(1, 50) === 1) {
            $db->query('DELETE FROM {rate_limits} WHERE created_at < UTC_TIMESTAMP() - INTERVAL 1 DAY');
        }
    }

    public static function clear(string $bucket): void
    {
        Database::get()->query('DELETE FROM {rate_limits} WHERE bucket = ?', [$bucket]);
    }
}
