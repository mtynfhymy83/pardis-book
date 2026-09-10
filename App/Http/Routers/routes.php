<?php

declare(strict_types=1);

use App\Http\Routers\Router;

/**
 * Root route loader. Split route definitions into focused files (api, admin,
 * ...) and require them here.
 */
return static function (Router $router): void {
    $apiRoutes = require __DIR__ . '/api_routes.php';
    $apiRoutes($router);
};
