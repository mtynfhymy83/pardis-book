<?php

declare(strict_types=1);

use App\Application\Validation\BestSellingItemValidator;
use App\Application\Services\AuditService;
use App\Application\Services\BestSellingService;
use App\Shared\Exceptions\ApiException;
use PHPUnit\Framework\TestCase;

final class BestSellingItemValidatorTest extends TestCase
{
    public function testItNormalizesAValidItem(): void
    {
        $item = BestSellingItemValidator::normalize([
            'title' => '  کتاب تست  ',
            'coverUrl' => 'https://cdn.example.com/book.jpg',
            'price' => 250000,
            'discountedPrice' => 200000,
            'discountPercent' => 20,
            'remainingPercent' => 35,
        ]);

        self::assertSame('کتاب تست', $item['title']);
        self::assertSame(250000, $item['price']);
        self::assertSame(0, $item['sortOrder']);
        self::assertTrue($item['active']);
    }

    public function testItRejectsAnInvalidDiscountedPriceAndPercentages(): void
    {
        try {
            BestSellingItemValidator::normalize([
                'title' => 'کتاب تست',
                'coverUrl' => '/images/book.jpg',
                'price' => 100000,
                'discountedPrice' => 120000,
                'discountPercent' => 101,
                'remainingPercent' => -1,
            ]);
            self::fail('Expected validation to fail.');
        } catch (ApiException $exception) {
            self::assertSame('VALIDATION_FAILED', $exception->errorCode);
            self::assertArrayHasKey('discountedPrice', $exception->fields);
            self::assertArrayHasKey('discountPercent', $exception->fields);
            self::assertArrayHasKey('remainingPercent', $exception->fields);
        }
    }

    public function testPatchKeepsCurrentValues(): void
    {
        $current = [
            'title' => 'کتاب قبلی', 'coverUrl' => '/old.jpg', 'coverAlt' => null,
            'price' => 100000, 'discountedPrice' => 90000, 'discountPercent' => 10,
            'remainingPercent' => 80, 'sortOrder' => 2, 'active' => true,
        ];

        $item = BestSellingItemValidator::normalize(['remainingPercent' => 45], $current);

        self::assertSame('کتاب قبلی', $item['title']);
        self::assertSame(45, $item['remainingPercent']);
        self::assertSame(2, $item['sortOrder']);
    }

    public function testBestSellingSearchRequiresAtLeastTwoCharacters(): void
    {
        $service = new BestSellingService(new AuditService());

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('حداقل ۲ کاراکتر');

        $service->searchPublic('ک');
    }
}
