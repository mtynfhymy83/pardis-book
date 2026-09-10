<?php

declare(strict_types=1);

namespace App\Http\Routers;

use App\Http\Concerns\ResponseTrait;
use App\Http\Middlewares\CheckAccessMiddleware;
use App\Framework\Exceptions\Handler\ExceptionHandler;
use App\Framework\Bootstrap\Container;
use Psr\Container\ContainerInterface;
use Swoole\Http\Request;
use ReflectionMethod;

/**
 * Custom router.
 *
 * - Static routes are O(1) lookups; dynamic ({param}) routes are bucketed by
 *   their first/second path segment so only a few regexes are ever tried.
 * - Controller actions are invoked via cached Reflection "plans": each
 *   parameter is filled by name from route params -> body -> query, with
 *   scalar casting. A `Swoole\Http\Request` typed param receives the request
 *   object; a param named `request` receives merged body+query; `data`/`inputs`
 *   receive the body only.
 */
class Router
{
    use ResponseTrait;

    private ExceptionHandler $exceptionHandler;

    private array $staticRoutes = [];
    private array $dynamicRoutes = [];

    private array $controllerCache = [];
    private array $reflectionCache = [];

    /** @var array<string, true> */
    private array $primitiveCastTypes = [
        'int' => true, 'float' => true, 'bool' => true, 'string' => true, 'array' => true,
    ];

    private CheckAccessMiddleware $accessMiddleware;
    private string $currentPrefix = '';
    private bool $useControllerCache = true;
    private ?ContainerInterface $container = null;

    public function __construct(?ContainerInterface $container = null)
    {
        $this->container = $container ?? Container::getInstance();
        $this->accessMiddleware = new CheckAccessMiddleware();
        $this->useControllerCache = (($_ENV['CONTROLLER_CACHE'] ?? 'false') === 'true');
        $this->exceptionHandler = new ExceptionHandler();
    }

    public function get(string $version, string $path, string $controller, string $method, mixed $access = false, bool $inaccess = false): void
    {
        $this->registerRoute($version, 'GET', $path, $controller, $method, $access, $inaccess);
    }

    public function post(string $version, string $path, string $controller, string $method, mixed $access = false, bool $inaccess = false): void
    {
        $this->registerRoute($version, 'POST', $path, $controller, $method, $access, $inaccess);
    }

    public function put(string $version, string $path, string $controller, string $method, mixed $access = false, bool $inaccess = false): void
    {
        $this->registerRoute($version, 'PUT', $path, $controller, $method, $access, $inaccess);
    }

    public function patch(string $version, string $path, string $controller, string $method, mixed $access = false, bool $inaccess = false): void
    {
        $this->registerRoute($version, 'PATCH', $path, $controller, $method, $access, $inaccess);
    }

    public function delete(string $version, string $path, string $controller, string $method, mixed $access = false, bool $inaccess = false): void
    {
        $this->registerRoute($version, 'DELETE', $path, $controller, $method, $access, $inaccess);
    }

    public function any(string $version, string $path, string $controller, string $method, mixed $access = false, bool $inaccess = false): void
    {
        foreach (['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS'] as $m) {
            $this->registerRoute($version, $m, $path, $controller, $method, $access, $inaccess);
        }
    }

    public function prefix(string $prefix, callable $callback): void
    {
        $previousPrefix = $this->currentPrefix;
        $this->currentPrefix = $previousPrefix . $prefix;
        $callback($this);
        $this->currentPrefix = $previousPrefix;
    }

    public function group(callable $callback): void
    {
        $callback($this);
    }

    private function normalizePath(string $version, string $prefix, string $path): string
    {
        $fullPath = '/' . $version . '/' . $prefix . '/' . $path;
        $clean = preg_replace('#/+#', '/', $fullPath);
        return $clean === '/' ? '/' : rtrim($clean, '/');
    }

    private function registerRoute(string $version, string $httpMethod, string $path, string $controller, string $method, mixed $access, bool $inaccess): void
    {
        $fullPath = $this->normalizePath($version, $this->currentPrefix, $path);

        $routeData = [
            'controller' => $controller,
            'method'     => $method,
            'access'     => $access,
            'inaccess'   => $inaccess,
        ];

        if (str_contains($fullPath, '{')) {
            $pattern = $this->convertPathToRegex($fullPath);
            $key = $this->getDynamicPrefixKey($fullPath);
            $this->dynamicRoutes[$version][$httpMethod][$key][] = ['pattern' => $pattern, 'route' => $routeData];
        } else {
            $this->staticRoutes[$version][$httpMethod][$fullPath] = $routeData;
        }
    }

