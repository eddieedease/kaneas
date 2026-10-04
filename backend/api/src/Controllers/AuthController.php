<?php

declare(strict_types=1);

namespace Kaneas\Controllers;

use Kaneas\Core\Database;
use Kaneas\Core\HttpException;
use Kaneas\Core\Request;
use Kaneas\Core\Response;
use Kaneas\Core\Validator;
use Kaneas\Services\EmailVerification;
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
        return [
            'allow_registration' => Settings::allowRegistration(),
            'email_verification' => EmailVerification::isRequired(),
        ];
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
        $verify = EmailVerification::isRequired();
        $db = Database::get();

        $user = $db->transaction(static function (Database $db) use ($data, $email, $verify): array {
            if ($db->value('SELECT id FROM {users} WHERE email = ?', [$email]) !== null) {
                throw HttpException::validation(['email' => 'taken']);
            }
            $id = $db->insert(
                'INSERT INTO {users} (email, name, password_hash, role, locale) VALUES (?, ?, ?, ?, ?)',
                [$email, $data['name'], Passwords::hash($data['password']), 'user', $data['locale'] ?? 'nl'],
            );
            if (!$verify) {
                EmailVerification::acceptInvitations($db, $id, $email);
            }
            return $db->one('SELECT id, email, name, role, locale FROM {users} WHERE id = ?', [$id]);
        });

        if ($verify) {
            // No session yet: the account is activated through the emailed link.
            EmailVerification::send($user);
            return Response::created(['verification_required' => true, 'email' => $email]);
        }

        $db->query('UPDATE {users} SET last_login_at = UTC_TIMESTAMP() WHERE id = ?', [$user['id']]);
        return Response::created(TokenService::startSession($user, $request));
    }

    /** Body: { "token": "..." } from the emailed link. Verifies and signs the user in. */
    public function verifyEmail(Request $request): array
    {
        RateLimiter::ensure('verify:' . $request->ip(), 20, 3600);
        RateLimiter::hit('verify:' . $request->ip());
        $data = Validator::validate($request->all(), ['token' => 'required|string|max:200']);

        $user = EmailVerification::consume($data['token']);
        Database::get()->query('UPDATE {users} SET last_login_at = UTC_TIMESTAMP() WHERE id = ?', [$user['id']]);
        return TokenService::startSession($user, $request);
    }

    /** Body: { "email": "..." }. Always 204, so it doesn't reveal which addresses exist. */
    public function resendVerification(Request $request): Response
    {
        $data = Validator::validate($request->all(), ['email' => 'required|email']);
        $email = strtolower($data['email']);
        RateLimiter::ensure('resend-ip:' . $request->ip(), 10, 3600);
        RateLimiter::ensure('resend:' . $email, 3, 3600);
        RateLimiter::hit('resend-ip:' . $request->ip());
        RateLimiter::hit('resend:' . $email);

        if (EmailVerification::isRequired()) {
            $user = Database::get()->one(
                'SELECT id, email, name, locale FROM {users} WHERE email = ? AND is_active = 1 AND email_verified_at IS NULL',
                [$email],
            );
            if ($user !== null) {
                EmailVerification::send($user);
            }
        }
        return Response::noContent();
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
            'SELECT id, email, name, role, locale, is_active, email_verified_at, password_hash FROM {users} WHERE email = ?',
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
        // Checked after the password, so it doesn't reveal which addresses are registered.
        if ($user['email_verified_at'] === null && EmailVerification::isRequired()) {
            throw new HttpException(403, 'email_not_verified');
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
