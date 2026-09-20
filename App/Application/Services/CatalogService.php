<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Application\Queries\CatalogCriteria;
use App\Domain\Services\PricingEngine;
use App\Infrastructure\Database\DB;
use App\Shared\Exceptions\ApiException;
use App\Shared\Support\PersianText;

final class CatalogService
{
    private const SUMMARY_FROM = <<<'SQL'
        FROM products p
        LEFT JOIN series se ON se.id = p.series_id
        LEFT JOIN publishers pub ON pub.id = p.publisher_id
        LEFT JOIN categories cat ON cat.id = p.category_id
        LEFT JOIN LATERAL (
            SELECT s.*
            FROM skus s
            WHERE s.product_id = p.id AND s.status <> 'archived'
            ORDER BY s.is_default DESC, s.id
            LIMIT 1
        ) sku ON true
        LEFT JOIN LATERAL (
            SELECT pt.unit_price
            FROM pricing_tiers pt
            WHERE pt.sku_id = sku.id AND pt.effective_from <= now()
              AND (pt.effective_to IS NULL OR pt.effective_to > now())
              AND pt.min_quantity <= sku.minimum_order_quantity
              AND (pt.max_quantity IS NULL OR pt.max_quantity >= sku.minimum_order_quantity)
            ORDER BY pt.min_quantity DESC
            LIMIT 1
        ) price ON true
        LEFT JOIN LATERAL (
            SELECT COALESCE(SUM(ib.on_hand - ib.reserved - ib.safety_stock), 0)::int AS available_quantity
            FROM inventory_balances ib WHERE ib.sku_id = sku.id
        ) stock ON true
        LEFT JOIN LATERAL (
            SELECT pm.url, pm.alt FROM product_media pm
            WHERE pm.product_id = p.id
            ORDER BY pm.is_primary DESC, pm.sort_order, pm.id LIMIT 1
        ) media ON true
        LEFT JOIN LATERAL (
            SELECT COALESCE(SUM(ol.quantity), 0)::int AS sales_quantity
            FROM order_lines ol JOIN orders o ON o.id = ol.order_id
            WHERE ol.sku_id = sku.id AND o.payment_status = 'paid'
              AND o.status NOT IN ('cancelled', 'refunded')
        ) sales ON true
        SQL;

    private const SUMMARY_COLUMNS = <<<'SQL'
        p.public_id AS product_id, p.slug, p.title, p.subtitle,
        se.public_id AS series_id, se.name AS series_name,
        pub.public_id AS publisher_id, pub.name AS publisher_name,
        sku.public_id AS sku_id, sku.minimum_order_quantity, sku.reference_unit_price,
        sku.status AS availability, sku.fast_dispatch, sku.attributes,
        COALESCE(price.unit_price, sku.reference_unit_price) AS starting_unit_price,
        COALESCE(stock.available_quantity, 0) AS available_quantity,
        media.url AS cover_url, media.alt AS cover_alt,
        COALESCE(sales.sales_quantity, 0) AS sales_quantity
        SQL;

    public function __construct(private PricingEngine $pricing, private BestSellingService $bestSelling)
    {
    }

    public function products(array $query): array
    {
        return $this->productsByCriteria(CatalogCriteria::from($query));
    }

    public function search(array $query): array
    {
        $query['sort'] = $query['sort'] ?? 'bestSelling';
        return $this->productsByCriteria(CatalogCriteria::from($query));
    }

