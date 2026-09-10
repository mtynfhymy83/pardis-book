# Pardis Book Swoole API

Implementation of the Pardis wholesale bookstore backend contract. The current runnable slice includes the shared API envelope, PostgreSQL schema and migrations, OTP/JWT sessions, catalog/search, tier pricing, guest/user carts, addresses, checkout quotes, inventory reservations, idempotent order creation, fake-gateway payment attempts and server-side verification.

## Start

```bash
cp .env.example .env
# replace JWT_SECRET, then:
docker compose up -d db redis
docker compose run --rm app php scripts/migrate.php
docker compose up -d app worker
```

API base: `http://localhost:9501/api/v1`. OpenAPI is in `docs/openapi.yaml`. Fake OTP codes are returned only outside production; fake payment is blocked in production unless explicitly enabled.

```bash
composer test
composer analyse
```

A minimal, runnable starter built from the Madras_Api framework core: a custom
**Swoole** HTTP server with **Clean Architecture / DDD** layering, a custom
router, and a PHP-DI container. No Laravel/Symfony.

> Full architecture reference: see `docs/FRAMEWORK_STRUCTURE.md` in the
> Madras_Api project.

## Requirements

- PHP 8.1+
- The `swoole` PHP extension (or run via Docker)
- Composer

## Run (local)

```bash
cp .env.example .env          # then set JWT_SECRET
composer install
php server.php
```

## Run (Docker)

```bash
cp .env.example .env
docker compose up --build
```

## Database

PostgreSQL 16 is the source of truth. Apply the versioned migrations before starting the API:

```bash
php scripts/migrate.php
php server.php
```

The PDO pool is built per Swoole worker and closed on `WorkerStop`.

## Try it

```bash
curl http://localhost:9501/api/v1/health/live
curl http://localhost:9501/api/v1/products
curl -X POST http://localhost:9501/api/v1/pricing/quote-line -H "Content-Type: application/json" -d '{"skuId":"sku_ff3_sb_2e","quantity":10}'
```

Every response uses the standard envelope:

```json
{ "data": {}, "meta": { "requestId": "req_...", "serverTime": "2026-09-09T08:35:20Z" } }
```

## Directory layout

```
App/
├── Framework/        ← reusable core (DON'T put business logic here)
│   ├── Bootstrap/    Server, Container, EnvironmentManager, WorkerBootstrapper
│   ├── Core/         Pipeline, MiddlewareInterface, Method
│   ├── Coroutine/    Context (request-scoped state)
│   ├── Config/       di.php (bindings), events.php (listeners)
│   └── Exceptions/   ExceptionHandler
├── Http/             delivery layer
│   ├── Controllers/  thin — read input, call service, return data
│   ├── Middlewares/  Cors, ServerError, CheckAccess (+ your own)
│   ├── Routers/      Router.php + route definition files
│   └── Concerns/     ResponseTrait
├── Domain/           business core (no framework/DB dependencies)
│   ├── Contracts/    interfaces (Services, Repositories)
│   └── Services/     business logic
├── Application/      (add as you grow) use-cases / features
├── Infrastructure/   (add as you grow) DB, cache, storage implementations
├── Shared/           ApiResponse + exceptions
└── Helpers/          autoloaded global functions
```

## Two reference slices

| Slice | Flow | Files |
|-------|------|-------|
| `Hello` (no DB) | Controller → Service | `HelloController`, `GreeterService(Interface)` |
| `Note` (DB) | Controller → Service → Repository → DB | `NoteController`, `NoteService(Interface)`, `NoteRepository(Interface)` |

## Add a new feature (the pattern)

1. `Domain/Contracts/Services/XServiceInterface.php` — the contract
2. `Domain/Services/XService.php` — the logic (depends on repo interfaces only)
3. (data) `Domain/Contracts/Repositories/XRepositoryInterface.php`
   + `Infrastructure/Persistence/Repositories/XRepository.php` (uses `DB::fetchAll/execute/transaction`)
4. `Http/Controllers/XController.php` — extends `Controller`
5. Bind it in `Framework/Config/di.php`:
   ```php
   XServiceInterface::class    => autowire(XService::class),
   XRepositoryInterface::class => autowire(XRepository::class),
   XService::class    => autowire(),
   XRepository::class => autowire(),
   ```
6. Register the route in `Http/Routers/api_routes.php`.

## Database access (DB facade)

Always go through `DB` — it borrows a pooled connection and returns it for you:

```php
use App\Infrastructure\Database\DB;

DB::fetchAll('SELECT * FROM notes WHERE title LIKE :q', [':q' => "%$term%"]);
DB::fetch('SELECT * FROM notes WHERE id = :id', [':id' => $id]);
DB::execute('DELETE FROM notes WHERE id = :id', [':id' => $id]);          // → affected rows
DB::execute('INSERT INTO notes (title) VALUES (:t)', [':t' => $t], true); // → last insert id

DB::transaction(function (PDO $pdo) {
    // ... multiple statements; auto commit / rollback
});
```

Never hold a connection across requests, and never interpolate user input into
SQL — bind parameters (as above).

## How controller arguments are filled

The router inspects each action's signature (cached Reflection):

| Parameter | Receives |
|-----------|----------|
| typed `Swoole\Http\Request $request` | the raw request object |
| named `request` (untyped) | merged body + query array |
| named `data` or `inputs` | request body only |
| anything else (e.g. `int $id`) | route param → body → query, cast to its scalar type |

## Notes on Swoole

- Each worker has **isolated memory** — the router/DB pool/cache are built once
  per worker in `WorkerBootstrapper`.
- Use `Context` for per-request state, never static/global shared variables.
- `Pipeline` avoids closures to prevent memory leaks in long-lived workers.
- When you add a database, build a connection pool in `WorkerBootstrapper`
  and close it on `WorkerStop` (hook already noted in `Server::start()`).
```
