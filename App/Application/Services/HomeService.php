<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Infrastructure\Database\DB;

final class HomeService
{
    public function __construct(private CatalogService $catalog)
    {
    }

    public function home(): array
    {
        $settings = DB::fetch("SELECT value FROM settings WHERE key = 'home'");
        $content = $this->decodeJsonObject($settings['value'] ?? null);

        return [
            'hero' => $content['hero'] ?? [],
            'benefits' => $content['benefits'] ?? [],
            'featuredSeries' => $this->catalog->featuredSeries(8),
            'bestSelling' => $this->catalog->bestSelling(12),
            'fastDispatch' => $this->catalog->fastDispatch(12),
            'wholesaleSteps' => $content['wholesaleSteps'] ?? [],
            'contact' => $content['contact'] ?? [],
        ];
    }

    public function navigation(): array
    {
        $rows = DB::fetchAll(
            <<<'SQL'
                SELECT c.id internal_id, c.parent_id parent_internal_id,
                       c.public_id id, c.slug, c.name,
                       COUNT(p.id)::int product_count
                FROM categories c
                LEFT JOIN products p ON p.category_id = c.id AND p.status = 'published'
                GROUP BY c.id
                ORDER BY c.parent_id NULLS FIRST, c.name
                SQL
        );

        $items = [];
        foreach ($rows as $row) {
            $items[(int) $row['internal_id']] = [
                'id' => $row['id'],
                'slug' => $row['slug'],
                'name' => $row['name'],
                'productCount' => (int) $row['product_count'],
                'children' => [],
            ];
        }

        $tree = [];
        foreach ($rows as $row) {
            $id = (int) $row['internal_id'];
            $parentId = $row['parent_internal_id'] === null ? null : (int) $row['parent_internal_id'];
            if ($parentId !== null && isset($items[$parentId])) {
                $items[$parentId]['children'][] = &$items[$id];
            } else {
                $tree[] = &$items[$id];
            }
        }

        return [
            'categories' => array_values($tree),
            'links' => [
                ['label' => 'مجموعه‌های محبوب', 'href' => '/series?featured=true'],
                ['label' => 'ارسال فوری', 'href' => '/products?fastDispatch=true'],
                ['label' => 'خرید عمده', 'href' => '/content/pages/wholesale-guide'],
                ['label' => 'درباره ما', 'href' => '/content/pages/about'],
                ['label' => 'تماس با ما', 'href' => '/content/pages/contact'],
            ],
        ];
    }

    private function decodeJsonObject(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        $decoded = json_decode((string) $value, true);
        return is_array($decoded) ? $decoded : [];
    }
}
