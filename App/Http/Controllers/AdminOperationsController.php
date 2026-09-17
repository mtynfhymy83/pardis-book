<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\Services\AdminOperationsService;
use Swoole\Http\Request;

final class AdminOperationsController extends Controller
{
    public function __construct(private AdminOperationsService $admin) {}
    public function orders(Request $r, array $request): array { return $this->ok($this->admin->orders($request)); }
    public function transition(Request $r,string $orderId,array $data): array { return $this->ok($this->admin->transition($this->requireAuthUserId($r),$orderId,$data)); }
    public function note(Request $r,string $orderId,array $data): array { return $this->created($this->admin->note($this->requireAuthUserId($r),$orderId,(string)($data['body']??''))); }
    public function createShipment(Request $r,string $orderId,array $data): array { return $this->created($this->admin->createShipment($this->requireAuthUserId($r),$orderId,$data)); }
    public function dispatchShipment(Request $r,string $shipmentId): array { return $this->ok($this->admin->dispatchShipment($this->requireAuthUserId($r),$shipmentId)); }
    public function customers(Request $r, array $request): array { return $this->ok($this->admin->customers((string)($request['q']??''))); }
    public function payments(): array { return $this->ok($this->admin->payments()); }
    public function salesSummary(): array { return $this->ok($this->admin->salesSummary()); }
    public function inventoryRisk(): array { return $this->ok($this->admin->inventoryRisk()); }
    public function tickets(): array { return $this->ok($this->admin->tickets()); }
    public function ticket(string $ticketId): array { return $this->ok($this->admin->ticket($ticketId)); }
    public function ticketMessage(Request $r,string $ticketId,array $data): array { return $this->created($this->admin->ticketMessage($this->requireAuthUserId($r),$ticketId,(string)($data['body']??''))); }
    public function assignTicket(Request $r,string $ticketId,array $data): array { return $this->ok($this->admin->assignTicket($this->requireAuthUserId($r),$ticketId,(string)($data['assigneeId']??''))); }
    public function ticketStatus(Request $r,string $ticketId,array $data): array { return $this->ok($this->admin->ticketStatus($this->requireAuthUserId($r),$ticketId,(string)($data['status']??''))); }
}