    public function product(string $slug): array
    {
        $product = DB::fetch(
            <<<'SQL'
                SELECT p.id, p.public_id, p.slug, p.title, p.subtitle, p.description,
                       pub.public_id publisher_id, pub.name publisher_name,
                       se.public_id series_id, se.name series_name,
                       cat.public_id category_id, cat.name category_name
                FROM products p
                LEFT JOIN publishers pub ON pub.id = p.publisher_id
                LEFT JOIN series se ON se.id = p.series_id
                LEFT JOIN categories cat ON cat.id = p.category_id
                WHERE p.slug = :slug AND p.status = 'published'
                SQL,
            [':slug' => $slug]
        );
        if (!$product) {
            throw new ApiException('PRODUCT_NOT_FOUND', 'محصول یافت نشد.', 404);
        }

        $skus = DB::fetchAll(
            <<<'SQL'
                SELECT s.public_id id, s.code, s.isbn, s.attributes, s.minimum_order_quantity,
                       s.reference_unit_price, s.fast_dispatch, s.status, s.pricing_version, s.is_default,
                       COALESCE(stock.available_quantity, 0)::int available_quantity,
                       COALESCE(tiers.items, '[]'::jsonb) pricing_tiers
                FROM skus s
                LEFT JOIN LATERAL (
                    SELECT SUM(on_hand - reserved - safety_stock)::int available_quantity
                    FROM inventory_balances WHERE sku_id = s.id
                ) stock ON true
                LEFT JOIN LATERAL (
                    SELECT jsonb_agg(jsonb_build_object(
                        'minQuantity', pt.min_quantity, 'maxQuantity', pt.max_quantity, 'unitPrice', pt.unit_price
                    ) ORDER BY pt.min_quantity) items
                    FROM pricing_tiers pt
                    WHERE pt.sku_id = s.id AND pt.effective_from <= now()
                      AND (pt.effective_to IS NULL OR pt.effective_to > now())
                ) tiers ON true
                WHERE s.product_id = :id AND s.status <> 'archived'
                ORDER BY s.is_default DESC, s.id
                SQL,
            [':id' => $product['id']]
        );
        $media = DB::fetchAll(
            'SELECT public_id id, url, alt, is_primary AS "isPrimary", sort_order AS "sortOrder" FROM product_media WHERE product_id=:id ORDER BY is_primary DESC, sort_order, id',
            [':id' => $product['id']]
        );

        return [
            'id' => $product['public_id'], 'slug' => $product['slug'], 'title' => $product['title'],
            'subtitle' => $product['subtitle'], 'description' => $product['description'],
            'series' => $product['series_id'] ? ['id' => $product['series_id'], 'name' => $product['series_name']] : null,
            'publisher' => $product['publisher_id'] ? ['id' => $product['publisher_id'], 'name' => $product['publisher_name']] : null,
            'category' => $product['category_id'] ? ['id' => $product['category_id'], 'name' => $product['category_name']] : null,
            'media' => $media,
            'skus' => array_map(static function (array $sku): array {
                $sku['attributes'] = self::decodeJsonObject($sku['attributes']);
                $sku['pricingTiers'] = self::decodeJsonArray($sku['pricing_tiers']);
                unset($sku['pricing_tiers']);
                $sku['availableQuantity'] = max(0, (int) $sku['available_quantity']);
                unset($sku['available_quantity']);
                $sku['minimumQuantity'] = (int) $sku['minimum_order_quantity']; unset($sku['minimum_order_quantity']);
                $sku['referenceUnitPrice'] = (int) $sku['reference_unit_price']; unset($sku['reference_unit_price']);
                $sku['pricingVersion'] = 'pv_' . $sku['pricing_version']; unset($sku['pricing_version']);
                $sku['fastDispatch'] = (bool) $sku['fast_dispatch']; unset($sku['fast_dispatch']);
                $sku['isDefault'] = (bool) $sku['is_default']; unset($sku['is_default']);
                return $sku;
            }, $skus),
        ];
    }

    public function related(string $slug, int $limit): array
    {
        $source = DB::fetch('SELECT id,series_id,category_id FROM products WHERE slug=:slug AND status=\'published\'', [':slug' => $slug]);
        if (!$source) {
            throw new ApiException('PRODUCT_NOT_FOUND', 'محصول یافت نشد.', 404);
        }
        $limit = min(24, max(1, $limit));
        $params = [':source' => $source['id'], ':limit' => $limit];
        $where = "p.status='published' AND p.id<>:source";
        if ($source['series_id'] !== null) {
            $where .= ' AND (p.series_id=:series OR p.category_id=:category)';
            $params[':series'] = $source['series_id']; $params[':category'] = $source['category_id'];
        } elseif ($source['category_id'] !== null) {
            $where .= ' AND p.category_id=:category'; $params[':category'] = $source['category_id'];
        }
        return $this->summaryCollection($where, $params, 'sales_quantity DESC, p.created_at DESC, p.id DESC');
    }

