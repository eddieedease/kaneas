<?php

declare(strict_types=1);

namespace Kaneas\Services;

use Kaneas\Core\Database;
use Kaneas\Core\HttpException;

/** Board level permissions: viewer < editor < owner. */
final class BoardAccess
{
    public const VIEWER = 'viewer';
    public const EDITOR = 'editor';
    public const OWNER = 'owner';

    private const RANK = [self::VIEWER => 1, self::EDITOR => 2, self::OWNER => 3];

    public static function role(int $boardId, int $userId): ?string
    {
        $role = Database::get()->value(
            'SELECT role FROM {board_members} WHERE board_id = ? AND user_id = ?',
            [$boardId, $userId],
        );
        return $role === null ? null : (string) $role;
    }

    /**
     * Returns the user's role on the board, or throws. Non-members get a 404 so board ids can't be probed.
     */
    public static function require(int $boardId, int $userId, string $minimum = self::VIEWER): string
    {
        $role = self::role($boardId, $userId);
        if ($role === null) {
            throw HttpException::notFound();
        }
        if (self::RANK[$role] < self::RANK[$minimum]) {
            throw HttpException::forbidden();
        }
        return $role;
    }

    public static function boardIdForColumn(int $columnId): int
    {
        $boardId = Database::get()->value('SELECT board_id FROM {board_columns} WHERE id = ?', [$columnId]);
        if ($boardId === null) {
            throw HttpException::notFound();
        }
        return (int) $boardId;
    }

    public static function boardIdForCard(int $cardId): int
    {
        $boardId = Database::get()->value('SELECT board_id FROM {cards} WHERE id = ?', [$cardId]);
        if ($boardId === null) {
            throw HttpException::notFound();
        }
        return (int) $boardId;
    }
}
