<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\Services\BestSellingService;
use Swoole\Http\Request;

final class AdminBestSellingController extends Controller
{
    public function __construct(private BestSellingService $bestSelling) {}

    public function index(array $request = []): array
    {
        return $this->ok($this->bestSelling->adminItems((string) ($request['q'] ?? '')));
    }

    public function create(Request $request, array $data): array
    {
        return $this->created($this->bestSelling->create($this->requireAuthUserId($request), $data));
    }

    public function update(Request $request, string $itemId, array $data): array
    {
        return $this->ok($this->bestSelling->update($this->requireAuthUserId($request), $itemId, $data));
    }

    public function delete(Request $request, string $itemId): array
    {
        $this->bestSelling->delete($this->requireAuthUserId($request), $itemId);
        return $this->deleted('محصول از فهرست پرفروش‌ها حذف شد.');
    }
}