    public function seriesDetail(string $slug, array $query): array
    {
        $series = DB::fetch('SELECT public_id id,slug,name FROM series WHERE slug=:slug', [':slug' => $slug]);
        if (!$series) {
            throw new ApiException('PRODUCT_NOT_FOUND', 'مجموعه یافت نشد.', 404);
        }
        $query['filter'] = is_array($query['filter'] ?? null) ? $query['filter'] : [];
        $query['filter']['seriesId'] = $series['id'];
        return ['series' => $series, 'products' => $this->productsByCriteria(CatalogCriteria::from($query))];
    }

    public function featuredSeries(int $limit = 8): array
    {
        $limit = min(24, max(1, $limit));
        $rows = DB::fetchAll(
            <<<'SQL'
                SELECT se.public_id id, se.slug, se.name, COUNT(DISTINCT p.id)::int product_count,
                       COALESCE(jsonb_agg(DISTINCT sku.attributes->>'level') FILTER (WHERE sku.attributes->>'level' IS NOT NULL), '[]'::jsonb) levels,
                       media.url cover_url, media.alt cover_alt
                FROM series se
                LEFT JOIN products p ON p.series_id=se.id AND p.status='published'
                LEFT JOIN skus sku ON sku.product_id=p.id AND sku.status<>'archived'
                LEFT JOIN LATERAL (
                    SELECT pm.url,pm.alt FROM product_media pm JOIN products cp ON cp.id=pm.product_id
                    WHERE cp.series_id=se.id AND cp.status='published'
                    ORDER BY pm.is_primary DESC,pm.sort_order,pm.id LIMIT 1
                ) media ON true
                WHERE se.featured=true GROUP BY se.id,media.url,media.alt
                ORDER BY product_count DESC,se.name LIMIT :limit
                SQL,
            [':limit' => $limit]
        );
        return array_map(static fn(array $row): array => [
            'id' => $row['id'], 'slug' => $row['slug'], 'name' => $row['name'], 'productCount' => (int) $row['product_count'],
            'levels' => self::decodeJsonArray($row['levels']), 'cover' => self::mapCover($row['cover_url'], $row['cover_alt'], $row['name']),
        ], $rows);
    }

    public function bestSelling(int $limit = 12): array { return $this->bestSelling->publicItems($limit); }
    public function searchBestSelling(string $query, int $limit = 12): array { return $this->bestSelling->searchPublic($query, $limit); }
    public function fastDispatch(int $limit = 12): array { return $this->summaryCollection("p.status='published' AND sku.fast_dispatch=true AND sku.status IN ('in_stock','limited') AND COALESCE(stock.available_quantity,0)>0", [':limit' => min(60, max(1, $limit))], 'sales_quantity DESC,p.created_at DESC,p.id DESC'); }

    public function searchSuggestions(string $query, int $limit = 10): array
    {
        $term = PersianText::normalize($query); $limit = min(10, max(1, $limit));
        if (mb_strlen($term) < 2) return [];
        return DB::fetchAll(
            <<<'SQL'
                SELECT type,id,slug,label,subtitle FROM (
                    SELECT 'product'::text type,p.public_id id,p.slug,p.title label,COALESCE(p.subtitle,se.name,'') subtitle,
                           CASE WHEN p.search_text ILIKE :prefix OR p.title ILIKE :prefix THEN 0 ELSE 1 END rank
                    FROM products p LEFT JOIN series se ON se.id=p.series_id
                    WHERE p.status='published' AND (
                        p.search_text ILIKE :term OR p.title ILIKE :term OR EXISTS (
                            SELECT 1 FROM skus s WHERE s.product_id=p.id AND (s.code ILIKE :term OR s.isbn ILIKE :term)
                        )
                    )
                    UNION ALL
                    SELECT 'series'::text,se.public_id,se.slug,se.name,'مجموعه کتاب'::text,
                           CASE WHEN se.name ILIKE :prefix THEN 0 ELSE 1 END FROM series se WHERE se.name ILIKE :term
                ) suggestions ORDER BY rank,label LIMIT :limit
                SQL,
            [':prefix' => $term . '%', ':term' => '%' . $term . '%', ':limit' => $limit]
        );
    }

