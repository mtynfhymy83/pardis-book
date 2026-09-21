<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Domain\Contracts\Providers\ObjectStorageInterface;
use App\Shared\Exceptions\ApiException;
use App\Shared\Support\Id;

final class AdminImageUploadService
{
    private const MAX_BYTES = 5 * 1024 * 1024;
    private const ALLOWED_MIMES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    public function __construct(private ObjectStorageInterface $storage) {}

    /** @param array{name?:string,type?:string,tmp_name?:string,error?:int,size?:int} $file */
    public function uploadCover(array $file): array
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            throw new ApiException('IMAGE_UPLOAD_FAILED', 'فایل تصویر به‌درستی دریافت نشد.', 422);
        }

        $path = (string) ($file['tmp_name'] ?? '');
        $size = $path !== '' && is_file($path) ? filesize($path) : false;
        if ($size === false || $size < 1) {
            throw new ApiException('IMAGE_UPLOAD_FAILED', 'فایل تصویر خالی یا نامعتبر است.', 422);
        }
        if ($size > self::MAX_BYTES) {
            throw new ApiException('IMAGE_TOO_LARGE', 'حجم تصویر باید حداکثر ۵ مگابایت باشد.', 422);
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path) ?: '';
        if (!isset(self::ALLOWED_MIMES[$mime]) || @getimagesize($path) === false) {
            throw new ApiException('IMAGE_TYPE_NOT_ALLOWED', 'فقط تصویر معتبر JPG، PNG یا WebP مجاز است.', 422);
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new ApiException('IMAGE_UPLOAD_FAILED', 'خواندن فایل تصویر انجام نشد.', 422);
        }

        $key = 'public/catalog/covers/' . Id::make('cover') . '.' . self::ALLOWED_MIMES[$mime];
        $this->storage->putString($key, $contents, $mime);

        return ['coverUrl' => $this->storage->publicUrl($key)];
    }
}
