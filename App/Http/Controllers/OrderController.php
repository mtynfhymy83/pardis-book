<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\Services\OrderService;
use Swoole\Http\Request;

final class OrderController extends Controller
{
    public function __construct(private OrderService $orders) {}
    public function list(Request $request): array { return $this->ok($this->orders->list($this->requireAuthUserId($request))); }
    public function detail(Request $request, string $orderId): array { return $this->ok($this->orders->detail($this->requireAuthUserId($request), $orderId)); }
    public function timeline(Request $request, string $orderId): array { return $this->ok($this->orders->timeline($this->requireAuthUserId($request), $orderId)); }
    public function invoice(Request $request, string $orderId): array { return $this->ok($this->orders->invoice($this->requireAuthUserId($request), $orderId)); }
    public function shipments(Request $request, string $orderId): array { return $this->ok($this->orders->shipments($this->requireAuthUserId($request), $orderId)); }
    public function tracking(Request $request, string $shipmentId): array { return $this->ok($this->orders->tracking($this->requireAuthUserId($request), $shipmentId)); }
    public function cancel(Request $request, string $orderId, array $data): array { return $this->ok($this->orders->cancel($this->requireAuthUserId($request), $orderId, isset($data['reason']) ? (string) $data['reason'] : null)); }
}