    public function pricing(string $skuId): array
    {
        [$sku, $tiers] = $this->loadSkuPricing($skuId);
        return [
            'skuId' => $sku['public_id'], 'minimumQuantity' => (int) $sku['minimum_order_quantity'],
            'referenceUnitPrice' => (int) $sku['reference_unit_price'], 'availability' => $sku['status'],
            'maximumPurchasableQuantity' => max(0, (int) $sku['available_quantity']),
            'pricingVersion' => 'pv_' . $sku['pricing_version'],
            'tiers' => array_map(static fn(array $tier): array => ['minQuantity' => (int) $tier['min_quantity'], 'maxQuantity' => $tier['max_quantity'] === null ? null : (int) $tier['max_quantity'], 'unitPrice' => (int) $tier['unit_price']], $tiers),
        ];
    }

    public function quote(string $skuId, int $quantity): array
    {
        [$sku, $tiers] = $this->loadSkuPricing($skuId);
        return $this->pricing->quote($sku, $tiers, $quantity, max(0, (int) $sku['available_quantity']));
    }

    public function availability(array $items): array
    {
        if ($items === [] || count($items) > 50) throw new ApiException('VALIDATION_FAILED', 'تعداد اقلام باید بین ۱ تا ۵۰ باشد.', 422, ['items' => 'تعداد اقلام نامعتبر است.']);
        $ids = []; foreach ($items as $item) { $id = (string) ($item['skuId'] ?? ''); if ($id === '') throw new ApiException('VALIDATION_FAILED', 'شناسه SKU الزامی است.', 422, ['items' => 'skuId الزامی است.']); $ids[$id] = true; }
        $params = []; $placeholders = []; foreach (array_keys($ids) as $index => $id) { $key=':sku'.$index; $placeholders[]=$key; $params[$key]=$id; }
        $rows = DB::fetchAll("SELECT s.public_id sku_id,s.status,COALESCE(SUM(ib.on_hand-ib.reserved-ib.safety_stock),0)::int available_quantity,s.minimum_order_quantity FROM skus s LEFT JOIN inventory_balances ib ON ib.sku_id=s.id WHERE s.public_id IN (".implode(',',$placeholders).") GROUP BY s.id", $params);
        $found = []; foreach ($rows as $row) $found[$row['sku_id']]=$row;
        return array_map(static function (array $item) use ($found): array {
            $id=(string)$item['skuId']; $quantity=max(1,(int)($item['quantity']??1)); $row=$found[$id]??null;
            if(!$row) return ['skuId'=>$id,'availability'=>'not_found','maximumPurchasableQuantity'=>0,'canPurchase'=>false];
            $available=max(0,(int)$row['available_quantity']);
            return ['skuId'=>$id,'availability'=>$row['status'],'minimumQuantity'=>(int)$row['minimum_order_quantity'],'maximumPurchasableQuantity'=>$available,'canPurchase'=>in_array($row['status'],['in_stock','limited'],true)&&$quantity>=(int)$row['minimum_order_quantity']&&$quantity<=$available];
        }, $items);
    }