    private function convertPathToRegex(string $path): string
    {
        $pattern = preg_replace('/\{([a-zA-Z0-9_]+)\?\}/', '(?<$1>[^/]+)?', $path);
        $pattern = preg_replace('/\{([a-zA-Z0-9_]+)\}/', '(?<$1>[^/]+)', $pattern);
        return '#^' . $pattern . '$#';
    }

    public function resolve(string $version, string $requestMethod, string $path, ?Request $request = null): mixed
    {
        $path = $this->normalizeRequestPath($version, $path);

        // 1) Static routes — O(1)
        if (isset($this->staticRoutes[$version][$requestMethod][$path])) {
            return $this->handleRoute($this->staticRoutes[$version][$requestMethod][$path], $request, []);
        }

        // 2) Dynamic routes — bucketed
        if (isset($this->dynamicRoutes[$version][$requestMethod])) {
            $key = $this->getDynamicRequestKey($path);
            $buckets = $this->dynamicRoutes[$version][$requestMethod];

            $bucketsToCheck = [];
            if (isset($buckets[$key])) {
                $bucketsToCheck[] = $buckets[$key];
            }
            $slashPos = strpos($key, '/');
            if ($slashPos !== false) {
                $parentKey = substr($key, 0, $slashPos);
                if (isset($buckets[$parentKey])) {
                    $bucketsToCheck[] = $buckets[$parentKey];
                }
            }
            if (isset($buckets['*'])) {
                $bucketsToCheck[] = $buckets['*'];
            }

            foreach ($bucketsToCheck as $bucketItems) {
                foreach ($bucketItems as $item) {
                    if (preg_match($item['pattern'], $path, $matches)) {
                        return $this->handleRoute($item['route'], $request, $matches);
                    }
                }
            }
        }

        if ($requestMethod === 'OPTIONS') {
            return $this->sendResponse(null, 'OK', false, 200);
        }

        return $this->sendResponse(null, "Route Not Found ($path)", true, 404);
    }

    private function handleRoute(array $route, ?Request $request, array $params): mixed
    {
        try {
            $this->checkAccess($route['access'], $route['inaccess'], $request);

            $controllerClass = $route['controller'];
            $method = $route['method'];

            if ($this->useControllerCache) {
                if (!isset($this->controllerCache[$controllerClass])) {
                    $this->controllerCache[$controllerClass] = $this->resolveController($controllerClass);
                }
                $controllerInstance = $this->controllerCache[$controllerClass];
            } else {
                $controllerInstance = $this->resolveController($controllerClass);
            }

            if (!method_exists($controllerInstance, $method)) {
                throw new \Exception("Method $method not found in $controllerClass", 500);
            }

            $bodyData = $this->extractBody($request);
            $response = $this->invokeControllerMethod($controllerClass, $controllerInstance, $method, $request, $bodyData, $params);

            if ($this->isRawResponse($response) || is_string($response)) {
                return $response;
            }
            if ($this->isStructuredApiResponse($response)) {
                return $response;
            }
            if (is_array($response) && isset($response['content_type'], $response['data'])) {
                return $response;
            }
            if (is_array($response) && !empty($response['__file_download'])) {
                return $response;
            }

            return $this->sendResponse($response, 'Success', false, 200);
        } catch (\Throwable $e) {
            return $this->exceptionHandler->handle($e);
        }
    }

    private function invokeControllerMethod(string $class, object $instance, string $method, ?Request $request, array $bodyData, array $routeParams): mixed
    {
        $cacheKey = $class . '::' . $method;

        if (!isset($this->reflectionCache[$cacheKey])) {
            $reflection = new ReflectionMethod($instance, $method);
            $paramPlan = [];
            foreach ($reflection->getParameters() as $param) {
                $type = $param->getType();
                $typeName = $type instanceof \ReflectionNamedType ? $type->getName() : null;
                $name = $param->getName();
                $source = 'value';

                if ($typeName === Request::class) {
                    $source = 'request_object';
                } elseif ($name === 'request') {
                    $source = 'merged_request';
                } elseif ($name === 'data' || $name === 'inputs') {
                    $source = 'body';
                }

                $paramPlan[] = [
                    'name'       => $name,
                    'source'     => $source,
                    'type'       => $typeName,
                    'shouldCast' => isset($this->primitiveCastTypes[$typeName ?? '']),
                    'default'    => $param->isDefaultValueAvailable() ? $param->getDefaultValue() : null,
                    'hasDefault' => $param->isDefaultValueAvailable(),
                ];
            }
            $this->reflectionCache[$cacheKey] = $paramPlan;
        }

        $passParams = [];
        $queryParams = $request ? ($request->get ?? []) : [];

        foreach ($this->reflectionCache[$cacheKey] as $plan) {
            switch ($plan['source']) {
                case 'request_object':
                    $passParams[] = $request;
                    continue 2;
                case 'merged_request':
                    $passParams[] = $bodyData ? $bodyData + $queryParams : $queryParams;
                    continue 2;
                case 'body':
                    $passParams[] = $bodyData;
                    continue 2;
            }

            $name = $plan['name'];
            if (isset($routeParams[$name])) {
                $value = $routeParams[$name];
            } else {
                $value = $bodyData[$name] ?? $queryParams[$name] ?? null;
            }

            if ($value === null && $plan['hasDefault']) {
                $value = $plan['default'];
            } elseif ($value !== null && $plan['shouldCast']) {
                $value = $this->castValue($value, $plan['type']);
            }

            $passParams[] = $value;
        }

        return $instance->$method(...$passParams);
    }

