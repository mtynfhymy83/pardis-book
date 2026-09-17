<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Domain\Contracts\Providers\ObjectStorageInterface;
use App\Infrastructure\Database\DB;
use App\Shared\Exceptions\ApiException;
use App\Shared\Support\Id;
use PDO;

final class SupportService
{
    public function __construct(private ObjectStorageInterface $storage) {}
    public function categories(): array { return DB::fetchAll('SELECT id,title FROM support_categories ORDER BY title'); }
    public function list(int $userId): array { return DB::fetchAll('SELECT public_id id,category_id AS "categoryId",title,status,created_at AS "createdAt",updated_at AS "updatedAt" FROM support_tickets WHERE user_id=:user ORDER BY id DESC', [':user' => $userId]); }

    public function create(int $userId, array $data): array
    {
        $category = (string) ($data['categoryId'] ?? ''); $title = trim((string) ($data['title'] ?? '')); $body = trim((string) ($data['description'] ?? ''));
        if ($category === '' || $title === '' || $body === '') throw new ApiException('VALIDATION_FAILED', 'دسته‌بندی، عنوان و متن تیکت الزامی هستند.', 422);
        return DB::transaction(function (PDO $pdo) use ($userId, $data, $category, $title, $body): array {
            $exists = $pdo->prepare('SELECT 1 FROM support_categories WHERE id=:id'); $exists->execute([':id' => $category]); if (!$exists->fetchColumn()) throw new ApiException('SUPPORT_CATEGORY_NOT_FOUND', 'دسته‌بندی پشتیبانی یافت نشد.', 404);
            $orderId = null;
            if (!empty($data['orderId'])) {$order = $pdo->prepare('SELECT id FROM orders WHERE public_id=:order AND user_id=:user'); $order->execute([':order' => $data['orderId'], ':user' => $userId]); $orderId = $order->fetchColumn(); if (!$orderId) throw new ApiException('ORDER_NOT_FOUND', 'سفارش یافت نشد.', 404);}
            $ticketId = Id::make('tkt'); $insert = $pdo->prepare('INSERT INTO support_tickets(public_id,user_id,category_id,order_id,title,status) VALUES(:id,:user,:category,:order,:title,\'new\') RETURNING id');
            $insert->execute([':id' => $ticketId, ':user' => $userId, ':category' => $category, ':order' => $orderId ?: null, ':title' => $title]); $internal = (int) $insert->fetchColumn();
            $pdo->prepare('INSERT INTO support_messages(public_id,ticket_id,sender_id,body,is_staff) VALUES(:id,:ticket,:sender,:body,false)')->execute([':id' => Id::make('msg'), ':ticket' => $internal, ':sender' => $userId, ':body' => $body]);
            return ['id' => $ticketId, 'status' => 'new'];
        });
    }

    public function detail(int $userId, string $ticketId): array
    {
        $ticket = $this->owned($userId, $ticketId);
        return $this->shape($ticket);
    }

    public function message(int $userId, string $ticketId, string $body): array
    {
        $body = trim($body); if ($body === '') throw new ApiException('VALIDATION_FAILED', 'متن پیام الزامی است.', 422);
        return DB::transaction(function (PDO $pdo) use ($userId, $ticketId, $body): array {
            $ticket = $this->lockOwned($pdo, $userId, $ticketId); if ($ticket['status'] === 'closed') throw new ApiException('TICKET_CLOSED', 'تیکت بسته است.', 409);
            $id = Id::make('msg'); $pdo->prepare('INSERT INTO support_messages(public_id,ticket_id,sender_id,body,is_staff) VALUES(:id,:ticket,:sender,:body,false)')->execute([':id' => $id, ':ticket' => $ticket['id'], ':sender' => $userId, ':body' => $body]);
            $pdo->prepare("UPDATE support_tickets SET status='in_review',updated_at=now() WHERE id=:id")->execute([':id' => $ticket['id']]); return ['id' => $id, 'status' => 'in_review'];
        });
    }

    public function close(int $userId, string $ticketId): array { DB::execute("UPDATE support_tickets SET status='closed',updated_at=now() WHERE public_id=:ticket AND user_id=:user AND status<>'closed'", [':ticket' => $ticketId, ':user' => $userId]); return ['id' => $ticketId, 'status' => 'closed']; }

    public function presignUpload(int $userId, array $data): array
    {
        $name = (string) ($data['fileName'] ?? ''); $mime = strtolower((string) ($data['mime'] ?? '')); $size = (int) ($data['sizeBytes'] ?? 0);
        $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        if ($size < 1 || $size > 5 * 1024 * 1024) throw new ApiException('UPLOAD_TOO_LARGE', 'حجم فایل باید حداکثر ۵ مگابایت باشد.', 422);
        if (!isset($allowed[$mime])) throw new ApiException('UPLOAD_TYPE_NOT_ALLOWED', 'فقط تصویر JPG، PNG یا WebP مجاز است.', 422);
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION)); if ($extension !== $allowed[$mime]) throw new ApiException('UPLOAD_TYPE_NOT_ALLOWED', 'پسوند فایل با MIME مطابقت ندارد.', 422);
        $id = Id::make('upl'); $key = 'private/support/' . $userId . '/' . $id . '.' . $extension;
        $signed = $this->storage->presignPut($key, $mime);
        DB::execute("INSERT INTO uploads(public_id,owner_id,storage_key,mime,size_bytes,status) VALUES(:id,:owner,:key,:mime,:size,'pending')", [':id' => $id, ':owner' => $userId, ':key' => $key, ':mime' => $mime, ':size' => $size]);
        return ['uploadId' => $id, 'storage' => $signed];
    }

    private function owned(int $userId, string $ticketId): array { $ticket = DB::fetch('SELECT * FROM support_tickets WHERE public_id=:ticket AND user_id=:user', [':ticket' => $ticketId, ':user' => $userId]); if (!$ticket) throw new ApiException('SUPPORT_TICKET_NOT_FOUND', 'تیکت یافت نشد.', 404); return $ticket; }
    private function lockOwned(PDO $pdo, int $userId, string $ticketId): array { $stmt = $pdo->prepare('SELECT * FROM support_tickets WHERE public_id=:ticket AND user_id=:user FOR UPDATE'); $stmt->execute([':ticket' => $ticketId, ':user' => $userId]); $ticket = $stmt->fetch(); if (!$ticket) throw new ApiException('SUPPORT_TICKET_NOT_FOUND', 'تیکت یافت نشد.', 404); return $ticket; }
    private function shape(array $ticket): array { $messages = DB::fetchAll('SELECT public_id id,body,is_staff AS "isStaff",created_at AS "createdAt" FROM support_messages WHERE ticket_id=:ticket ORDER BY id', [':ticket' => $ticket['id']]); return ['id' => $ticket['public_id'], 'categoryId' => $ticket['category_id'], 'title' => $ticket['title'], 'status' => $ticket['status'], 'createdAt' => $ticket['created_at'], 'messages' => $messages]; }
}