    public function facets(): array
    {
        $rows = DB::fetchAll(<<<'SQL'
            SELECT 'series' type,se.public_id id,se.name label,COUNT(p.id)::int count FROM series se JOIN products p ON p.series_id=se.id AND p.status='published' GROUP BY se.id
            UNION ALL SELECT 'publisher',pub.public_id,pub.name,COUNT(p.id)::int FROM publishers pub JOIN products p ON p.publisher_id=pub.id AND p.status='published' GROUP BY pub.id
            UNION ALL SELECT 'category',cat.public_id,cat.name,COUNT(p.id)::int FROM categories cat JOIN products p ON p.category_id=cat.id AND p.status='published' GROUP BY cat.id
            UNION ALL SELECT 'ageGroup',sku.attributes->>'ageGroup',sku.attributes->>'ageGroup',COUNT(DISTINCT p.id)::int FROM skus sku JOIN products p ON p.id=sku.product_id AND p.status='published' WHERE sku.status<>'archived' AND jsonb_exists(sku.attributes, 'ageGroup') GROUP BY sku.attributes->>'ageGroup'
            UNION ALL SELECT 'level',sku.attributes->>'level',sku.attributes->>'level',COUNT(DISTINCT p.id)::int FROM skus sku JOIN products p ON p.id=sku.product_id AND p.status='published' WHERE sku.status<>'archived' AND jsonb_exists(sku.attributes, 'level') GROUP BY sku.attributes->>'level'
            UNION ALL SELECT 'edition',sku.attributes->>'edition',sku.attributes->>'edition',COUNT(DISTINCT p.id)::int FROM skus sku JOIN products p ON p.id=sku.product_id AND p.status='published' WHERE sku.status<>'archived' AND jsonb_exists(sku.attributes, 'edition') GROUP BY sku.attributes->>'edition'
            UNION ALL SELECT 'bookType',sku.attributes->>'bookType',sku.attributes->>'bookType',COUNT(DISTINCT p.id)::int FROM skus sku JOIN products p ON p.id=sku.product_id AND p.status='published' WHERE sku.status<>'archived' AND jsonb_exists(sku.attributes, 'bookType') GROUP BY sku.attributes->>'bookType'
            SQL);
        $facets = ['series'=>[],'publisher'=>[],'category'=>[],'ageGroup'=>[],'level'=>[],'edition'=>[],'bookType'=>[]];
        foreach($rows as $row) $facets[$row['type']][]=['id'=>$row['id'],'label'=>$row['label'],'count'=>(int)$row['count']];
        return $facets;
    }

    public function taxonomy(string $table): array
    {
        if (!in_array($table, ['series','publishers','categories'], true)) return [];
        return DB::fetchAll("SELECT public_id id,slug,name FROM {$table} ORDER BY name");
    }

    private function productsByCriteria(CatalogCriteria $criteria): array
    {
        $params=[]; $where=$this->filters($criteria,$params);
        $params[':limit']=$criteria->pageSize; $params[':offset']=($criteria->page-1)*$criteria->pageSize;
        $sql='SELECT COUNT(*) OVER()::int total_count,'.self::SUMMARY_COLUMNS.' '.self::SUMMARY_FROM." WHERE {$where} ORDER BY ".$this->sort($criteria->sort).' LIMIT :limit OFFSET :offset';
        $rows=DB::fetchAll($sql,$params); $total=$rows===[] ? 0 : (int)$rows[0]['total_count'];
        return ['items'=>array_map($this->mapProductSummary(...),$rows),'page'=>$criteria->page,'pageSize'=>$criteria->pageSize,'total'=>$total];
    }

    private function summaryCollection(string $where,array $params,string $orderBy): array
    {
        $rows=DB::fetchAll('SELECT '.self::SUMMARY_COLUMNS.' '.self::SUMMARY_FROM." WHERE {$where} ORDER BY {$orderBy} LIMIT :limit",$params);
        return array_map($this->mapProductSummary(...),$rows);
    }

    private function filters(CatalogCriteria $criteria,array &$params): string
    {
        $where=["p.status='published'"];
        if($criteria->term!==''){ $where[]="(p.search_text ILIKE :term OR p.title ILIKE :term OR EXISTS(SELECT 1 FROM skus search_sku WHERE search_sku.product_id=p.id AND (search_sku.code ILIKE :term OR search_sku.isbn ILIKE :term)))"; $params[':term']='%'.$criteria->term.'%'; }
        foreach(['seriesId'=>['seriesId','se.public_id'],'categoryId'=>['categoryId','cat.public_id'],'publisherId'=>['publisherId','pub.public_id']] as $property=>[$param,$column]){if($criteria->$property!==null){$where[]="{$column}=:{$param}";$params[':'.$param]=$criteria->$property;}}
        foreach(['ageGroup'=>'ageGroup','level'=>'level','edition'=>'edition','bookType'=>'bookType'] as $property=>$attribute){if($criteria->$property!==null){$where[]="sku.attributes->>:key_{$attribute}=:value_{$attribute}";$params[":key_{$attribute}"]=$attribute;$params[":value_{$attribute}"]=$criteria->$property;}}
        if($criteria->inStock!==null) $where[]=$criteria->inStock?"sku.status IN ('in_stock','limited') AND COALESCE(stock.available_quantity,0)>0":"(sku.id IS NULL OR sku.status NOT IN ('in_stock','limited') OR COALESCE(stock.available_quantity,0)<=0)";
        if($criteria->fastDispatch!==null) $where[]=$criteria->fastDispatch?'sku.fast_dispatch=true':'COALESCE(sku.fast_dispatch,false)=false';
        if($criteria->minimumUnitPrice!==null){$where[]='COALESCE(price.unit_price,sku.reference_unit_price)>=:min_price';$params[':min_price']=$criteria->minimumUnitPrice;}
        if($criteria->maximumUnitPrice!==null){$where[]='COALESCE(price.unit_price,sku.reference_unit_price)<=:max_price';$params[':max_price']=$criteria->maximumUnitPrice;}
        return implode(' AND ',$where);
    }

