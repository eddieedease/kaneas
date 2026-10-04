<?php

declare(strict_types=1);

namespace Kaneas\Controllers;

use Kaneas\Core\Database;
use Kaneas\Core\HttpException;
use Kaneas\Core\Request;
use Kaneas\Core\Response;
use Kaneas\Core\Validator;
use Kaneas\Services\BoardAccess;

final class CardController
{
    private const FIELDS = 'id, column_id, title, description, position, assignee_id, created_by, created_at, updated_at';

    public function store(Request $request, array $params): Response
    {
        $columnId = $params['id'];
        $boardId = BoardAccess::boardIdForColumn($columnId);
        BoardAccess::require($boardId, $request->userId(), BoardAccess::EDITOR);
        $data = Validator::validate($request->all(), [
            'title' => 'required|string|min:1|max:200',
            'description' => 'string|max:20000',
            'assignee_id' => 'int',
        ]);
        $this->assertAssignee($boardId, $data['assignee_id'] ?? null);

        $db = Database::get();
        $position = (int) $db->value('SELECT COALESCE(MAX(position) + 1, 0) FROM {cards} WHERE column_id = ?', [$columnId]);
        $id = $db->insert(
            'INSERT INTO {cards} (board_id, column_id, title, description, position, assignee_id, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$boardId, $columnId, $data['title'], $data['description'] ?? null, $position, $data['assignee_id'] ?? null, $request->userId()],
        );
        $this->touchBoard($boardId);

        return Response::created($db->one('SELECT ' . self::FIELDS . ' FROM {cards} WHERE id = ?', [$id]));
    }

    public function update(Request $request, array $params): array
    {
        $boardId = BoardAccess::boardIdForCard($params['id']);
        BoardAccess::require($boardId, $request->userId(), BoardAccess::EDITOR);
        $data = Validator::validate($request->all(), [
            'title' => 'string|min:1|max:200',
            'description' => 'string|max:20000',
            'assignee_id' => 'int',
        ]);
        // Explicit null clears optional fields.
        foreach (['description', 'assignee_id'] as $nullable) {
            if ($request->has($nullable) && $request->input($nullable) === null) {
                $data[$nullable] = null;
            }
        }
        $this->assertAssignee($boardId, $data['assignee_id'] ?? null);

        $db = Database::get();
        if ($data) {
            $sets = implode(', ', array_map(static fn ($k) => "$k = ?", array_keys($data)));
            $db->query("UPDATE {cards} SET $sets WHERE id = ?", [...array_values($data), $params['id']]);
            $this->touchBoard($boardId);
        }
        return $db->one('SELECT ' . self::FIELDS . ' FROM {cards} WHERE id = ?', [$params['id']]);
    }

    /** Body: { "column_id": 4, "position": 0 } — moves the card and renumbers both columns. */
    public function move(Request $request, array $params): array
    {
        $cardId = $params['id'];
        $boardId = BoardAccess::boardIdForCard($cardId);
        BoardAccess::require($boardId, $request->userId(), BoardAccess::EDITOR);
        $data = Validator::validate($request->all(), [
            'column_id' => 'required|int',
            'position' => 'required|int|min:0',
        ]);
        if (BoardAccess::boardIdForColumn($data['column_id']) !== $boardId) {
            throw HttpException::validation(['column_id' => 'invalid']);
        }

        $db = Database::get();
        $db->transaction(static function (Database $db) use ($cardId, $data): void {
            $card = $db->one('SELECT column_id FROM {cards} WHERE id = ? FOR UPDATE', [$cardId]);
            $sourceColumn = (int) $card['column_id'];
            $targetColumn = $data['column_id'];

            $targetIds = array_map('intval', array_column($db->all(
                'SELECT id FROM {cards} WHERE column_id = ? AND id <> ? ORDER BY position, id FOR UPDATE',
                [$targetColumn, $cardId],
            ), 'id'));
            array_splice($targetIds, min($data['position'], count($targetIds)), 0, [$cardId]);

            $db->query('UPDATE {cards} SET column_id = ? WHERE id = ?', [$targetColumn, $cardId]);
            foreach ($targetIds as $position => $id) {
                $db->query('UPDATE {cards} SET position = ? WHERE id = ?', [$position, $id]);
            }

            if ($sourceColumn !== $targetColumn) {
                $sourceIds = array_column($db->all('SELECT id FROM {cards} WHERE column_id = ? ORDER BY position, id', [$sourceColumn]), 'id');
                foreach ($sourceIds as $position => $id) {
                    $db->query('UPDATE {cards} SET position = ? WHERE id = ?', [$position, $id]);
                }
            }
        });
        $this->touchBoard($boardId);

        return $db->one('SELECT ' . self::FIELDS . ' FROM {cards} WHERE id = ?', [$cardId]);
    }

    public function destroy(Request $request, array $params): Response
    {
        $boardId = BoardAccess::boardIdForCard($params['id']);
        BoardAccess::require($boardId, $request->userId(), BoardAccess::EDITOR);
        Database::get()->query('DELETE FROM {cards} WHERE id = ?', [$params['id']]);
        $this->touchBoard($boardId);
        return Response::noContent();
    }

    private function assertAssignee(int $boardId, ?int $assigneeId): void
    {
        if ($assigneeId !== null && BoardAccess::role($boardId, $assigneeId) === null) {
            throw HttpException::validation(['assignee_id' => 'not_a_member']);
        }
    }

    private function touchBoard(int $boardId): void
    {
        Database::get()->query('UPDATE {boards} SET updated_at = UTC_TIMESTAMP() WHERE id = ?', [$boardId]);
    }
}
