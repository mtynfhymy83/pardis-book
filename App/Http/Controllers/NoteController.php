<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Contracts\Services\NoteServiceInterface;
use Swoole\Http\Request;

/**
 * Full DB-backed vertical slice:
 *   Controller -> NoteServiceInterface -> NoteRepositoryInterface -> DB (pool)
 */
class NoteController extends Controller
{
    public function __construct(private NoteServiceInterface $notes)
    {
    }

    /** GET /v1/notes?page=1&per_page=20 */
    public function index(int $page = 1, int $per_page = 20): array
    {
        $result = $this->notes->list($page, $per_page);
        return $this->paginated($result['items'], $result['total'], $result['page'], $result['per_page']);
    }

    /** GET /v1/notes/{id} */
    public function show(int $id): array
    {
        return $this->ok($this->notes->get($id));
    }

    /** POST /v1/notes  body: { "title": "...", "body": "..." } */
    public function store(Request $request): array
    {
        $body = $this->getRequestBody($request);
        return $this->created($this->notes->create($body));
    }

    /** DELETE /v1/notes/{id} */
    public function destroy(int $id): array
    {
        $this->notes->remove($id);
        return $this->deleted();
    }
}
