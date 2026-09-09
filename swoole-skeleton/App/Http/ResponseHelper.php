<?php

declare(strict_types=1);

namespace App\Http;

use Swoole\Http\Response;

/**
 * Writes the final bytes to the Swoole response: JSON, text/html, custom
 * content types, file downloads and redirects.
 */
class ResponseHelper
{
    public function __construct(private Response $response)
    {
    }

    public function json(mixed $data, int $status = 200, array $headers = []): void
    {
        $this->response->header('Content-Type', 'application/json; charset=utf-8');

        foreach ($headers as $key => $value) {
            if ($key !== '' && $value !== null) {
                $this->response->header((string) $key, (string) $value);
            }
        }

        $this->response->status($status);

        if (is_array($data)) {
            unset($data['__status']);
        }
        $body = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

        $this->response->end($body);
    }

    public function text(string $content, int $status = 200): void
    {
        $this->response->header('Content-Type', 'text/plain; charset=utf-8');
        $this->response->status($status);
        $this->response->end($content);
    }

    public function html(string $content, int $status = 200): void
    {
        $this->response->header('Content-Type', 'text/html; charset=utf-8');
        $this->response->status($status);
        $this->response->end($content);
    }

    public function content(string $data, string $contentType, int $status = 200, array $headers = []): void
    {
        $normalized = strtolower($contentType);
        $this->response->header(
            'Content-Type',
            (str_starts_with($normalized, 'text/') || str_contains($normalized, 'json'))
                ? $contentType . '; charset=utf-8'
                : $contentType
        );

        foreach ($headers as $key => $value) {
            if ($key !== '' && $value !== null) {
                $this->response->header((string) $key, (string) $value);
            }
        }

        $this->response->header('Content-Length', (string) strlen($data));
        $this->response->status($status);
        $this->response->end($data);
    }

    public function download(string $filePath, ?string $filename = null, string $contentType = 'application/octet-stream', bool $deleteAfterSend = false): void
    {
        if (!is_file($filePath)) {
            $this->json(['success' => false, 'message' => 'File not found'], 404);
            return;
        }

        $filename ??= basename($filePath);

        $this->response->header('Content-Type', $contentType);
        $this->response->header('Content-Disposition', 'attachment; filename="' . addslashes($filename) . '"');
        $this->response->header('Content-Length', (string) filesize($filePath));
        $this->response->status(200);
        $this->response->sendfile($filePath);

        if ($deleteAfterSend && PHP_OS_FAMILY !== 'Windows') {
            @unlink($filePath);
        }
    }

    public function redirect(string $url, int $status = 302): void
    {
        $this->response->header('Location', $url);
        $this->response->status($status);
        $this->response->end();
    }
}
