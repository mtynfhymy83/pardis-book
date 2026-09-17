<?php

declare(strict_types=1);

use App\Application\Queries\CatalogCriteria;
use App\Shared\Exceptions\ApiException;
use PHPUnit\Framework\TestCase;

final class CatalogCriteriaTest extends TestCase
{
    public function testNormalizesAndParsesFilters(): void
    {
        $criteria = CatalogCriteria::from([
            'q' => '  كتاب ۳ ',
            'page' => '2',
            'pageSize' => '12',
            'sort' => 'unitPriceAsc',
            'filter' => ['inStock' => 'true', 'minUnitPrice' => '100000', 'level' => '3'],
        ]);

        self::assertSame('کتاب 3', $criteria->term);
        self::assertSame(2, $criteria->page);
        self::assertSame(12, $criteria->pageSize);
        self::assertTrue($criteria->inStock);
        self::assertSame(100000, $criteria->minimumUnitPrice);
        self::assertSame('3', $criteria->level);
    }

    public function testRejectsInvalidPriceRange(): void
    {
        $this->expectException(ApiException::class);
        CatalogCriteria::from(['filter' => ['minUnitPrice' => 200, 'maxUnitPrice' => 100]]);
    }

    public function testRejectsUnknownSort(): void
    {
        $this->expectException(ApiException::class);
        CatalogCriteria::from(['sort' => 'unsafe']);
    }
}
