<?php

declare(strict_types=1);

use App\Framework\Coroutine\Context;
use App\Http\Routers\Router;
use PHPUnit\Framework\TestCase;

final class PlatformRoutesTest extends TestCase
{
    protected function tearDown(): void
    {
        Context::clear();
    }

    public function testLivenessUsesStandardEnvelope(): void
    {
        Context::set('request_id', 'request_test_123');
        $router = $this->router();

        $response = $router->resolve('v1', 'GET', 'health/live');

        self::assertSame(['status' => 'ok'], $response['data']);
        self::assertSame('request_test_123', $response['meta']['requestId']);
        self::assertSame(200, $response['__status']);
    }

    public function testUnknownRouteUsesStableErrorEnvelope(): void
    {
        Context::set('request_id', 'request_test_456');
        $response = $this->router()->resolve('v1', 'GET', 'does-not-exist');

        self::assertSame('ROUTE_NOT_FOUND', $response['error']['code']);
        self::assertSame(404, $response['__status']);
        self::assertSame('request_test_456', $response['meta']['requestId']);
    }

    private function router(): Router
    {
        $router = new Router();
        $registerRoutes = require dirname(__DIR__, 2) . '/App/Http/Routers/routes.php';
        $registerRoutes($router);
        return $router;
    }
}
