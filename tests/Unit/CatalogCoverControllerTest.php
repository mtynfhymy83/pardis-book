<?php

declare(strict_types=1);

use App\Domain\Contracts\Providers\ObjectStorageInterface;
use App\Http\Controllers\CatalogCoverController;
use App\Shared\Exceptions\ApiException;
use PHPUnit\Framework\TestCase;

final class CatalogCoverControllerTest extends TestCase
{
    public function testItServesOnlyCatalogCoversFromPrivateStorage(): void
    {
        $storage = $this->storage('png-bytes');
        $response = (new CatalogCoverController($storage))->show('cover_abc123.png');

        self::assertSame('public/catalog/covers/cover_abc123.png', $storage->lastKey);
        self::assertSame('image/png', $response['content_type']);
        self::assertSame('png-bytes', $response['data']);
        self::assertSame('nosniff', $response['headers']['X-Content-Type-Options']);
    }

    public function testItRejectsOtherStorageKeys(): void
    {
        $storage = $this->storage('png-bytes');
        $this->expectException(ApiException::class);
        (new CatalogCoverController($storage))->show('../private.png');
    }

    public function testItReturnsNotFoundForMissingCover(): void
    {
        $storage = $this->storage(null);
        $this->expectException(ApiException::class);
        (new CatalogCoverController($storage))->show('cover_abc123.webp');
    }

    private function storage(?string $contents): ObjectStorageInterface
    {
        return new class($contents) implements ObjectStorageInterface {
            public string $lastKey = '';
            public function __construct(private ?string $contents) {}
            public function presignPut(string $key, string $mime, int $expiresInSeconds = 900): array { return []; }
            public function putString(string $key, string $contents, string $mime): void {}
            public function publicUrl(string $key): string { return ''; }
            public function presignGet(string $key, int $expiresInSeconds = 300): array { return []; }
            public function getString(string $key, int $maxBytes): ?string { $this->lastKey = $key; return $this->contents; }
        };
    }
}