    private function sort(string $sort): string
    {
        return match($sort){'bestSelling'=>'sales_quantity DESC,p.created_at DESC,p.id DESC','unitPriceAsc'=>'starting_unit_price ASC NULLS LAST,p.created_at DESC,p.id DESC','discountDesc'=>'(sku.reference_unit_price-COALESCE(price.unit_price,sku.reference_unit_price)) DESC,p.created_at DESC,p.id DESC','fastDispatch'=>'sku.fast_dispatch DESC,sales_quantity DESC,p.created_at DESC,p.id DESC',default=>'p.created_at DESC,p.id DESC'};
    }

    private function loadSkuPricing(string $skuId): array
    {
        $sku=DB::fetch("SELECT s.*,COALESCE(SUM(ib.on_hand-ib.reserved-ib.safety_stock),0)::int available_quantity FROM skus s LEFT JOIN inventory_balances ib ON ib.sku_id=s.id WHERE s.public_id=:id GROUP BY s.id",[':id'=>$skuId]);
        if(!$sku) throw new ApiException('SKU_NOT_FOUND','SKU یافت نشد.',404);
        if($sku['status']==='archived') throw new ApiException('SKU_ARCHIVED','SKU آرشیو شده است.',409);
        $tiers=DB::fetchAll('SELECT min_quantity,max_quantity,unit_price FROM pricing_tiers WHERE sku_id=:id AND effective_from<=now() AND (effective_to IS NULL OR effective_to>now()) ORDER BY min_quantity',[':id'=>$sku['id']]);
        return [$sku,$tiers];
    }

    private function mapProductSummary(array $row): array
    {
        return ['id'=>$row['product_id'],'slug'=>$row['slug'],'title'=>$row['title'],'subtitle'=>$row['subtitle'],'series'=>$row['series_id']?['id'=>$row['series_id'],'name'=>$row['series_name']]:null,'publisher'=>$row['publisher_id']?['id'=>$row['publisher_id'],'name'=>$row['publisher_name']]:null,'cover'=>self::mapCover($row['cover_url'],$row['cover_alt'],$row['title']),'defaultSku'=>$row['sku_id']?['id'=>$row['sku_id'],'attributes'=>self::decodeJsonObject($row['attributes']),'minimumQuantity'=>(int)$row['minimum_order_quantity'],'startingUnitPrice'=>(int)$row['starting_unit_price'],'referenceUnitPrice'=>(int)$row['reference_unit_price'],'maximumPurchasableQuantity'=>max(0,(int)$row['available_quantity']),'availability'=>$row['availability'],'fastDispatch'=>(bool)$row['fast_dispatch']]:null];
    }
    private static function mapCover(mixed $url,mixed $alt,string $fallbackAlt):?array{return $url?['url'=>(string)$url,'alt'=>$alt?(string)$alt:$fallbackAlt]:null;}
    private static function decodeJsonArray(mixed $value):array{if(is_array($value))return array_values($value);$decoded=json_decode((string)$value,true);return is_array($decoded)?array_values($decoded):[];}
    private static function decodeJsonObject(mixed $value):array{if(is_array($value))return $value;$decoded=json_decode((string)$value,true);return is_array($decoded)?$decoded:[];}
}
