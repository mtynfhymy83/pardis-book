<?php

declare(strict_types=1);

use App\Shared\Exceptions\ApiException;
use App\Shared\Http\Pagination;
use PHPUnit\Framework\TestCase;

final class PaginationTest extends TestCase
{
    public function testBuildsSafePagination(): void
    {
        self::assertSame(
            ['page' => 3, 'pageSize' => 20, 'offset' => 40, 'sort' => 'newest'],
            Pagination::from(['page' => '3', 'pageSize' => '20', 'sort' => 'newest'], ['newest'])
        );
    }

    public function testRejectsUnlistedSort(): void
    {
        $this->expectException(ApiException::class);
        Pagination::from(['sort' => 'DROP TABLE'], ['newest']);
    }
}
