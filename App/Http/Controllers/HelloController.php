<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Contracts\Services\GreeterServiceInterface;
use Swoole\Http\Request;

/**
 * Sample vertical slice: Controller -> ServiceInterface -> Service.
 *
 * The service is injected through the constructor by the DI container
 * (see Framework/Config/di.php).
 */
class HelloController extends Controller
{
    public function __construct(private GreeterServiceInterface $greeter)
    {
    }

    /**
     * GET /v1/hello?name=Ali
     * The router fills $name from the query string (cast to string).
     */
    public function index(string $name = ''): array
    {
        return $this->ok($this->greeter->greet($name));
    }

    /**
     * GET /v1/hello/{name}
     * The router fills $name from the route parameter.
     */
    public function show(string $name): array
    {
        return $this->ok($this->greeter->greet($name));
    }

    /**
     * POST /v1/hello  body: { "name": "Sara" }
     */
    public function store(Request $request): array
    {
        $body = $this->getRequestBody($request);
        return $this->created($this->greeter->greet($body['name'] ?? ''));
    }
}
