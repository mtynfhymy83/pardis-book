<?php

declare(strict_types=1);

namespace App\Application\Queries;

use App\Shared\Exceptions\ApiException;
use App\Shared\Http\Pagination;
use App\Shared\Support\PersianText;

final class CatalogCriteria
{
    private const SORTS = ['bestSelling', 'newest', 'unitPriceAsc', 'discountDesc', 'fastDispatch'];

    public function __construct(
        public readonly int $page,
        public readonly int $pageSize,
        public readonly string $sort,
        public readonly string $term,
        public readonly ?string $seriesId,
        public readonly ?string $categoryId,
        public readonly ?string $publisherId,
        public readonly ?string $ageGroup,
        public readonly ?string $level,
        public readonly ?string $edition,
        public readonly ?string $bookType,
        public readonly ?bool $inStock,
        public readonly ?bool $fastDispatch,
        public readonly ?int $minimumUnitPrice,
        public readonly ?int $maximumUnitPrice,
    ) {
    }

    public static function from(array $query): self
    {
        $pagination = Pagination::from($query, self::SORTS, 60);
        $filter = is_array($query['filter'] ?? null) ? $query['filter'] : [];
        foreach (['seriesId', 'categoryId', 'publisherId', 'ageGroup', 'level', 'edition', 'bookType', 'inStock', 'fastDispatch', 'minUnitPrice', 'maxUnitPrice'] as $name) {
            if (!array_key_exists($name, $filter) && array_key_exists($name, $query)) {
                $filter[$name] = $query[$name];
            }
        }

        $minimum = self::integer($filter, 'minUnitPrice');
        $maximum = self::integer($filter, 'maxUnitPrice');
        if ($minimum !== null && $minimum < 0 || $maximum !== null && $maximum < 0) {
            throw self::invalid('قیمت نمی‌تواند منفی باشد.', ['filter.price' => 'قیمت باید عددی نامنفی باشد.']);
        }
        if ($minimum !== null && $maximum !== null && $minimum > $maximum) {
            throw self::invalid('بازه قیمت معتبر نیست.', ['filter.minUnitPrice' => 'حداقل قیمت از حداکثر بیشتر است.']);
        }

        return new self(
            $pagination['page'],
            $pagination['pageSize'],
            $pagination['sort'] ?? 'newest',
            PersianText::normalize((string) ($query['q'] ?? '')),
            self::text($filter, 'seriesId'),
            self::text($filter, 'categoryId'),
            self::text($filter, 'publisherId'),
            self::text($filter, 'ageGroup'),
            self::text($filter, 'level'),
            self::text($filter, 'edition'),
            self::text($filter, 'bookType'),
            self::boolean($filter, 'inStock'),
            self::boolean($filter, 'fastDispatch'),
            $minimum,
            $maximum,
        );
    }

    private static function text(array $filter, string $key): ?string
    {
        if (!array_key_exists($key, $filter) || trim((string) $filter[$key]) === '') {
            return null;
        }
        $value = trim((string) $filter[$key]);
        if (mb_strlen($value) > 100) {
            throw self::invalid('فیلتر بیش از حد طولانی است.', ["filter.{$key}" => 'حداکثر طول ۱۰۰ کاراکتر است.']);
        }
        return $value;
    }

    private static function integer(array $filter, string $key): ?int
    {
        if (!array_key_exists($key, $filter) || $filter[$key] === '') {
            return null;
        }
        $value = filter_var($filter[$key], FILTER_VALIDATE_INT);
        if ($value === false) {
            throw self::invalid('فیلتر عددی معتبر نیست.', ["filter.{$key}" => 'باید عدد صحیح باشد.']);
        }
        return (int) $value;
    }

    private static function boolean(array $filter, string $key): ?bool
    {
        if (!array_key_exists($key, $filter) || $filter[$key] === '') {
            return null;
        }
        $value = filter_var($filter[$key], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        if ($value === null) {
            throw self::invalid('فیلتر بولی معتبر نیست.', ["filter.{$key}" => 'فقط true یا false مجاز است.']);
        }
        return $value;
    }

    private static function invalid(string $message, array $fields): ApiException
    {
        return new ApiException('VALIDATION_FAILED', $message, 422, $fields);
    }
}
