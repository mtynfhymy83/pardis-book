<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\Services\SupportService;
use Swoole\Http\Request;

final class SupportController extends Controller
{
    public function __construct(private SupportService $support) {}
    public function categories(): array { return $this->ok($this->support->categories()); }
    public function list(Request $request): array { return $this->ok($this->support->list($this->requireAuthUserId($request))); }
    public function create(Request $request, array $data): array { return $this->created($this->support->create($this->requireAuthUserId($request), $data)); }
    public function detail(Request $request, string $ticketId): array { return $this->ok($this->support->detail($this->requireAuthUserId($request), $ticketId)); }
    public function message(Request $request, string $ticketId, array $data): array { return $this->created($this->support->message($this->requireAuthUserId($request), $ticketId, (string) ($data['body'] ?? ''))); }
    public function close(Request $request, string $ticketId): array { return $this->ok($this->support->close($this->requireAuthUserId($request), $ticketId)); }
    public function upload(Request $request, array $data): array { return $this->created($this->support->presignUpload($this->requireAuthUserId($request), $data)); }
}
