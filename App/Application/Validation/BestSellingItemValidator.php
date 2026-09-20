<?php

declare(strict_types=1);

namespace App\Application\Validation;

use App\Shared\Exceptions\ApiException;

final class BestSellingItemValidator
{
    /**
     * Validate and normalize create/update input.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed>|null $current Existing API-shaped item for PATCH requests.
     * @return array{title:string,coverUrl:string,coverAlt:?string,price:int,discountedPrice:int,discountPercent:int,remainingPercent:int,sortOrder:int,active:bool}
     */
    public static function normalize(array $data, ?array $current = null): array
    {
        $value = static fn(string $key, mixed $default = null): mixed => array_key_exists($key, $data)
            ? $data[$key]
            : ($current[$key] ?? $default);

        $title = trim((string) $value('title', ''));
        $coverUrl = trim((string) $value('coverUrl', ''));
        $coverAltValue = $value('coverAlt');
        $coverAlt = $coverAltValue === null ? null : trim((string) $coverAltValue);
        $price = self::integer($value('price'), 'price');
        $discountedPrice = self::integer($value('discountedPrice'), 'discountedPrice');
        $discountPercent = self::integer($value('discountPercent'), 'discountPercent');
        $remainingPercent = self::integer($value('remainingPercent'), 'remainingPercent');
        $sortOrder = self::integer($value('sortOrder', 0), 'sortOrder');
        $active = self::boolean($value('active', true));

        $errors = [];
        if ($title === '') $errors['title'] = 'نام کتاب الزامی است.';
        elseif (mb_strlen($title) > 250) $errors['title'] = 'نام کتاب حداکثر ۲۵۰ کاراکتر است.';
        if ($coverUrl === '' || !self::validCoverUrl($coverUrl)) $errors['coverUrl'] = 'آدرس عکس جلد باید URL معتبر یا مسیر مطلق سایت باشد.';
        if ($coverAlt !== null && mb_strlen($coverAlt) > 250) $errors['coverAlt'] = 'متن جایگزین عکس حداکثر ۲۵۰ کاراکتر است.';
        if ($price < 1) $errors['price'] = 'قیمت باید بیشتر از صفر باشد.';
        if ($discountedPrice < 0 || $discountedPrice > $price) $errors['discountedPrice'] = 'قیمت با تخفیف باید بین صفر و قیمت اصلی باشد.';
        if ($discountPercent < 0 || $discountPercent > 100) $errors['discountPercent'] = 'درصد تخفیف باید بین ۰ تا ۱۰۰ باشد.';
        if ($remainingPercent < 0 || $remainingPercent > 100) $errors['remainingPercent'] = 'درصد مانده باید بین ۰ تا ۱۰۰ باشد.';
        if ($sortOrder < 0 || $sortOrder > 100000) $errors['sortOrder'] = 'ترتیب نمایش باید بین ۰ تا ۱۰۰۰۰۰ باشد.';

        if ($errors !== []) {
            throw new ApiException('VALIDATION_FAILED', 'اطلاعات محصول پرفروش معتبر نیست.', 422, $errors);
        }

        return [
            'title' => $title,
            'coverUrl' => $coverUrl,
            'coverAlt' => $coverAlt === '' ? null : $coverAlt,
            'price' => $price,
            'discountedPrice' => $discountedPrice,
            'discountPercent' => $discountPercent,
            'remainingPercent' => $remainingPercent,
            'sortOrder' => $sortOrder,
            'active' => $active,
        ];
    }

    private static function integer(mixed $value, string $field): int
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw new ApiException('VALIDATION_FAILED', 'اطلاعات محصول پرفروش معتبر نیست.', 422, [$field => 'مقدار باید عدد صحیح باشد.']);
        }
        return (int) $value;
    }

    private static function boolean(mixed $value): bool
    {
        if (is_bool($value)) return $value;
        if (in_array($value, [1, '1', 'true'], true)) return true;
        if (in_array($value, [0, '0', 'false'], true)) return false;
        throw new ApiException('VALIDATION_FAILED', 'اطلاعات محصول پرفروش معتبر نیست.', 422, ['active' => 'مقدار active باید boolean باشد.']);
    }

    private static function validCoverUrl(string $url): bool
    {
        if (str_starts_with($url, '/') && !str_starts_with($url, '//')) return true;
        if (filter_var($url, FILTER_VALIDATE_URL) === false) return false;
        return in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true);
    }
}
