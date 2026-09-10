<?php

declare(strict_types=1);

namespace App\Domain\Services;

use App\Domain\Contracts\Repositories\NoteRepositoryInterface;
use App\Domain\Contracts\Services\NoteServiceInterface;
use App\Shared\Exceptions\NotFoundException;
use App\Shared\Exceptions\ValidationException;

/**
 * Business logic for notes. Depends only on the repository INTERFACE, so it
 * knows nothing about SQL, PDO or HTTP. This is the layer you unit-test.
 */
class NoteService implements NoteServiceInterface
{
    public function __construct(private NoteRepositoryInterface $notes)
    {
    }

    public function list(int $page, int $perPage): array
    {
        $page = max(1, $page);
        $perPage = min(100, max(1, $perPage));
        $offset = ($page - 1) * $perPage;

        return [
            'items' => $this->notes->all($perPage, $offset),
            'total' => $this->notes->count(),
            'page'  => $page,
            'per_page' => $perPage,
        ];
    }

    public function get(int $id): array
    {
        $note = $this->notes->find($id);
        if ($note === null) {
            throw new NotFoundException('Note not found.');
        }
        return $note;
    }

    public function create(array $input): array
    {
        $title = trim((string) ($input['title'] ?? ''));
        $body = trim((string) ($input['body'] ?? ''));

        $errors = [];
        if ($title === '') {
            $errors['title'] = 'Title is required.';
        } elseif (mb_strlen($title) > 200) {
            $errors['title'] = 'Title must be at most 200 characters.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $id = $this->notes->create($title, $body);
        return $this->get($id);
    }

    public function remove(int $id): void
    {
        if (!$this->notes->delete($id)) {
            throw new NotFoundException('Note not found.');
        }
    }
}
