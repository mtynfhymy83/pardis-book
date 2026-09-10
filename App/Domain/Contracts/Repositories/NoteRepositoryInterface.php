<?php

declare(strict_types=1);

namespace App\Domain\Contracts\Repositories;

/**
 * Persistence contract for notes. The Domain owns this interface; the
 * Infrastructure layer provides the implementation (bound in di.php).
 */
interface NoteRepositoryInterface
{
    public function all(int $limit = 50, int $offset = 0): array;

    public function count(): int;

    public function find(int $id): ?array;

    public function create(string $title, string $body): int;

    public function delete(int $id): bool;
}
