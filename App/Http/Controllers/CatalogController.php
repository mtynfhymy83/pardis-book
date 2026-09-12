<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\Services\CatalogService;

final class CatalogController extends Controller
{
    public function __construct(private CatalogService $catalog)
    {
    }

    public function products(array $request): array
    {
        $result = $this->catalog->products($request);
        return $this->paginated($result['items'], $result['total'], $result['page'], $result['pageSize']);
    }

    public function product(string $productSlug): array
    {
        return $this->ok($this->catalog->product($productSlug));
    }

    public function pricing(string $skuId): array
    {
        return $this->ok($this->catalog->quote($skuId, 5));
    }

    public function quote(array $data): array
    {
        return $this->ok($this->catalog->quote((string) ($data['skuId'] ?? ''), (int) ($data['quantity'] ?? 0)));
    }

    public function series(): array
    {
        return $this->ok($this->catalog->taxonomy('series'));
    }

    public function featuredSeries(array $request): array
    {
        return $this->ok($this->catalog->featuredSeries((int) ($request['limit'] ?? 8)));
    }

    public function bestSelling(array $request): array
    {
        return $this->ok($this->catalog->bestSelling((int) ($request['limit'] ?? 12)));
    }

    public function fastDispatch(array $request): array
    {
        return $this->ok($this->catalog->fastDispatch((int) ($request['limit'] ?? 12)));
    }

    public function searchSuggestions(array $request): array
    {
        return $this->ok($this->catalog->searchSuggestions(
            (string) ($request['q'] ?? ''),
            (int) ($request['limit'] ?? 10)
        ));
    }

    public function publishers(): array
    {
        return $this->ok($this->catalog->taxonomy('publishers'));
    }

    public function categories(): array
    {
        return $this->ok($this->catalog->taxonomy('categories'));
    }
}
