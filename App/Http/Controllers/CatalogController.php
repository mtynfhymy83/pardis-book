<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\Services\CatalogService;

final class CatalogController extends Controller
{
    public function __construct(private CatalogService $catalog)
    {
    }

    public function products(array $request): array { $result=$this->catalog->products($request); return $this->cached($this->paginated($result['items'],$result['total'],$result['page'],$result['pageSize']),60); }
    public function search(array $request): array { $result=$this->catalog->search($request); return $this->cached($this->paginated($result['items'],$result['total'],$result['page'],$result['pageSize']),30); }
    public function product(string $productSlug): array { return $this->cached($this->ok($this->catalog->product($productSlug)),300); }
    public function related(string $productSlug,int $limit=12): array { return $this->cached($this->ok($this->catalog->related($productSlug,$limit)),120); }
    public function pricing(string $skuId): array { return $this->cached($this->ok($this->catalog->pricing($skuId)),15); }
    public function quote(array $data): array { return $this->ok($this->catalog->quote((string)($data['skuId']??''),(int)($data['quantity']??0))); }
    public function availability(array $data): array { return $this->ok(['items'=>$this->catalog->availability(is_array($data['items']??null)?$data['items']:[])]); }
    public function series(array $request=[]): array { return $this->cached($this->ok($this->catalog->taxonomy('series')),300); }
    public function seriesDetail(string $seriesSlug,array $request): array { return $this->cached($this->ok($this->catalog->seriesDetail($seriesSlug,$request)),60); }
    public function featuredSeries(array $request): array { return $this->cached($this->ok($this->catalog->featuredSeries((int)($request['limit']??8))),120); }
    public function bestSelling(array $request): array { return $this->cached($this->ok($this->catalog->bestSelling((int)($request['limit']??12))),60); }
    public function searchBestSelling(array $request): array { return $this->cached($this->ok($this->catalog->searchBestSelling((string)($request['q']??''),(int)($request['limit']??12))),30); }
    public function fastDispatch(array $request): array { return $this->cached($this->ok($this->catalog->fastDispatch((int)($request['limit']??12))),30); }
    public function searchSuggestions(array $request): array { return $this->cached($this->ok($this->catalog->searchSuggestions((string)($request['q']??''),(int)($request['limit']??10))),15); }
    public function publishers(): array { return $this->cached($this->ok($this->catalog->taxonomy('publishers')),300); }
    public function categories(): array { return $this->cached($this->ok($this->catalog->taxonomy('categories')),300); }
    public function facets(): array { return $this->cached($this->ok($this->catalog->facets()),120); }
}
