<?php

declare(strict_types=1);

namespace Kaneas\Controllers;

use Kaneas\Core\Database;
use Kaneas\Core\HttpException;
use Kaneas\Core\Request;
use Kaneas\Core\Response;
use Kaneas\Core\Validator;
use Kaneas\Services\Passwords;
use Kaneas\Services\RateLimiter;
use Kaneas\Services\Settings;
use Kaneas\Services\TokenService;

final class AuthController
{
    private const LOCALES = 'in:nl,en';

    /** Public app config the SPA needs before login. */
    public function config(Request $request): array
    {
        return ['allow_registration' => Settings::allowRegistration()];
    }

    public function register(Request $request): Response
    {
        if (!Settings::allowRegistration()) {
            throw new HttpException(403, 'registration_disabled');
        }
        RateLimiter::ensure('register:' . $request->ip(), 10, 3600);
        RateLimiter::hit('register:' . $request->ip());

        $data = Validator::validate($request->all(), [
            'name' => 'required|string|min:1|max:100',
            'email' => 'required|email',
            'password' => Passwords::RULES,
            'locale' => self::LOCALES,
        ]);
        $email = strtolower($data['email']);
        $db = Database::get();

        $user = $db->transaction(static function (Database $db) use ($data, $email): array {
            if ($db->value('SELECT id FROM {users} WHERE email = ?', [$email]) !== null) {
                throw HttpException::validation(['email' => 'taken']);
            }
            $id = $db->insert(
                'INSERT INTO {users} (email, name, password_hash, role, locale, last_login_at)
                 VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())',
                [$email, $data['name'], Passwords::hash($data['password']), 'user', $data['locale'] ?? 'nl'],
            );

            // Turn pending board invitations for this email into memberships.
            $db->query(
                'INSERT IGNORE INTO {board_members} (board_id, user_id, role)
                 SELECT board_id, ?, role FROM {board_invitations} WHERE email = ?',
                [$id, $email],
            );
            $db->query('DELETE FROM {board_invitations} WHERE email = ?', [$email]);

            return $db->one('SELECT id, email, name, role, locale FROM {users} WHERE id = ?', [$id]);
        });

        return Response::created(TokenService::startSession($user, $request));
    }

    public function login(Request $request): array
    {
        $data = Validator::validate($request->all(), [
            'email' => 'required|string|max:190',
            'password' => 'required|string|raw|bytes:1024',
        ]);
        $email = strtolower($data['email']);
        $pairBucket = 'login:' . $email . '|' . $request->ip();
        $ipBucket = 'login-ip:' . $request->ip();

        RateLimiter::ensure($pairBucket, 5, 900);
        RateLimiter::ensure($ipBucket, 30, 900);

        $db = Database::get();
        $user = $db->one(
            'SELECT id, email, name, role, locale, is_active, password_hash FROM {users} WHERE email = ?',
            [$email],
        );

        if (!Passwords::verify($data['password'], $user['password_hash'] ?? null)) {
            RateLimiter::hit($pairBucket);
            RateLimiter::hit($ipBucket);
            throw new HttpException(401, 'invalid_credentials');
        }
        if (!$user['is_active']) {
            throw new HttpException(403, 'account_disabled');
        }

        RateLimiter::clear($pairBucket);
        if (Passwords::needsRehash($user['password_hash'])) {
            $db->query('UPDATE {users} SET password_hash = ? WHERE id = ?', [Passwords::hash($data['password']), $user['id']]);
        }
        $db->query('UPDATE {users} SET last_login_at = UTC_TIMESTAMP() WHERE id = ?', [$user['id']]);

        return TokenService::startSession($user, $request);
    }

    public function refresh(Request $request): array
    {
        $this->assertSameSiteJson($request);
        return TokenService::refreshSession($request);
    }

    public function logout(Request $request): Response
    {
        $this->assertSameSiteJson($request);
        TokenService::endSession($request);
        return Response::noContent();
    }

    public function me(Request $request): array
    {
        return TokenService::publicUser($request->user);
    }

    public function updateMe(Request $request): array
    {
        $data = Validator::validate($request->all(), [
            'name' => 'string|min:1|max:100',
            'locale' => self::LOCALES,
        ]);
        if ($data) {
            $sets = implode(', ', array_map(static fn ($k) => "$k = ?", array_keys($data)));
            Database::get()->query("UPDATE {users} SET $sets WHERE id = ?", [...array_values($data), $request->userId()]);
        }
        $user = Database::get()->one('SELECT id, email, name, role, locale FROM {users} WHERE id = ?', [$request->userId()]);
        return TokenService::publicUser($user);
    }

    public function changePassword(Request $request): array
    {
        $data = Validator::validate($request->all(), [
            'current_password' => 'required|string|raw|bytes:1024',
            'password' => Passwords::RULES,
        ]);
        $db = Database::get();
        $hash = $db->value('SELECT password_hash FROM {users} WHERE id = ?', [$request->userId()]);
        if (!Passwords::verify($data['current_password'], $hash)) {
            throw HttpException::validation(['current_password' => 'invalid']);
        }
        $db->query('UPDATE {users} SET password_hash = ? WHERE id = ?', [Passwords::hash($data['password']), $request->userId()]);

        // Sign out all other sessions, then start a fresh one for this device.
        TokenService::revokeAllForUser($request->userId());
        return TokenService::startSession($request->user, $request);
    }

    /**
     * The refresh cookie is SameSite=Strict; requiring a JSON content type additionally
     * forces a CORS preflight for any cross-site attempt (classic forms can't send it).
     */
    private function assertSameSiteJson(Request $request): void
    {
        if (!$request->isJson()) {
            throw new HttpException(415, 'unsupported_media_type');
        }
    }
}
