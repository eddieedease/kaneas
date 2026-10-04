<?php

declare(strict_types=1);

namespace Kaneas\Controllers;

use Kaneas\Core\Database;
use Kaneas\Core\HttpException;
use Kaneas\Core\Request;
use Kaneas\Core\Response;
use Kaneas\Core\Validator;
use Kaneas\Services\BoardAccess;

final class BoardController
{
    private const DEFAULT_COLUMNS = [
        'nl' => ['Te doen', 'Bezig', 'Klaar'],
        'en' => ['To do', 'In progress', 'Done'],
    ];

    public function index(Request $request): array
    {
        return Database::get()->all(
            'SELECT b.id, b.name, b.description, b.owner_id, m.role AS my_role, b.created_at, b.updated_at,
                    (SELECT COUNT(*) FROM {board_members} bm WHERE bm.board_id = b.id) AS member_count,
                    (SELECT COUNT(*) FROM {cards} c WHERE c.board_id = b.id) AS card_count
             FROM {boards} b
             JOIN {board_members} m ON m.board_id = b.id AND m.user_id = ?
             ORDER BY b.updated_at DESC',
            [$request->userId()],
        );
    }

    public function store(Request $request): Response
    {
        $data = Validator::validate($request->all(), [
            'name' => 'required|string|min:1|max:150',
            'description' => 'string|max:5000',
        ]);
        $userId = $request->userId();
        $columns = self::DEFAULT_COLUMNS[$request->user['locale']] ?? self::DEFAULT_COLUMNS['nl'];

        $boardId = Database::get()->transaction(static function (Database $db) use ($data, $userId, $columns): int {
            $boardId = $db->insert(
                'INSERT INTO {boards} (owner_id, name, description) VALUES (?, ?, ?)',
                [$userId, $data['name'], $data['description'] ?? null],
            );
            $db->query('INSERT INTO {board_members} (board_id, user_id, role) VALUES (?, ?, ?)', [$boardId, $userId, BoardAccess::OWNER]);
            foreach ($columns as $position => $name) {
                $db->query('INSERT INTO {board_columns} (board_id, name, position) VALUES (?, ?, ?)', [$boardId, $name, $position]);
            }
            return $boardId;
        });

        return Response::created($this->load($boardId, $userId));
    }

    public function show(Request $request, array $params): array
    {
        BoardAccess::require($params['id'], $request->userId());
        return $this->load($params['id'], $request->userId());
    }

    public function update(Request $request, array $params): array
    {
        BoardAccess::require($params['id'], $request->userId(), BoardAccess::OWNER);
        $data = Validator::validate($request->all(), [
            'name' => 'string|min:1|max:150',
            'description' => 'string|max:5000',
        ]);
        if ($request->has('description') && $request->input('description') === null) {
            $data['description'] = null;
        }
        if ($data) {
            $sets = implode(', ', array_map(static fn ($k) => "$k = ?", array_keys($data)));
            Database::get()->query("UPDATE {boards} SET $sets WHERE id = ?", [...array_values($data), $params['id']]);
        }
        return $this->load($params['id'], $request->userId());
    }

    public function destroy(Request $request, array $params): Response
    {
        BoardAccess::require($params['id'], $request->userId(), BoardAccess::OWNER);
        Database::get()->query('DELETE FROM {boards} WHERE id = ?', [$params['id']]);
        return Response::noContent();
    }

    /** Full board: meta, columns with cards, members (+ pending invitations for owners). */
    private function load(int $boardId, int $userId): array
    {
        $db = Database::get();
        $board = $db->one('SELECT id, name, description, owner_id, created_at, updated_at FROM {boards} WHERE id = ?', [$boardId]);
        if ($board === null) {
            throw HttpException::notFound();
        }
        $board['my_role'] = BoardAccess::role($boardId, $userId);

        $columns = $db->all('SELECT id, name, position FROM {board_columns} WHERE board_id = ? ORDER BY position, id', [$boardId]);
        $cards = $db->all(
            'SELECT id, column_id, title, description, position, assignee_id, created_by, created_at, updated_at
             FROM {cards} WHERE board_id = ? ORDER BY position, id',
            [$boardId],
        );
        $byColumn = [];
        foreach ($cards as $card) {
            $byColumn[$card['column_id']][] = $card;
        }
        foreach ($columns as &$column) {
            $column['cards'] = $byColumn[$column['id']] ?? [];
        }
        unset($column);

        $board['columns'] = $columns;
        $board['members'] = MemberController::members($boardId);
        $board['invitations'] = $board['my_role'] === BoardAccess::OWNER ? MemberController::invitations($boardId) : [];

        return $board;
    }
}
