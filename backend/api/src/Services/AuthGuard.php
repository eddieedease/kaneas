<?php

declare(strict_types=1);

namespace Kaneas\Services;

use Kaneas\Core\Config;
use Kaneas\Core\Database;
use Kaneas\Core\HttpException;
use Kaneas\Core\Jwt;
use Kaneas\Core\Request;

final class AuthGuard
{
    /**
     * Validates the bearer access token and loads the user fresh from the database,
     * so role changes and deactivations take effect immediately.
     */
    public static function authenticate(Request $request): array
    {
        $token = $request->bearerToken();
        if ($token === null) {
            throw new HttpException(401, 'unauthorized');
        }

        $payload = Jwt::decode($token, (string) Config::get('jwt.secret'));
        if (($payload['typ'] ?? null) !== 'access' || ($payload['iss'] ?? null) !== TokenService::ISSUER) {
            throw new HttpException(401, 'token_invalid');
        }

        $user = Database::get()->one(
            'SELECT id, email, name, role, locale, is_active FROM {users} WHERE id = ?',
            [(int) ($payload['sub'] ?? 0)],
        );
        if ($user === null || !$user['is_active']) {
            throw new HttpException(401, 'unauthorized');
        }
        return $user;
    }
}
