<?php

declare(strict_types=1);

namespace Kaneas\Controllers;

use Kaneas\Core\Database;
use Kaneas\Core\HttpException;
use Kaneas\Core\Request;
use Kaneas\Core\Response;
use Kaneas\Core\Validator;
use Kaneas\Services\BoardAccess;
use Kaneas\Services\Mailer;
use Kaneas\Services\MailTemplates;

/**
 * Board collaboration. Only the owner (creator) manages members.
 * Adding an email of an existing user adds them directly; otherwise a pending
 * invitation is stored that turns into a membership when they register.
 */
final class MemberController
{
    public static function members(int $boardId): array
    {
        return Database::get()->all(
            'SELECT u.id AS user_id, u.name, u.email, m.role, m.created_at
             FROM {board_members} m JOIN {users} u ON u.id = m.user_id
             WHERE m.board_id = ? ORDER BY FIELD(m.role, \'owner\', \'editor\', \'viewer\'), u.name',
            [$boardId],
        );
    }

    public static function invitations(int $boardId): array
    {
        return Database::get()->all(
            'SELECT id, email, role, created_at FROM {board_invitations} WHERE board_id = ? ORDER BY created_at',
            [$boardId],
        );
    }

    public function index(Request $request, array $params): array
    {
        $role = BoardAccess::require($params['id'], $request->userId());
        return [
            'members' => self::members($params['id']),
            'invitations' => $role === BoardAccess::OWNER ? self::invitations($params['id']) : [],
        ];
    }

    /** Body: { "email": "...", "role": "editor" | "viewer" } */
    public function store(Request $request, array $params): Response
    {
        $boardId = $params['id'];
        BoardAccess::require($boardId, $request->userId(), BoardAccess::OWNER);
        $data = Validator::validate($request->all(), [
            'email' => 'required|email',
            'role' => 'in:editor,viewer',
        ]);
        $email = strtolower($data['email']);
        $role = $data['role'] ?? BoardAccess::EDITOR;

        $db = Database::get();
        $board = $db->one('SELECT name FROM {boards} WHERE id = ?', [$boardId]);
        $user = $db->one('SELECT id, name, locale, is_active FROM {users} WHERE email = ?', [$email]);
        $inviter = $request->user['name'];
        $mailer = Mailer::fromSettings();

        if ($user !== null) {
            if (BoardAccess::role($boardId, (int) $user['id']) !== null) {
                throw HttpException::validation(['email' => 'already_member']);
            }
            $db->query('INSERT INTO {board_members} (board_id, user_id, role) VALUES (?, ?, ?)', [$boardId, $user['id'], $role]);

            [$subject, $body] = MailTemplates::render('added', $user['locale'], [
                'name' => $user['name'], 'inviter' => $inviter, 'board' => $board['name'],
                'url' => MailTemplates::appUrl('boards/' . $boardId),
            ]);
            $mailed = $mailer->trySend($email, $subject, $body);

            return Response::created(['type' => 'member', 'mailed' => $mailed, 'members' => self::members($boardId)]);
        }

        $db->query(
            'INSERT INTO {board_invitations} (board_id, email, role, invited_by) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE role = VALUES(role), invited_by = VALUES(invited_by)',
            [$boardId, $email, $role, $request->userId()],
        );
        [$subject, $body] = MailTemplates::render('invite', $request->user['locale'], [
            'inviter' => $inviter, 'board' => $board['name'], 'url' => MailTemplates::appUrl('register'),
        ]);
        $mailed = $mailer->trySend($email, $subject, $body);

        return Response::created(['type' => 'invitation', 'mailed' => $mailed, 'invitations' => self::invitations($boardId)]);
    }

    public function update(Request $request, array $params): array
    {
        BoardAccess::require($params['id'], $request->userId(), BoardAccess::OWNER);
        $data = Validator::validate($request->all(), ['role' => 'required|in:editor,viewer']);
        $this->assertNotOwner($params['id'], $params['userId']);

        Database::get()->query(
            'UPDATE {board_members} SET role = ? WHERE board_id = ? AND user_id = ?',
            [$data['role'], $params['id'], $params['userId']],
        );
        return self::members($params['id']);
    }

    /** Owners remove members; any member may remove themselves (leave the board). */
    public function destroy(Request $request, array $params): Response
    {
        $isSelf = $params['userId'] === $request->userId();
        BoardAccess::require($params['id'], $request->userId(), $isSelf ? BoardAccess::VIEWER : BoardAccess::OWNER);
        $this->assertNotOwner($params['id'], $params['userId']);

        $db = Database::get();
        $db->transaction(static function (Database $db) use ($params): void {
            $db->query('DELETE FROM {board_members} WHERE board_id = ? AND user_id = ?', [$params['id'], $params['userId']]);
            $db->query('UPDATE {cards} SET assignee_id = NULL WHERE board_id = ? AND assignee_id = ?', [$params['id'], $params['userId']]);
        });
        return Response::noContent();
    }

    public function destroyInvitation(Request $request, array $params): Response
    {
        BoardAccess::require($params['id'], $request->userId(), BoardAccess::OWNER);
        Database::get()->query(
            'DELETE FROM {board_invitations} WHERE id = ? AND board_id = ?',
            [$params['invitationId'], $params['id']],
        );
        return Response::noContent();
    }

    private function assertNotOwner(int $boardId, int $userId): void
    {
        $role = BoardAccess::role($boardId, $userId);
        if ($role === null) {
            throw HttpException::notFound();
        }
        if ($role === BoardAccess::OWNER) {
            throw new HttpException(422, 'owner_immutable');
        }
    }
}
