<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Infrastructure\Database\DB;
use App\Shared\Exceptions\ApiException;

final class ContentService
{
    public function page(string $slug): array
    {
        $page = DB::fetch('SELECT public_id id,slug,title,body FROM content_pages WHERE slug=:slug AND published=true', [':slug' => $slug]);
        if (!$page) {
            throw new ApiException('PRODUCT_NOT_FOUND', 'صفحه محتوا یافت نشد.', 404);
        }
        return $page;
    }
}
