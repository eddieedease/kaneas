<?php

declare(strict_types=1);

namespace Kaneas\Services;

final class Passwords
{
    /** Password rules shared by register, password change and the installer. bcrypt only uses 72 bytes. */
    public const RULES = 'required|string|raw|min:8|bytes:72';

    public static function hash(string $password): string
    {
        return password_hash($password, self::algorithm());
    }

    public static function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, self::algorithm());
    }

    /** Verifies against a dummy hash when the user doesn't exist, so timing doesn't reveal valid emails. */
    public static function verify(string $password, ?string $hash): bool
    {
        static $dummy = null;
        $dummy ??= password_hash('kaneas-dummy-password', self::algorithm());
        $ok = password_verify($password, $hash ?? $dummy);
        return $hash !== null && $ok;
    }

    private static function algorithm(): string
    {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
    }
}
