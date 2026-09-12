<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Domain\Services\PricingEngine;
use App\Infrastructure\Database\DB;
use App\Shared\Exceptions\ApiException;
use App\Shared\Support\PersianText;

final class CatalogService
{
    private const PRODUCT_SUMMARY_SELECT = <<<'SQL'
        SELECT
            p.public_id AS product_id,
            p.slug,
            p.title,
            p.subtitle,
            se.public_id AS series_id,
            se.name AS series_name,
            pub.public_id AS publisher_id,
            pub.name AS publisher_name,
            sku.public_id AS sku_id,
            sku.minimum_order_quantity,
            sku.reference_unit_price,
            sku.status AS availability,
            sku.fast_dispatch,
            sku.attributes,
            COALESCE(price.unit_price, sku.reference_unit_price) AS starting_unit_price,
            COALESCE(stock.available_quantity, 0) AS available_quantity,
            media.url AS cover_url,
            media.alt AS cover_alt,
            COALESCE(sales.sales_quantity, 0) AS sales_quantity
        FROM products p
        LEFT JOIN series se ON se.id = p.series_id
        LEFT JOIN publishers pub ON pub.id = p.publisher_id
        LEFT JOIN LATERAL (
            SELECT s.*
            FROM skus s
            WHERE s.product_id = p.id AND s.status <> 'archived'
            ORDER BY s.id
            LIMIT 1
        ) sku ON true
        LEFT JOIN LATERAL (
            SELECT pt.unit_price
            FROM pricing_tiers pt
            WHERE pt.sku_id = sku.id
              AND pt.effective_from <= now()
              AND (pt.effective_to IS NULL OR pt.effective_to > now())
              AND pt.min_quantity <= sku.minimum_order_quantity
              AND (pt.max_quantity IS NULL OR pt.max_quantity >= sku.minimum_order_quantity)
            ORDER BY pt.min_quantity DESC
            LIMIT 1
        ) price ON true
        LEFT JOIN LATERAL (
            SELECT SUM(ib.on_hand - ib.reserved - ib.safety_stock)::int AS available_quantity
            FROM inventory_balances ib
            WHERE ib.sku_id = sku.id
        ) stock ON true
        LEFT JOIN LATERAL (
            SELECT pm.url, pm.alt
            FROM product_media pm
            WHERE pm.product_id = p.id
            ORDER BY pm.is_primary DESC, pm.sort_order, pm.id
            LIMIT 1
        ) media ON true
        LEFT JOIN LATERAL (
            SELECT SUM(ol.quantity)::int AS sales_quantity
            FROM order_lines ol
            JOIN orders o ON o.id = ol.order_id
            WHERE ol.sku_id = sku.id
              AND o.payment_status = 'paid'
              AND o.status NOT IN ('cancelled', 'refunded')
        ) sales ON true
        SQL;

    public function __construct(private PricingEngine $pricing)
    {
    }

    public function products(array $query): array
    {
        $page = max(1, (int) ($query['page'] ?? 1));
        $pageSize = min(60, max(1, (int) ($query['pageSize'] ?? 24)));
        $term = PersianText::normalize((string) ($query['q'] ?? ''));
        $where = "p.status = 'published'";
        $params = [];

        if ($term !== '') {
            $where .= ' AND (p.search_text ILIKE :search_text OR p.title ILIKE :search_title)';
            $params[':search_text'] = '%' . $term . '%';
            $params[':search_title'] = '%' . $term . '%';
        }

        $sort = (string) ($query['sort'] ?? 'newest');
        $orderBy = match ($sort) {
            'bestSelling' => 'sales_quantity DESC, p.created_at DESC',
            'unitPriceAsc' => 'starting_unit_price ASC NULLS LAST, p.created_at DESC',
            'fastDispatch' => 'sku.fast_dispatch DESC, p.created_at DESC',
            default => 'p.created_at DESC',
        };

        $params[':limit'] = $pageSize;
        $params[':offset'] = ($page - 1) * $pageSize;
        $rows = DB::fetchAll(
            self::PRODUCT_SUMMARY_SELECT . " WHERE {$where} ORDER BY {$orderBy} LIMIT :limit OFFSET :offset",
            $params
        );
        $countParams = array_diff_key($params, [':limit' => true, ':offset' => true]);
        $count = DB::fetch("SELECT count(*) AS count FROM products p WHERE {$where}", $countParams);

        return [
            'items' => array_map($this->mapProductSummary(...), $rows),
            'page' => $page,
            'pageSize' => $pageSize,
            'total' => (int) $count['count'],
        ];
    }

