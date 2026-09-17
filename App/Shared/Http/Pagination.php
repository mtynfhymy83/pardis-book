<?php

declare(strict_types=1);

namespace App\Shared\Http;

use App\Shared\Exceptions\ApiException;

final class Pagination
{
    /** @param list<string> $allowedSorts */
    public static function from(array $query, array $allowedSorts = [], int $maximumPageSize = 60): array
    {
        $page = filter_var($query['page'] ?? 1, FILTER_VALIDATE_INT);
        $pageSize = filter_var($query['pageSize'] ?? 24, FILTER_VALIDATE_INT);
        $sort = isset($query['sort']) ? (string) $query['sort'] : null;
        $fields = [];

        if ($page === false || $page < 1) {
            $fields['page'] = 'شماره صفحه باید عددی مثبت باشد.';
        }
        if ($pageSize === false || $pageSize < 1 || $pageSize > $maximumPageSize) {
            $fields['pageSize'] = "اندازه صفحه باید بین ۱ و {$maximumPageSize} باشد.";
        }
        if ($sort !== null && !in_array($sort, $allowedSorts, true)) {
            $fields['sort'] = 'مرتب‌سازی انتخاب‌شده مجاز نیست.';
        }
        if ($fields !== []) {
            throw new ApiException('VALIDATION_FAILED', 'پارامترهای صفحه‌بندی معتبر نیستند.', 422, $fields);
        }

        return [
            'page' => (int) $page,
            'pageSize' => (int) $pageSize,
            'offset' => ((int) $page - 1) * (int) $pageSize,
            'sort' => $sort,
        ];
    }
}