    private function castValue(mixed $value, ?string $type): mixed
    {
        if ($value === null || $type === null) {
            return $value;
        }

        return match ($type) {
            'int'    => is_numeric($value) ? (int) $value : 0,
            'float'  => is_numeric($value) ? (float) $value : 0.0,
            'bool'   => in_array($value, ['true', '1', 'on', 'yes', true, 1], true) ? true
                        : (in_array($value, ['false', '0', 'off', 'no', false, 0], true) ? false : $value),
            'string' => is_scalar($value) ? (string) $value : $value,
            'array'  => is_array($value) ? $value : [$value],
            default  => $value,
        };
    }

    private function extractBody(?Request $request): array
    {
        if (!$request) {
            return [];
        }

        $ctype = strtolower($request->header['content-type'] ?? '');
        if (str_contains($ctype, 'application/json')) {
            $raw = $request->rawContent();
            try {
                return !empty($raw) ? (json_decode($raw, true, 512, JSON_THROW_ON_ERROR) ?? []) : [];
            } catch (\JsonException) {
                return [];
            }
        }

        return $request->post ?? [];
    }

    private function checkAccess(mixed $access, bool $inaccess, ?Request $request): void
    {
        if ($access) {
            $roles = match ($access) {
                'owners' => ['support', 'admin'],
                'owner'  => ['owner'],
                'all', 'auth' => ['owner', 'support', 'admin', 'operator', 'teacher', 'guest', 'user'],
                default  => is_array($access) ? $access : [$access],
            };
            $this->accessMiddleware->checkAccess($roles, $request);
        }
    }

    private function isRawResponse(mixed $response): bool
    {
        return is_array($response) && (isset($response['swagger_file']) || isset($response['data']['swagger_file']));
    }

    private function isStructuredApiResponse(mixed $response): bool
    {
        return is_array($response)
            && (array_key_exists('data', $response) || isset($response['error']))
            && isset($response['meta']);
    }

    private function getDynamicPrefixKey(string $fullPath): string
    {
        $clean = trim($fullPath, '/');
        if ($clean === '') {
            return '*';
        }

        $parts = explode('/', $clean);
        $key = $parts[1] ?? '*';

        if (isset($parts[2]) && $parts[2] !== '' && !str_contains($parts[2], '{')) {
            $key = $parts[1] . '/' . $parts[2];
        }

        return $key;
    }

    private function getDynamicRequestKey(string $path): string
    {
        $firstSlash = $path[0] === '/' ? 1 : 0;
        $secondSlash = strpos($path, '/', $firstSlash);
        if ($secondSlash === false) {
            return '*';
        }

        $thirdStart = $secondSlash + 1;
        $thirdSlash = strpos($path, '/', $thirdStart);
        $firstSegment = $thirdSlash === false
            ? substr($path, $thirdStart)
            : substr($path, $thirdStart, $thirdSlash - $thirdStart);

        if ($firstSegment === '') {
            return '*';
        }
        if ($thirdSlash === false) {
            return $firstSegment;
        }

        $fourthStart = $thirdSlash + 1;
        $fourthSlash = strpos($path, '/', $fourthStart);
        if ($fourthSlash === false) {
            return $firstSegment;
        }

        $secondSegment = substr($path, $fourthStart, $fourthSlash - $fourthStart);
        return $secondSegment !== '' ? $firstSegment . '/' . $secondSegment : $firstSegment;
    }

    private function normalizeRequestPath(string $version, string $path): string
    {
        $path = trim($path, '/');
        return $path === '' ? '/' . $version : '/' . $version . '/' . $path;
    }

    private function resolveController(string $controllerClass): object
    {
        if (!class_exists($controllerClass)) {
            throw new \Exception("Controller $controllerClass not found", 500);
        }

        if ($this->container !== null) {
            try {
                return $this->container->get($controllerClass);
            } catch (\Throwable $e) {
                error_log("[DI Warning] Failed to resolve $controllerClass: " . $e->getMessage());
            }
        }

        return new $controllerClass();
    }
}
