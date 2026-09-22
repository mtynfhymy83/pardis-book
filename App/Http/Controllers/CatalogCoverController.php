<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Contracts\Providers\ObjectStorageInterface;
use App\Shared\Exceptions\ApiException;

final class CatalogCoverController extends Controller
{
    private const MAX_BYTES = 5 * 1024 * 1024;
    private const MIME_TYPES = [
        'jpg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
    ];

    public function __construct(private ObjectStorageInterface $storage) {}

    public function show(string $filename): array
    {
        if (preg_match('/^cover_[a-z0-9]+\.(jpg|png|webp)$/', $filename, $matches) !== 1) {
            throw new ApiException('COVER_NOT_FOUND', 'تصویر جلد یافت نشد.', 404);
        }

        $contents = $this->storage->getString('public/catalog/covers/' . $filename, self::MAX_BYTES);
        if ($contents === null) {
            throw new ApiException('COVER_NOT_FOUND', 'تصویر جلد یافت نشد.', 404);
        }

        return [
            'content_type' => self::MIME_TYPES[$matches[1]],
            'data' => $contents,
            'headers' => [
                'Cache-Control' => 'public, max-age=31536000, immutable',
                'X-Content-Type-Options' => 'nosniff',
            ],
        ];
    }
}
