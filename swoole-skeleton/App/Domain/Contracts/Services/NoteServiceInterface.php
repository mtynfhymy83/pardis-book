<?php

declare(strict_types=1);

namespace App\Domain\Contracts\Services;

interface NoteServiceInterface
{
    public function list(int $page, int $perPage): array;

    public function get(int $id): array;

    public function create(array $input): array;

    public function remove(int $id): void;
}
