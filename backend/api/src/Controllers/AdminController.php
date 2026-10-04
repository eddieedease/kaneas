<?php

declare(strict_types=1);

namespace Kaneas\Controllers;

use Kaneas\Core\Database;
use Kaneas\Core\HttpException;
use Kaneas\Core\Request;
use Kaneas\Core\Response;
use Kaneas\Core\Validator;
use Kaneas\Services\EmailVerification;
use Kaneas\Services\Mailer;
use Kaneas\Services\MailTemplates;
use Kaneas\Services\Settings;
use Kaneas\Services\TokenService;

final class AdminController
{
    private const USER_FIELDS = 'u.id, u.email, u.name, u.role, u.locale, u.is_active, u.email_verified_at, u.last_login_at, u.created_at';

    public function users(Request $request): array
    {
        return Database::get()->all(
            'SELECT ' . self::USER_FIELDS . ',
                    (SELECT COUNT(*) FROM {boards} b WHERE b.owner_id = u.id) AS board_count
             FROM {users} u ORDER BY u.created_at DESC',
        );
    }

    /** Body: { "role"?: "user"|"admin", "is_active"?: bool, "email_verified"?: true } */
    public function updateUser(Request $request, array $params): array
    {
        $data = Validator::validate($request->all(), [
            'role' => 'in:user,admin',
            'is_active' => 'bool',
            'email_verified' => 'bool',
        ]);
        $db = Database::get();
        $target = $db->one('SELECT id, email FROM {users} WHERE id = ?', [$params['id']]);
        if ($target === null) {
            throw HttpException::notFound();
        }
        if ($params['id'] === $request->userId() && (($data['role'] ?? 'admin') !== 'admin' || ($data['is_active'] ?? true) === false)) {
            throw new HttpException(422, 'cannot_demote_self');
        }

        $db->transaction(static function (Database $db) use ($data, $params, $target): void {
            if (($data['email_verified'] ?? false) === true) {
                EmailVerification::markVerified($db, $params['id'], $target['email']);
            }
            unset($data['email_verified']);
            if ($data) {
                $sets = implode(', ', array_map(static fn ($k) => "$k = ?", array_keys($data)));
                $values = array_map(static fn ($v) => is_bool($v) ? (int) $v : $v, array_values($data));
                $db->query("UPDATE {users} SET $sets WHERE id = ?", [...$values, $params['id']]);
            }
        });
        if (($data['is_active'] ?? true) === false) {
            TokenService::revokeAllForUser($params['id']);
        }
        return $db->one('SELECT ' . self::USER_FIELDS . ' FROM {users} u WHERE u.id = ?', [$params['id']]);
    }

    public function deleteUser(Request $request, array $params): Response
    {
        if ($params['id'] === $request->userId()) {
            throw new HttpException(422, 'cannot_demote_self');
        }
        $db = Database::get();
        if ($db->value('SELECT id FROM {users} WHERE id = ?', [$params['id']]) === null) {
            throw HttpException::notFound();
        }
        // Boards owned by the user are deleted with them (FK cascade).
        $db->query('DELETE FROM {users} WHERE id = ?', [$params['id']]);
        return Response::noContent();
    }

    public function settings(Request $request): array
    {
        return [
            'allow_registration' => Settings::allowRegistration(),
            'require_email_verification' => Settings::requireEmailVerification(),
            // Verification is only enforced once mail works.
            'mail_configured' => Mailer::fromSettings()->isConfigured(),
        ];
    }

    public function updateSettings(Request $request): array
    {
        $data = Validator::validate($request->all(), [
            'allow_registration' => 'bool',
            'require_email_verification' => 'bool',
        ]);
        foreach ($data as $name => $value) {
            Settings::set($name, $value);
        }
        return $this->settings($request);
    }

    /** The SMTP password is never returned, only whether one is set. */
    public function mailSettings(Request $request): array
    {
        $mail = Settings::mail();
        $mail['has_password'] = $mail['password'] !== '';
        unset($mail['password']);
        return $mail;
    }

    /** Body: mail settings; omit "password" (or send null) to keep the stored one, "" clears it. */
    public function updateMailSettings(Request $request): array
    {
        $data = Validator::validate($request->all(), [
            'enabled' => 'bool',
            'host' => 'string|max:255',
            'port' => 'int|min:1|max:65535',
            'encryption' => 'in:tls,ssl,none',
            'username' => 'string|max:255',
            'password' => 'string|raw|max:255',
            'from_address' => 'email',
            'from_name' => 'string|max:100',
        ]);
        foreach (['host', 'username', 'from_address', 'from_name'] as $clearable) {
            if ($request->input($clearable) === '') {
                $data[$clearable] = '';
            }
        }
        if ($request->input('password') === '') {
            $data['password'] = '';
        }
        if (($data['enabled'] ?? false) && (($data['host'] ?? Settings::mail()['host']) === '')) {
            throw HttpException::validation(['host' => 'required']);
        }
        Settings::saveMail($data);
        return $this->mailSettings($request);
    }

    /** Body: { "to"?: "email" } — defaults to the admin's own address. Reports the SMTP error. */
    public function testMail(Request $request): array
    {
        $data = Validator::validate($request->all(), ['to' => 'email']);
        $to = $data['to'] ?? $request->user['email'];
        [$subject, $body] = MailTemplates::render('test', $request->user['locale']);

        $mail = Settings::mail();
        $mail['enabled'] = true; // allow testing before switching mail on
        $mailer = new Mailer($mail);
        if (!$mailer->isConfigured()) {
            throw new HttpException(422, 'mail_not_configured');
        }
        try {
            $mailer->send($to, $subject, $body);
        } catch (\RuntimeException $e) {
            throw new HttpException(502, 'mail_failed', ['message' => $e->getMessage()]);
        }
        return ['sent' => true, 'to' => $to];
    }
}
