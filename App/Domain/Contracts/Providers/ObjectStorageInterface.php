<?php

declare(strict_types=1);

namespace App\Domain\Contracts\Providers;

interface ObjectStorageInterface
{
    /** @return array{url:string,method:string,headers:array<string,string>,expiresAt:string} */
    public function presignPut(string $key, string $mime, int $expiresInSeconds = 900): array;

    public function putString(string $key, string $contents, string $mime): void;

    /** @return array{url:string,method:string,expiresAt:string} */
    public function presignGet(string $key, int $expiresInSeconds = 300): array;
}
