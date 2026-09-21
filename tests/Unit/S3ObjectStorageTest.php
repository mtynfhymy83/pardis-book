<?php

declare(strict_types=1);

use App\Infrastructure\Providers\S3ObjectStorage;
use PHPUnit\Framework\TestCase;

final class S3ObjectStorageTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $previous = [];
    private array $keys = ['S3_BUCKET', 'S3_ENDPOINT', 'S3_FORCE_PATH_STYLE', 'S3_PUBLIC_URL'];

    protected function setUp(): void
    {
        foreach ($this->keys as $key) $this->previous[$key] = $_ENV[$key] ?? null;
    }

    protected function tearDown(): void
    {
        foreach ($this->previous as $key => $value) {
            if ($value === null) unset($_ENV[$key]);
            else $_ENV[$key] = $value;
        }
    }

    public function testItBuildsAParsPackPathStylePublicUrl(): void
    {
        $_ENV['S3_BUCKET'] = 'books';
        $_ENV['S3_ENDPOINT'] = 'https://account.parspack.net';
        $_ENV['S3_FORCE_PATH_STYLE'] = 'true';
        unset($_ENV['S3_PUBLIC_URL']);

        self::assertSame(
            'https://account.parspack.net/books/public/catalog/a%20book.webp',
            (new S3ObjectStorage())->publicUrl('public/catalog/a book.webp')
        );
    }

    public function testExplicitPublicBaseUrlTakesPriority(): void
    {
        $_ENV['S3_PUBLIC_URL'] = 'https://cdn.example.com/books/';

        self::assertSame(
            'https://cdn.example.com/books/public/catalog/a.webp',
            (new S3ObjectStorage())->publicUrl('public/catalog/a.webp')
        );
    }
}
