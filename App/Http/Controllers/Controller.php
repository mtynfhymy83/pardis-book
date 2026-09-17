<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Concerns\ResponseTrait;
use App\Shared\Exceptions\AuthenticationException;
use App\Application\Security\JwtAuthenticator;
use App\Application\Security\AuthenticatedPrincipal;
use App\Shared\Exceptions\ApiException;
use Swoole\Http\Request;

/**
 * Base controller. Controllers stay thin: read input, call a service, return
 * data. Business logic lives in Domain/Application services.
 */
class Controller
{
    use ResponseTrait;

    /**
     * Decode the JSON (or form) body of a Swoole request.
     */
    protected function getRequestBody(Request $request): array
    {
        $ctype = strtolower($request->header['content-type'] ?? '');
        if (str_contains($ctype, 'application/json')) {
            $raw = $request->rawContent();
            if ($raw === '' || $raw === null) {
                return [];
            }
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : [];
        }
        return $request->post ?? [];
    }

    protected function getAuthUserId(Request $request): ?int
    {
        try {
            return (new JwtAuthenticator())->authenticate($request)->userId;
        } catch (ApiException $exception) {
            if ($exception->errorCode !== 'AUTHENTICATION_REQUIRED') {
                throw $exception;
            }
            return null;
        }
    }

    protected function requireAuthUserId(Request $request): int
    {
        $userId = $this->getAuthUserId($request);
        if (!$userId) {
            throw new AuthenticationException();
        }
        return $userId;
    }

    protected function requireAuthPrincipal(Request $request): AuthenticatedPrincipal
    {
        return (new JwtAuthenticator())->authenticate($request);
    }

    protected function cached(array $response, int $seconds): array
    {
        $response['headers'] = ['Cache-Control' => "public, max-age={$seconds}"];
        return $response;
    }

}
