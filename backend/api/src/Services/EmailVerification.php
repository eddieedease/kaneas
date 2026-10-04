<?php

declare(strict_types=1);

namespace Kaneas\Services;

use Kaneas\Core\Database;
use Kaneas\Core\HttpException;

/**
 * Email verification for self-registered accounts.
 *
 * Only enforced when the admin setting is on AND mail is configured, so
 * development (no SMTP) and fresh installs keep working without mail.
 */
final class EmailVerification
{
    private const PURPOSE = 'verify_email';
    private const TTL_SECONDS = 86400;

    public static function isRequired(): bool
    {
        return Settings::requireEmailVerification() && Mailer::fromSettings()->isConfigured();
    }

    /**
     * Creates a fresh single-use token (older ones are invalidated) and mails the link.
     * @param array{id:int|string,email:string,name:string,locale:string} $user
     */
    public static function send(array $user): bool
    {
        $raw = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $db = Database::get();
        $db->transaction(static function (Database $db) use ($user, $raw): void {
            $db->query('DELETE FROM {user_tokens} WHERE user_id = ? AND purpose = ?', [$user['id'], self::PURPOSE]);
            $db->query(
                'INSERT INTO {user_tokens} (user_id, purpose, token_hash, expires_at)
                 VALUES (?, ?, ?, UTC_TIMESTAMP() + INTERVAL ? SECOND)',
                [$user['id'], self::PURPOSE, hash('sha256', $raw), self::TTL_SECONDS],
            );
        });

        [$subject, $body] = MailTemplates::render('verify', $user['locale'], [
            'name' => $user['name'],
            'url' => MailTemplates::appUrl('verify-email?token=' . $raw),
        ]);
        return Mailer::fromSettings()->trySend($user['email'], $subject, $body);
    }

    /**
     * Marks the token's user as verified and returns the user.
     * @throws HttpException 400 verification_invalid
     */
    public static function consume(string $raw): array
    {
        return Database::get()->transaction(static function (Database $db) use ($raw): array {
            $token = $db->one(
                'SELECT id, user_id FROM {user_tokens}
                 WHERE token_hash = ? AND purpose = ? AND used_at IS NULL AND expires_at > UTC_TIMESTAMP()
                 FOR UPDATE',
                [hash('sha256', $raw), self::PURPOSE],
            );
            if ($token === null) {
                throw new HttpException(400, 'verification_invalid');
            }
            $db->query('UPDATE {user_tokens} SET used_at = UTC_TIMESTAMP() WHERE id = ?', [$token['id']]);

            $user = $db->one('SELECT id, email, name, role, locale, is_active FROM {users} WHERE id = ?', [$token['user_id']]);
            if ($user === null || !$user['is_active']) {
                throw new HttpException(400, 'verification_invalid');
            }
            self::markVerified($db, (int) $user['id'], $user['email']);
            return $user;
        });
    }

    /** Sets email_verified_at and turns pending board invitations for this address into memberships. */
    public static function markVerified(Database $db, int $userId, string $email): void
    {
        $db->query('UPDATE {users} SET email_verified_at = COALESCE(email_verified_at, UTC_TIMESTAMP()) WHERE id = ?', [$userId]);
        self::acceptInvitations($db, $userId, $email);
    }

    /**
     * Pending invitations become memberships only once the address is trusted (verified,
     * or verification is not in use) — otherwise anyone could claim someone else's invites.
     */
    public static function acceptInvitations(Database $db, int $userId, string $email): void
    {
        $db->query(
            'INSERT IGNORE INTO {board_members} (board_id, user_id, role)
             SELECT board_id, ?, role FROM {board_invitations} WHERE email = ?',
            [$userId, $email],
        );
        $db->query('DELETE FROM {board_invitations} WHERE email = ?', [$email]);
    }
}