    public function product(string $slug): array
    {
        $product = DB::fetch(
            <<<'SQL'
                SELECT p.*, pub.public_id publisher_id, pub.name publisher_name,
                       se.public_id series_id, se.name series_name
                FROM products p
                LEFT JOIN publishers pub ON pub.id = p.publisher_id
                LEFT JOIN series se ON se.id = p.series_id
                WHERE p.slug = :slug AND p.status = 'published'
                SQL,
            [':slug' => $slug]
        );

        if (!$product) {
            throw new ApiException('PRODUCT_NOT_FOUND', 'محصول یافت نشد.', 404);
        }

        $product['skus'] = DB::fetchAll(
            'SELECT public_id id, code, isbn, attributes, minimum_order_quantity, reference_unit_price, fast_dispatch, status, pricing_version FROM skus WHERE product_id = :id',
            [':id' => $product['id']]
        );
        $product['media'] = DB::fetchAll(
            'SELECT public_id id, url, alt, is_primary, sort_order FROM product_media WHERE product_id = :id ORDER BY is_primary DESC, sort_order, id',
            [':id' => $product['id']]
        );
        unset($product['id']);

        return $product;
    }

    public function featuredSeries(int $limit = 8): array
    {
        $limit = min(24, max(1, $limit));
        $rows = DB::fetchAll(
            <<<'SQL'
                SELECT se.public_id id, se.slug, se.name,
                       COUNT(DISTINCT p.id)::int product_count,
                       COALESCE(
                           jsonb_agg(DISTINCT sku.attributes->>'level')
                               FILTER (WHERE sku.attributes->>'level' IS NOT NULL),
                           '[]'::jsonb
                       ) levels,
                       media.url cover_url,
                       media.alt cover_alt
                FROM series se
                LEFT JOIN products p ON p.series_id = se.id AND p.status = 'published'
                LEFT JOIN skus sku ON sku.product_id = p.id AND sku.status <> 'archived'
                LEFT JOIN LATERAL (
                    SELECT pm.url, pm.alt
                    FROM product_media pm
                    JOIN products cover_product ON cover_product.id = pm.product_id
                    WHERE cover_product.series_id = se.id AND cover_product.status = 'published'
                    ORDER BY pm.is_primary DESC, pm.sort_order, pm.id
                    LIMIT 1
                ) media ON true
                WHERE se.featured = true
                GROUP BY se.id, media.url, media.alt
                ORDER BY product_count DESC, se.name
                LIMIT :limit
                SQL,
            [':limit' => $limit]
        );

        return array_map(static function (array $row): array {
            return [
                'id' => $row['id'],
                'slug' => $row['slug'],
                'name' => $row['name'],
                'productCount' => (int) $row['product_count'],
                'levels' => self::decodeJsonArray($row['levels']),
                'cover' => self::mapCover($row['cover_url'], $row['cover_alt'], $row['name']),
            ];
        }, $rows);
    }

    public function bestSelling(int $limit = 12): array
    {
        return $this->productCollection(
            "p.status = 'published' AND sku.id IS NOT NULL",
            'sales_quantity DESC, p.created_at DESC',
            $limit
        );
    }

    public function fastDispatch(int $limit = 12): array
    {
        return $this->productCollection(
            "p.status = 'published' AND sku.fast_dispatch = true AND sku.status IN ('in_stock', 'limited') AND COALESCE(stock.available_quantity, 0) > 0",
            'sales_quantity DESC, p.created_at DESC',
            $limit
        );
    }

