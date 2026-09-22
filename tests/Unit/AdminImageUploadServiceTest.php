<?php

declare(strict_types=1);

use App\Application\Services\AdminImageUploadService;
use App\Domain\Contracts\Providers\ObjectStorageInterface;
use App\Shared\Exceptions\ApiException;
use PHPUnit\Framework\TestCase;

final class AdminImageUploadServiceTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'cover_');
        self::assertNotFalse($path);
        $this->path = $path;
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) unlink($this->path);
    }

    public function testItValidatesAndUploadsAPngCover(): void
    {
        file_put_contents($this->path, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='
        ));
        $storage = new class implements ObjectStorageInterface {
            public string $key = '';
            public string $contents = '';
            public string $mime = '';
            public function presignPut(string $key, string $mime, int $expiresInSeconds = 900): array { return []; }
            public function putString(string $key, string $contents, string $mime): void { $this->key = $key; $this->contents = $contents; $this->mime = $mime; }
            public function publicUrl(string $key): string { return 'https://cdn.example.com/' . $key; }
            public function presignGet(string $key, int $expiresInSeconds = 300): array { return []; }
            public function getString(string $key, int $maxBytes): ?string { return null; }
        };

        $result = (new AdminImageUploadService($storage))->uploadCover([
            'tmp_name' => $this->path,
            'error' => UPLOAD_ERR_OK,
        ]);

        self::assertSame('image/png', $storage->mime);
        self::assertMatchesRegularExpression('#^public/catalog/covers/cover_[a-z0-9]+\.png$#', $storage->key);
        self::assertSame('https://cdn.example.com/' . $storage->key, $result['coverUrl']);
        self::assertNotSame('', $storage->contents);
    }

    public function testItRejectsAFileThatIsNotARealImage(): void
    {
        file_put_contents($this->path, 'not an image');
        $storage = new class implements ObjectStorageInterface {
            public function presignPut(string $key, string $mime, int $expiresInSeconds = 900): array { return []; }
            public function putString(string $key, string $contents, string $mime): void {}
            public function publicUrl(string $key): string { return ''; }
            public function presignGet(string $key, int $expiresInSeconds = 300): array { return []; }
            public function getString(string $key, int $maxBytes): ?string { return null; }
        };

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('JPG');
        (new AdminImageUploadService($storage))->uploadCover([
            'tmp_name' => $this->path,
            'error' => UPLOAD_ERR_OK,
        ]);
    }
}
