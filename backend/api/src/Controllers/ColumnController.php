<?php

declare(strict_types=1);

namespace Kaneas\Controllers;

use Kaneas\Core\Database;
use Kaneas\Core\HttpException;
use Kaneas\Core\Request;
use Kaneas\Core\Response;
use Kaneas\Core\Validator;
use Kaneas\Services\BoardAccess;

final class ColumnController
{
    public function store(Request $request, array $params): Response
    {
        $boardId = $params['id'];
        BoardAccess::require($boardId, $request->userId(), BoardAccess::EDITOR);
        $data = Validator::validate($request->all(), ['name' => 'required|string|min:1|max:100']);

        $db = Database::get();
        $position = (int) $db->value('SELECT COALESCE(MAX(position) + 1, 0) FROM {board_columns} WHERE board_id = ?', [$boardId]);
        $id = $db->insert('INSERT INTO {board_columns} (board_id, name, position) VALUES (?, ?, ?)', [$boardId, $data['name'], $position]);
        $this->touchBoard($boardId);

        return Response::created(['id' => $id, 'name' => $data['name'], 'position' => $position, 'cards' => []]);
    }

    public function update(Request $request, array $params): array
    {
        $boardId = BoardAccess::boardIdForColumn($params['id']);
        BoardAccess::require($boardId, $request->userId(), BoardAccess::EDITOR);
        $data = Validator::validate($request->all(), ['name' => 'required|string|min:1|max:100']);

        Database::get()->query('UPDATE {board_columns} SET name = ? WHERE id = ?', [$data['name'], $params['id']]);
        $this->touchBoard($boardId);
        return Database::get()->one('SELECT id, name, position FROM {board_columns} WHERE id = ?', [$params['id']]);
    }

    /** Body: { "column_ids": [3, 1, 2] } — the complete new order. */
    public function reorder(Request $request, array $params): Response
    {
        $boardId = $params['id'];
        BoardAccess::require($boardId, $request->userId(), BoardAccess::EDITOR);
        $ids = $request->input('column_ids');
        if (!is_array($ids) || !array_is_list($ids)) {
            throw HttpException::validation(['column_ids' => 'required']);
        }
        $ids = array_map('intval', $ids);

        Database::get()->transaction(static function (Database $db) use ($boardId, $ids): void {
            $existing = array_map('intval', array_column(
                $db->all('SELECT id FROM {board_columns} WHERE board_id = ? FOR UPDATE', [$boardId]),
                'id',
            ));
            $sortedIds = $ids;
            sort($sortedIds);
            sort($existing);
            if ($sortedIds !== $existing) {
                throw HttpException::validation(['column_ids' => 'mismatch']);
            }
            foreach ($ids as $position => $id) {
                $db->query('UPDATE {board_columns} SET position = ? WHERE id = ?', [$position, $id]);
            }
        });
        $this->touchBoard($boardId);
        return Response::noContent();
    }

    public function destroy(Request $request, array $params): Response
    {
        $boardId = BoardAccess::boardIdForColumn($params['id']);
        BoardAccess::require($boardId, $request->userId(), BoardAccess::EDITOR);
        Database::get()->query('DELETE FROM {board_columns} WHERE id = ?', [$params['id']]);
        $this->touchBoard($boardId);
        return Response::noContent();
    }

    private function touchBoard(int $boardId): void
    {
        Database::get()->query('UPDATE {boards} SET updated_at = UTC_TIMESTAMP() WHERE id = ?', [$boardId]);
    }
}
