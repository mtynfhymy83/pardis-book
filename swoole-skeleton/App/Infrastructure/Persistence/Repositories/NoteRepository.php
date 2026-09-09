<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Repositories;

use App\Domain\Contracts\Repositories\NoteRepositoryInterface;
use App\Infrastructure\Database\DB;

/**
 * Raw-SQL implementation using the DB facade. Parameters are always bound (never
 * interpolated) to prevent SQL injection.
 *
 * Note on create(): lastInsertId() works for SQLite/MySQL. For PostgreSQL use a
 * `RETURNING id` clause instead (see the commented variant below).
 */
class NoteRepository implements NoteRepositoryInterface
{
    public function all(int $limit = 50, int $offset = 0): array
    {
        return DB::fetchAll(
            'SELECT id, title, body, created_at FROM notes ORDER BY id DESC LIMIT :limit OFFSET :offset',
            [':limit' => $limit, ':offset' => $offset]
        );
    }

    public function count(): int
    {
        $row = DB::fetch('SELECT COUNT(*) AS c FROM notes');
        return (int) ($row['c'] ?? 0);
    }

    public function find(int $id): ?array
    {
        $row = DB::fetch('SELECT id, title, body, created_at FROM notes WHERE id = :id', [':id' => $id]);
        return $row ?: null;
    }

    public function create(string $title, string $body): int
    {
        $id = DB::execute(
            'INSERT INTO notes (title, body) VALUES (:title, :body)',
            [':title' => $title, ':body' => $body],
            returnLastInsertId: true
        );
        return (int) $id;

        // PostgreSQL variant:
        // $row = DB::fetch(
        //     'INSERT INTO notes (title, body) VALUES (:title, :body) RETURNING id',
        //     [':title' => $title, ':body' => $body]
        // );
        // return (int) $row['id'];
    }

    public function delete(int $id): bool
    {
        return DB::execute('DELETE FROM notes WHERE id = :id', [':id' => $id]) > 0;
    }
}
