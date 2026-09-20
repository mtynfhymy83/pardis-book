<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Application\Validation\BestSellingItemValidator;
use App\Infrastructure\Database\DB;
use App\Shared\Exceptions\ApiException;
use App\Shared\Support\Id;
use App\Shared\Support\PersianText;
use PDO;

final class BestSellingService
{
    private const COLUMNS = <<<'SQL'
        public_id AS id, title, cover_url AS "coverUrl", cover_alt AS "coverAlt",
        price, discounted_price AS "discountedPrice", discount_percent AS "discountPercent",
        remaining_percent AS "remainingPercent", sort_order AS "sortOrder", active,
        created_at AS "createdAt", updated_at AS "updatedAt"
        SQL;

    public function __construct(private AuditService $audit) {}

    public function publicItems(int $limit = 12): array
    {
        $rows = DB::fetchAll(
            'SELECT ' . self::COLUMNS . ' FROM best_selling_products WHERE active=true ORDER BY sort_order,id LIMIT :limit',
            [':limit' => min(60, max(1, $limit))]
        );
        return array_map(self::mapPublicRow(...), $rows);
    }

    public function searchPublic(string $query, int $limit = 12): array
    {
        $term = PersianText::normalize($query);
        if (mb_strlen($term) < 2) {
            throw new ApiException('VALIDATION_FAILED', 'عبارت جست‌وجو باید حداقل ۲ کاراکتر باشد.', 422, [
                'q' => 'حداقل ۲ کاراکتر وارد کنید.',
            ]);
        }

        $rows = DB::fetchAll(
            'SELECT ' . self::COLUMNS . ' FROM best_selling_products WHERE active=true AND title ILIKE :query ORDER BY sort_order,id LIMIT :limit',
            [':query' => '%' . $term . '%', ':limit' => min(60, max(1, $limit))]
        );
        return array_map(self::mapPublicRow(...), $rows);
    }

    public function adminItems(string $query = ''): array
    {
        $term = PersianText::normalize($query);
        $where = $term === '' ? '' : ' WHERE title ILIKE :query';
        $params = $term === '' ? [] : [':query' => '%' . $term . '%'];
        return array_map(self::mapRow(...), DB::fetchAll(
            'SELECT ' . self::COLUMNS . ' FROM best_selling_products' . $where . ' ORDER BY sort_order,id',
            $params
        ));
    }

    public function create(int $actorId, array $data): array
    {
        $item = BestSellingItemValidator::normalize($data);
        return DB::transaction(function (PDO $pdo) use ($actorId, $item): array {
            $id = Id::make('bsp');
            $statement = $pdo->prepare(<<<'SQL'
                INSERT INTO best_selling_products(
                    public_id,title,cover_url,cover_alt,price,discounted_price,
                    discount_percent,remaining_percent,sort_order,active
                ) VALUES(:id,:title,:coverUrl,:coverAlt,:price,:discountedPrice,
                    :discountPercent,:remainingPercent,:sortOrder,:active)
                SQL);
            $statement->execute([':id' => $id] + self::parameters($item));
            $created = ['id' => $id] + $item;
            $this->audit->record($pdo, $actorId, 'best_selling.create', 'best_selling_product', $id, null, $created);
            return $created;
        });
    }

    public function update(int $actorId, string $id, array $data): array
    {
        return DB::transaction(function (PDO $pdo) use ($actorId, $id, $data): array {
            $statement = $pdo->prepare('SELECT ' . self::COLUMNS . ' FROM best_selling_products WHERE public_id=:id FOR UPDATE');
            $statement->execute([':id' => $id]);
            $row = $statement->fetch();
            if (!$row) throw new ApiException('BEST_SELLING_PRODUCT_NOT_FOUND', 'محصول پرفروش یافت نشد.', 404);
            $before = self::mapRow($row);
            $item = BestSellingItemValidator::normalize($data, $before);
            $pdo->prepare(<<<'SQL'
                UPDATE best_selling_products SET
                    title=:title,cover_url=:coverUrl,cover_alt=:coverAlt,price=:price,
                    discounted_price=:discountedPrice,discount_percent=:discountPercent,
                    remaining_percent=:remainingPercent,sort_order=:sortOrder,active=:active,updated_at=now()
                WHERE public_id=:id
                SQL)->execute([':id' => $id] + self::parameters($item));
            $updated = ['id' => $id] + $item;
            $this->audit->record($pdo, $actorId, 'best_selling.update', 'best_selling_product', $id, $before, $updated);
            return $updated;
        });
    }

    public function delete(int $actorId, string $id): void
    {
        DB::transaction(function (PDO $pdo) use ($actorId, $id): void {
            $statement = $pdo->prepare('SELECT ' . self::COLUMNS . ' FROM best_selling_products WHERE public_id=:id FOR UPDATE');
            $statement->execute([':id' => $id]);
            $row = $statement->fetch();
            if (!$row) throw new ApiException('BEST_SELLING_PRODUCT_NOT_FOUND', 'محصول پرفروش یافت نشد.', 404);
            $before = self::mapRow($row);
            $pdo->prepare('DELETE FROM best_selling_products WHERE public_id=:id')->execute([':id' => $id]);
            $this->audit->record($pdo, $actorId, 'best_selling.delete', 'best_selling_product', $id, $before, null);
        });
    }

    private static function parameters(array $item): array
    {
        return [
            ':title' => $item['title'], ':coverUrl' => $item['coverUrl'], ':coverAlt' => $item['coverAlt'],
            ':price' => $item['price'], ':discountedPrice' => $item['discountedPrice'],
            ':discountPercent' => $item['discountPercent'], ':remainingPercent' => $item['remainingPercent'],
            ':sortOrder' => $item['sortOrder'], ':active' => $item['active'],
        ];
    }

    private static function mapRow(array $row): array
    {
        foreach (['price', 'discountedPrice', 'discountPercent', 'remainingPercent', 'sortOrder'] as $field) {
            $row[$field] = (int) $row[$field];
        }
        $row['active'] = filter_var($row['active'], FILTER_VALIDATE_BOOL);
        return $row;
    }

    private static function mapPublicRow(array $row): array
    {
        $item = self::mapRow($row);
        return [
            'id' => $item['id'],
            'title' => $item['title'],
            'coverUrl' => $item['coverUrl'],
            'coverAlt' => $item['coverAlt'],
            'price' => $item['price'],
            'discountedPrice' => $item['discountedPrice'],
            'discountPercent' => $item['discountPercent'],
            'remainingPercent' => $item['remainingPercent'],
        ];
    }
}
