<?php

declare(strict_types=1);

namespace Kaneas\Services;

use Kaneas\Core\Config;
use Kaneas\Core\Database;

/**
 * Runtime settings stored in the {settings} table, editable by admins.
 * Secrets (SMTP password) are encrypted with the app key from config.php.
 */
final class Settings
{
    public const MAIL_DEFAULTS = [
        'enabled' => false,
        'host' => '',
        'port' => 587,
        'encryption' => 'tls', // tls (STARTTLS) | ssl | none
        'username' => '',
        'password' => '',
        'from_address' => '',
        'from_name' => 'Kaneas',
    ];

    public static function get(string $name, mixed $default = null, ?Database $db = null): mixed
    {
        $db ??= Database::get();
        $value = $db->value('SELECT value FROM {settings} WHERE name = ?', [$name]);
        if ($value === null) {
            return $default;
        }
        return json_decode((string) $value, true);
    }

    public static function set(string $name, mixed $value, ?Database $db = null): void
    {
        $db ??= Database::get();
        $db->query(
            'INSERT INTO {settings} (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
            [$name, json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)],
        );
    }

    public static function allowRegistration(): bool
    {
        return (bool) self::get('allow_registration', true);
    }

    /** Admin preference; only enforced when mail is configured (see EmailVerification::isRequired). */
    public static function requireEmailVerification(): bool
    {
        return (bool) self::get('require_email_verification', true);
    }

    /** Mail settings with the password decrypted. */
    public static function mail(?Database $db = null, ?string $appKey = null): array
    {
        $stored = self::get('mail', [], $db);
        $mail = array_merge(self::MAIL_DEFAULTS, is_array($stored) ? $stored : []);
        $mail['password'] = $mail['password'] !== ''
            ? (self::decrypt($mail['password'], $appKey ?? self::appKey()) ?? '')
            : '';
        return $mail;
    }

    /** @param array $mail keys of MAIL_DEFAULTS; a null password keeps the stored one. */
    public static function saveMail(array $mail, ?Database $db = null, ?string $appKey = null): void
    {
        $appKey ??= self::appKey();
        $current = self::get('mail', [], $db);
        $merged = array_merge(self::MAIL_DEFAULTS, is_array($current) ? $current : [], array_intersect_key($mail, self::MAIL_DEFAULTS));

        if (array_key_exists('password', $mail) && $mail['password'] !== null) {
            $merged['password'] = $mail['password'] === '' ? '' : self::encrypt((string) $mail['password'], $appKey);
        }
        self::set('mail', $merged, $db);
    }

    private static function appKey(): string
    {
        return (string) Config::get('app.key');
    }

    private static function encrypt(string $plain, string $appKey): string
    {
        $key = hash('sha256', $appKey, true);
        $iv = random_bytes(12);
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        return base64_encode($iv . $tag . $cipher);
    }

    private static function decrypt(string $encoded, string $appKey): ?string
    {
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) < 28) {
            return null;
        }
        $key = hash('sha256', $appKey, true);
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        return $plain === false ? null : $plain;
    }
}
