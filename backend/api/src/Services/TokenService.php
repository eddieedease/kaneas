<?php

declare(strict_types=1);

namespace Kaneas\Services;

use Kaneas\Core\Config;
use Kaneas\Core\Database;
use Kaneas\Core\HttpException;
use Kaneas\Core\Jwt;
use Kaneas\Core\Request;

/**
 * Short lived access tokens (JWT, kept in memory by the SPA) and long lived,
 * rotating refresh tokens (random, stored hashed, sent as an httpOnly cookie).
 */
final class TokenService
{
    public const ISSUER = 'kaneas';
    public const COOKIE = 'kaneas_rt';

    /** Window in which a just-rotated refresh token may be reused (parallel tabs/requests). */
    private const REUSE_GRACE_SECONDS = 30;

    /** Issues a new session (access token + refresh cookie) and returns the response body. */
    public static function startSession(array $user, Request $request): array
    {
        self::issueRefreshToken((int) $user['id'], bin2hex(random_bytes(16)), $request);
        return self::sessionBody($user);
    }

    /** Rotates the refresh token from the cookie. Detects reuse of stolen tokens. */
    public static function refreshSession(Request $request): array
    {
        $raw = $request->cookie(self::COOKIE);
        if ($raw === null || $raw === '') {
            throw new HttpException(401, 'session_expired');
        }

        $db = Database::get();
        return $db->transaction(static function (Database $db) use ($raw, $request): array {
            $token = $db->one(
                'SELECT id, user_id, family_id, revoked_at,
                        expires_at > UTC_TIMESTAMP() AS is_valid,
                        revoked_at > UTC_TIMESTAMP() - INTERVAL ' . self::REUSE_GRACE_SECONDS . ' SECOND AS in_grace
                 FROM {refresh_tokens} WHERE token_hash = ? FOR UPDATE',
                [hash('sha256', $raw)],
            );

            if ($token === null || !$token['is_valid']) {
                self::clearCookie($request);
                throw new HttpException(401, 'session_expired');
            }

            if ($token['revoked_at'] !== null && !$token['in_grace']) {
                // A revoked token was presented again: assume theft, kill the whole session family.
                $db->query(
                    'UPDATE {refresh_tokens} SET revoked_at = UTC_TIMESTAMP() WHERE family_id = ? AND revoked_at IS NULL',
                    [$token['family_id']],
                );
                self::clearCookie($request);
                throw new HttpException(401, 'session_expired');
            }

            $user = $db->one(
                'SELECT id, email, name, role, locale, is_active FROM {users} WHERE id = ?',
                [$token['user_id']],
            );
            if ($user === null || !$user['is_active']) {
                self::clearCookie($request);
                throw new HttpException(401, 'session_expired');
            }

            $db->query(
                'UPDATE {refresh_tokens} SET revoked_at = UTC_TIMESTAMP() WHERE id = ? AND revoked_at IS NULL',
                [$token['id']],
            );
            self::issueRefreshToken((int) $user['id'], $token['family_id'], $request);

            return self::sessionBody($user);
        });
    }

    public static function endSession(Request $request): void
    {
        $raw = $request->cookie(self::COOKIE);
        if ($raw !== null && $raw !== '') {
            $db = Database::get();
            $familyId = $db->value('SELECT family_id FROM {refresh_tokens} WHERE token_hash = ?', [hash('sha256', $raw)]);
            if ($familyId !== null) {
                $db->query(
                    'UPDATE {refresh_tokens} SET revoked_at = UTC_TIMESTAMP() WHERE family_id = ? AND revoked_at IS NULL',
                    [$familyId],
                );
            }
        }
        self::clearCookie($request);
    }

    /** Signs the user out everywhere (password change, deactivation). */
    public static function revokeAllForUser(int $userId): void
    {
        Database::get()->query(
            'UPDATE {refresh_tokens} SET revoked_at = UTC_TIMESTAMP() WHERE user_id = ? AND revoked_at IS NULL',
            [$userId],
        );
    }

    public static function publicUser(array $user): array
    {
        return [
            'id' => (int) $user['id'],
            'email' => $user['email'],
            'name' => $user['name'],
            'role' => $user['role'],
            'locale' => $user['locale'],
        ];
    }

    private static function sessionBody(array $user): array
    {
        $ttl = (int) Config::get('jwt.access_ttl', 900);
        $now = time();
        $accessToken = Jwt::encode([
            'iss' => self::ISSUER,
            'typ' => 'access',
            'sub' => (int) $user['id'],
            'role' => $user['role'],
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + $ttl,
            'jti' => bin2hex(random_bytes(8)),
        ], (string) Config::get('jwt.secret'));

        return [
            'access_token' => $accessToken,
            'token_type' => 'Bearer',
            'expires_in' => $ttl,
            'user' => self::publicUser($user),
        ];
    }

    private static function issueRefreshToken(int $userId, string $familyId, Request $request): void
    {
        $raw = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $ttl = (int) Config::get('jwt.refresh_ttl', 60 * 60 * 24 * 30);
        $db = Database::get();

        $db->query(
            'INSERT INTO {refresh_tokens} (user_id, token_hash, family_id, expires_at, user_agent, ip)
             VALUES (?, ?, ?, UTC_TIMESTAMP() + INTERVAL ? SECOND, ?, ?)',
            [$userId, hash('sha256', $raw), $familyId, $ttl, $request->userAgent(), $request->ip()],
        );

        // Opportunistic housekeeping, avoids needing a cron job on shared hosting.
        if (random_int(1, 50) === 1) {
            $db->query('DELETE FROM {refresh_tokens} WHERE expires_at < UTC_TIMESTAMP() - INTERVAL 1 DAY');
        }

        self::setCookie($request, $raw, time() + $ttl);
    }

    private static function setCookie(Request $request, string $value, int $expires): void
    {
        setcookie(self::COOKIE, $value, [
            'expires' => $expires,
            'path' => $request->apiBasePath() . '/auth',
            'secure' => $request->isHttps(),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    }

    private static function clearCookie(Request $request): void
    {
        self::setCookie($request, '', time() - 3600);
    }
}