    public function searchSuggestions(string $query, int $limit = 10): array
    {
        $term = PersianText::normalize($query);
        $limit = min(10, max(1, $limit));
        if (mb_strlen($term) < 2) {
            return [];
        }

        return DB::fetchAll(
            <<<'SQL'
                SELECT type, id, slug, label, subtitle
                FROM (
                    SELECT DISTINCT 'product'::text type, p.public_id id, p.slug, p.title label,
                           COALESCE(p.subtitle, se.name, '') subtitle,
                           CASE WHEN p.search_text ILIKE :product_prefix THEN 0 ELSE 1 END rank
                    FROM products p
                    LEFT JOIN series se ON se.id = p.series_id
                    LEFT JOIN skus sku ON sku.product_id = p.id
                    WHERE p.status = 'published'
                      AND (p.search_text ILIKE :product_text OR p.title ILIKE :product_title OR sku.code ILIKE :sku_code OR sku.isbn ILIKE :isbn)
                    UNION ALL
                    SELECT 'series'::text type, se.public_id id, se.slug, se.name label,
                           'مجموعه کتاب'::text subtitle,
                           CASE WHEN se.name ILIKE :series_prefix THEN 0 ELSE 1 END rank
                    FROM series se
                    WHERE se.name ILIKE :series_name
                ) suggestions
                ORDER BY rank, label
                LIMIT :limit
                SQL,
            [
                ':product_prefix' => $term . '%',
                ':product_text' => '%' . $term . '%',
                ':product_title' => '%' . $term . '%',
                ':sku_code' => '%' . $term . '%',
                ':isbn' => '%' . $term . '%',
                ':series_prefix' => $term . '%',
                ':series_name' => '%' . $term . '%',
                ':limit' => $limit,
            ]
        );
    }

    public function quote(string $skuId, int $quantity): array
    {
        $sku = DB::fetch('SELECT * FROM skus WHERE public_id = :id', [':id' => $skuId]);
        if (!$sku) {
            throw new ApiException('SKU_NOT_FOUND', 'SKU یافت نشد.', 404);
        }

        $tiers = DB::fetchAll(
            'SELECT * FROM pricing_tiers WHERE sku_id = :id AND effective_from <= now() AND (effective_to IS NULL OR effective_to > now()) ORDER BY min_quantity',
            [':id' => $sku['id']]
        );
        $stock = DB::fetch(
            'SELECT COALESCE(sum(on_hand - reserved - safety_stock), 0) available FROM inventory_balances WHERE sku_id = :id',
            [':id' => $sku['id']]
        );

        return $this->pricing->quote($sku, $tiers, $quantity, (int) $stock['available']);
    }

    public function taxonomy(string $table): array
    {
        if (!in_array($table, ['series', 'publishers', 'categories'], true)) {
            return [];
        }

        return DB::fetchAll("SELECT public_id id, slug, name FROM {$table} ORDER BY name");
    }

    private function productCollection(string $where, string $orderBy, int $limit): array
    {
        $limit = min(60, max(1, $limit));
        $rows = DB::fetchAll(
            self::PRODUCT_SUMMARY_SELECT . " WHERE {$where} ORDER BY {$orderBy} LIMIT :limit",
            [':limit' => $limit]
        );

        return array_map($this->mapProductSummary(...), $rows);
    }

    private function mapProductSummary(array $row): array
    {
        return [
            'id' => $row['product_id'],
            'slug' => $row['slug'],
            'title' => $row['title'],
            'subtitle' => $row['subtitle'],
            'series' => $row['series_id'] ? ['id' => $row['series_id'], 'name' => $row['series_name']] : null,
            'publisher' => $row['publisher_id'] ? ['id' => $row['publisher_id'], 'name' => $row['publisher_name']] : null,
            'cover' => self::mapCover($row['cover_url'], $row['cover_alt'], $row['title']),
            'defaultSku' => $row['sku_id'] ? [
                'id' => $row['sku_id'],
                'attributes' => self::decodeJsonObject($row['attributes']),
                'minimumQuantity' => (int) $row['minimum_order_quantity'],
                'startingUnitPrice' => (int) $row['starting_unit_price'],
                'referenceUnitPrice' => (int) $row['reference_unit_price'],
                'availableQuantity' => max(0, (int) $row['available_quantity']),
                'availability' => $row['availability'],
                'fastDispatch' => (bool) $row['fast_dispatch'],
            ] : null,
        ];
    }

    private static function mapCover(mixed $url, mixed $alt, string $fallbackAlt): ?array
    {
        if (!$url) {
            return null;
        }

        return ['url' => (string) $url, 'alt' => $alt ? (string) $alt : $fallbackAlt];
    }

    private static function decodeJsonArray(mixed $value): array
    {
        if (is_array($value)) {
            return array_values($value);
        }
        $decoded = json_decode((string) $value, true);
        return is_array($decoded) ? array_values($decoded) : [];
    }

    private static function decodeJsonObject(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        $decoded = json_decode((string) $value, true);
        return is_array($decoded) ? $decoded : [];
    }
}
