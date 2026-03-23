# Per-RPC Middleware Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a `middleware` property to `RecordFunctionType` so individual RPC functions can declare their own middleware, overriding the global `middleware_map`.

**Architecture:** `RecordFunctionType` gains a nullable `middleware` property (emitted unconditionally in `toArray`, like `clearCacheTables`). `RecordRouteMiddleware` checks this property before falling back to `middleware_map` — if non-null, the function's middleware replaces the map. The lookup mirrors the exact two-step (exact + pattern) logic already in `RecordService`.

**Tech Stack:** PHP 8.2+, Laravel 12, Orchestra Testbench (tests), PHPUnit

---

## File Map

| File | Action | What changes |
|---|---|---|
| `src/Types/RecordFunctionType.php` | Modify | Add `middleware` param to constructor, `__set_state`, `fromArray`, `toArray` |
| `src/Http/Middleware/RecordRouteMiddleware.php` | Modify | Add `resolveFunctionConfig()`, integrate into `resolveMiddlewares()` |
| `tests/Unit/RecordFunctionTypeTest.php` | Modify | Add tests for the new `middleware` property |
| `tests/Feature/PerRpcMiddlewareTest.php` | Create | Feature tests: global function middleware, table function middleware, fallback behavior |

---

## Routing Note (read before writing feature tests)

**Global function routes** (`/api/rpc/{functionName}`) are registered at application boot from `config('record.global_functions')`. If a function name is absent from config at boot, no route is created for it and HTTP calls will return 404. `Config::set()` called in `setUp()` does NOT re-register routes.

**Fix:** pre-register all function names used by feature tests in `getEnvironmentSetUp()` so their routes exist at boot. Individual tests can then change the `RecordFunctionType` config (middleware, etc.) via `Config::set()` for their specific assertions — the route will still match, and `RecordRouteMiddleware` reads config at request time.

**Table function routes** (`/api/{table}/rpc/{functionName}`) are always registered with `{functionName}` matching `.*`, so table function feature tests can safely use `setUp()` without this constraint.

---

## Task 1: Add `middleware` to `RecordFunctionType`

**Files:**
- Modify: `src/Types/RecordFunctionType.php:91-230`
- Modify: `tests/Unit/RecordFunctionTypeTest.php`

- [ ] **Step 1: Write the failing unit tests**

Add to `tests/Unit/RecordFunctionTypeTest.php`:

```php
/** @test */
public function it_defaults_middleware_to_null(): void
{
    $type = new RecordFunctionType(
        httpMethod: 'POST',
        class: 'App\\Services\\DummyService',
        functionName: 'handle',
    );

    $this->assertNull($type->middleware);
}

/** @test */
public function it_accepts_array_middleware(): void
{
    $type = new RecordFunctionType(
        httpMethod: 'POST',
        class: 'App\\Services\\DummyService',
        functionName: 'handle',
        middleware: ['auth:sanctum', 'throttle:10,1'],
    );

    $this->assertSame(['auth:sanctum', 'throttle:10,1'], $type->middleware);
}

/** @test */
public function it_accepts_string_middleware(): void
{
    $type = new RecordFunctionType(
        httpMethod: 'POST',
        class: 'App\\Services\\DummyService',
        functionName: 'handle',
        middleware: 'auth:sanctum',
    );

    $this->assertSame('auth:sanctum', $type->middleware);
}

/** @test */
public function it_accepts_empty_array_middleware_as_explicit_opt_out(): void
{
    $type = new RecordFunctionType(
        httpMethod: 'POST',
        class: 'App\\Services\\DummyService',
        functionName: 'handle',
        middleware: [],
    );

    // Empty array is non-null — it is an explicit "run with no middleware"
    $this->assertSame([], $type->middleware);
    $this->assertNotNull($type->middleware);
}

/** @test */
public function it_includes_middleware_in_to_array_when_set(): void
{
    $type = new RecordFunctionType(
        httpMethod: 'POST',
        class: 'App\\Services\\DummyService',
        functionName: 'handle',
        middleware: ['auth:sanctum'],
    );

    $this->assertArrayHasKey('middleware', $type->toArray());
    $this->assertSame(['auth:sanctum'], $type->toArray()['middleware']);
}

/** @test */
public function it_includes_null_middleware_key_in_to_array(): void
{
    // middleware is emitted unconditionally (like clearCacheTables), NOT conditionally
    // (unlike description/querySchema which are omitted when null).
    // This ensures toArray() → fromArray() and toArray() → __set_state() round-trips work correctly.
    $type = new RecordFunctionType(
        httpMethod: 'POST',
        class: 'App\\Services\\DummyService',
        functionName: 'handle',
    );

    $array = $type->toArray();
    $this->assertArrayHasKey('middleware', $array);
    $this->assertNull($array['middleware']);
}

/** @test */
public function it_round_trips_middleware_through_from_array(): void
{
    $type = new RecordFunctionType(
        httpMethod: 'POST',
        class: 'App\\Services\\DummyService',
        functionName: 'handle',
        middleware: ['auth:sanctum'],
    );

    $restored = RecordFunctionType::fromArray($type->toArray());

    $this->assertSame(['auth:sanctum'], $restored->middleware);
}

/** @test */
public function it_round_trips_null_middleware_through_from_array(): void
{
    $type = new RecordFunctionType(
        httpMethod: 'POST',
        class: 'App\\Services\\DummyService',
        functionName: 'handle',
    );

    $restored = RecordFunctionType::fromArray($type->toArray());

    $this->assertNull($restored->middleware);
}

/** @test */
public function it_restores_middleware_via_set_state(): void
{
    $original = new RecordFunctionType(
        httpMethod: 'POST',
        class: 'App\\Services\\DummyService',
        functionName: 'handle',
        middleware: ['auth:sanctum'],
    );

    $exported = var_export($original, true);
    $restored = eval('return ' . $exported . ';');

    $this->assertInstanceOf(RecordFunctionType::class, $restored);
    $this->assertSame(['auth:sanctum'], $restored->middleware);
}

/** @test */
public function it_restores_null_middleware_via_set_state_when_absent_from_cached_config(): void
{
    // Simulates a config:cache payload that was generated before the middleware field
    // existed. The `?? null` default in __set_state must handle the missing key.
    $restored = RecordFunctionType::__set_state([
        'httpMethod'       => 'POST',
        'class'            => 'App\\Services\\DummyService',
        'functionName'     => 'handle',
        'isPublic'         => false,
        'pmsName'          => null,
        'disableCache'     => false,
        'cacheTTL'         => null,
        'description'      => null,
        'querySchema'      => null,
        'payloadSchema'    => null,
        'responseSchema'   => null,
        'clearCacheTables' => null,
        // 'middleware' intentionally absent — simulates pre-feature cached config
    ]);

    $this->assertNull($restored->middleware);
}
```

- [ ] **Step 2: Run tests to confirm they fail**

```bash
cd /Users/whitehat/Desktop/mylekha/backend/mylekha_helper/sp-laravel-api
vendor/bin/phpunit tests/Unit/RecordFunctionTypeTest.php --testdox
```

Expected: new tests FAIL (`middleware` property not yet defined)

- [ ] **Step 3: Add `middleware` to the constructor**

In `src/Types/RecordFunctionType.php`, add `middleware` as the **last** parameter (after `clearCacheTables`, ~line 103):

```php
public array|string|null $clearCacheTables = null,
public array|string|null $middleware = null,
```

- [ ] **Step 4: Update `toArray()` — emit `middleware` unconditionally**

In `src/Types/RecordFunctionType.php`, add `middleware` to the base `$config` array inside `toArray()` (~line 160-168), **alongside** `clearCacheTables` (not in the conditional block below):

```php
$config = [
    'pmsName'          => $this->pmsName,
    'isPublic'         => $this->isPublic,
    'httpMethod'       => $this->httpMethod,
    'class'            => $this->class,
    'functionName'     => $this->functionName,
    'disableCache'     => $this->disableCache,
    'cacheTTL'         => $this->cacheTTL,
    'clearCacheTables' => $this->clearCacheTables,
    'middleware'       => $this->middleware,
];
```

Do NOT add it to the conditional block below — `middleware` must always be present in the output, even when null.

- [ ] **Step 5: Update `__set_state()` — add `middleware` with null default**

In `src/Types/RecordFunctionType.php`, inside `__set_state()` (~line 134-150), add after `clearCacheTables`:

```php
clearCacheTables: $properties['clearCacheTables'] ?? null,
middleware: $properties['middleware'] ?? null,
```

- [ ] **Step 6: Update `fromArray()` — add `middleware` with null default**

In `src/Types/RecordFunctionType.php`, inside `fromArray()` (~line 214-230), add after `clearCacheTables`:

```php
clearCacheTables: $config['clearCacheTables'] ?? null,
middleware: $config['middleware'] ?? null,
```

- [ ] **Step 7: Run unit tests to confirm they pass**

```bash
vendor/bin/phpunit tests/Unit/RecordFunctionTypeTest.php --testdox
```

Expected: ALL PASS

- [ ] **Step 8: Run full test suite to confirm no regressions**

```bash
vendor/bin/phpunit --testdox
```

Expected: ALL PASS

- [ ] **Step 9: Commit**

```bash
git add src/Types/RecordFunctionType.php tests/Unit/RecordFunctionTypeTest.php
git commit -m "feat: add middleware property to RecordFunctionType"
```

---

## Task 2: Add per-function middleware lookup to `RecordRouteMiddleware`

**Files:**
- Modify: `src/Http/Middleware/RecordRouteMiddleware.php`
- Create: `tests/Feature/PerRpcMiddlewareTest.php`

- [ ] **Step 1: Write the failing feature tests**

Create `tests/Feature/PerRpcMiddlewareTest.php`:

```php
<?php

namespace Sopheak\Core\Tests\Feature;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Sopheak\Core\Tests\TestCase;
use Sopheak\Core\Types\RecordFunctionType;
use Sopheak\Core\Types\RecordTableType;
use Sopheak\Core\Utilities\SchemaRegistryUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Per-RPC middleware feature tests.
 *
 * IMPORTANT: Global function routes (/api/rpc/{functionName}) are built at boot time from
 * config('record.global_functions'). All function names used by tests must be registered in
 * getEnvironmentSetUp() so their routes exist. Per-test config overrides (via Config::set)
 * update the RecordFunctionType configs; the middleware lookup reads these at request time.
 *
 * Table function routes (/api/{table}/rpc/{functionName}) use a catch-all '.*' constraint
 * and are always registered, so table function tests do not require getEnvironmentSetUp changes.
 */
class PerRpcMiddlewareTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        // Register all function names used across tests at boot time.
        // Their configs will be overridden per-test via Config::set().
        $app['config']->set('record.global_functions', [
            'login'   => new RecordFunctionType(
                httpMethod: 'POST',
                class: RpcEchoHandler::class,
                functionName: 'handle',
                isPublic: true,
                middleware: [],   // default: public
            ),
            'profile' => new RecordFunctionType(
                httpMethod: 'GET',
                class: RpcEchoHandler::class,
                functionName: 'handle',
                isPublic: true,
                // middleware: null — falls back to middleware_map
            ),
            'logout'  => new RecordFunctionType(
                httpMethod: 'POST',
                class: RpcEchoHandler::class,
                functionName: 'handle',
                isPublic: true,
                middleware: [],   // default: public, overridden per-test
            ),
            'auth/me' => new RecordFunctionType(
                httpMethod: 'GET',
                class: RpcEchoHandler::class,
                functionName: 'handle',
                isPublic: true,
                middleware: [],   // public
            ),
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $router = $this->app['router'];
        $router->aliasMiddleware('test.require-token', RpcRequireTokenMiddleware::class);
        $router->aliasMiddleware('test.require-plan',  RpcRequirePlanMiddleware::class);

        // Default: all RPCs require a token unless overridden by function-level middleware
        Config::set('record.middleware_map', [
            'default' => [
                '*'        => [],
                'read'     => [],
                'write'    => [],
                'function' => ['test.require-token'],
            ],
            'tables' => [],
        ]);
    }

    // ── Global function tests ────────────────────────────────────────────────

    /** @test */
    public function global_function_with_empty_middleware_bypasses_middleware_map(): void
    {
        // login has middleware: [] — explicitly no middleware, map's token requirement is skipped
        $this->postJson('/api/rpc/login')
            ->assertStatus(200);
    }

    /** @test */
    public function global_function_with_null_middleware_falls_back_to_middleware_map(): void
    {
        // profile has middleware: null — map applies, requires token
        $this->getJson('/api/rpc/profile')
            ->assertStatus(401);

        $this->getJson('/api/rpc/profile', ['X-Token' => 'valid'])
            ->assertStatus(200);
    }

    /** @test */
    public function global_function_with_custom_middleware_replaces_middleware_map(): void
    {
        // Override logout to require a plan header instead of the default token
        Config::set('record.global_functions.logout', new RecordFunctionType(
            httpMethod: 'POST',
            class: RpcEchoHandler::class,
            functionName: 'handle',
            isPublic: true,
            middleware: ['test.require-plan'],
        ));

        // Token alone doesn't pass (requires plan, not token)
        $this->postJson('/api/rpc/logout', [], ['X-Token' => 'valid'])
            ->assertStatus(402);

        // Plan header passes (token not required)
        $this->postJson('/api/rpc/logout', [], ['X-Plan' => 'active'])
            ->assertStatus(200);
    }

    /** @test */
    public function global_function_with_slash_in_name_resolves_middleware_correctly(): void
    {
        // auth/me has middleware: [] — public, no token needed
        $this->getJson('/api/rpc/auth/me')
            ->assertStatus(200);
    }

    // ── Table function tests ─────────────────────────────────────────────────

    /** @test */
    public function table_function_with_empty_middleware_bypasses_middleware_map(): void
    {
        Config::set('record.tables', [
            'orders' => new RecordTableType(
                table: 'orders',
                functions: [
                    'summary' => new RecordFunctionType(
                        httpMethod: 'GET',
                        class: RpcEchoHandler::class,
                        functionName: 'handle',
                        isPublic: true,
                        middleware: [],   // public — map's token requirement is skipped
                    ),
                ],
            ),
        ]);
        SchemaRegistryUtils::refresh();

        $this->getJson('/api/orders/rpc/summary')
            ->assertStatus(200);
    }

    /** @test */
    public function table_function_with_null_middleware_falls_back_to_middleware_map(): void
    {
        Config::set('record.tables', [
            'orders' => new RecordTableType(
                table: 'orders',
                functions: [
                    'export' => new RecordFunctionType(
                        httpMethod: 'GET',
                        class: RpcEchoHandler::class,
                        functionName: 'handle',
                        isPublic: true,
                        // middleware: null — map applies
                    ),
                ],
            ),
        ]);
        SchemaRegistryUtils::refresh();

        $this->getJson('/api/orders/rpc/export')
            ->assertStatus(401);

        $this->getJson('/api/orders/rpc/export', ['X-Token' => 'valid'])
            ->assertStatus(200);
    }
}

// ── Test helpers ─────────────────────────────────────────────────────────────

class RpcEchoHandler
{
    // Returning a plain array is fine — RecordService wraps it in a 200 JsonResponse.
    public function handle(Request $request): array
    {
        return ['ok' => true];
    }
}

class RpcRequireTokenMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!$request->hasHeader('X-Token')) {
            return response()->json(['message' => 'Token required'], 401);
        }
        return $next($request);
    }
}

class RpcRequirePlanMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!$request->hasHeader('X-Plan')) {
            return response()->json(['message' => 'Plan required'], 402);
        }
        return $next($request);
    }
}
```

- [ ] **Step 2: Run tests to confirm they fail**

```bash
vendor/bin/phpunit tests/Feature/PerRpcMiddlewareTest.php --testdox
```

Expected: all tests FAIL (feature not implemented yet)

- [ ] **Step 3: Add missing `use` imports to `RecordRouteMiddleware`**

In `src/Http/Middleware/RecordRouteMiddleware.php`, add three new imports (note: `RecordConfigService` is already imported):

```php
use Sopheak\Core\Interfaces\RecordFunctionInterface;
use Sopheak\Core\Types\RecordFunctionType;
use Sopheak\Core\Utilities\SchemaRegistryUtils;
```

- [ ] **Step 4: Add `resolveFunctionConfig()` private method**

In `src/Http/Middleware/RecordRouteMiddleware.php`, add this method before `resolveActionGroup()`:

```php
private function resolveFunctionConfig(string $action, string $table, string $functionName): ?RecordFunctionType
{
    if ('global_function' !== $action && 'table_function' !== $action) {
        return null;
    }

    // $table is unused for global_function. For table_function with empty $table,
    // getTable() returns null and the registry falls back to [], causing a map fallback.
    /** @var array<string, mixed> $registry */
    $registry = 'global_function' === $action
        ? RecordConfigService::globalFunctions()
        : (SchemaRegistryUtils::getTable($table)?->functions ?? []);

    $raw = null;

    // Step 1: exact match
    if (isset($registry[$functionName])) {
        $raw = $registry[$functionName];
    } else {
        // Step 2: pattern match — e.g. config key 'order/{id}' matches request value 'order/42'
        foreach ($registry as $configuredKey => $config) {
            $pattern = preg_replace('/\{[^}]+\}/', '(\d+)', (string) $configuredKey);
            $pattern = '/^' . str_replace('/', '\/', $pattern) . '$/';

            if (preg_match($pattern, $functionName)) {
                $raw = $config;
                break;
            }
        }
    }

    if (null === $raw) {
        return null;
    }

    // Step 3: resolve to RecordFunctionType.
    // Most commonly $raw is already a RecordFunctionType instance (direct construction).
    // Early returns are used here for clarity; the final null return handles
    // all non-RecordFunctionType values (plain arrays, unresolvable strings).
    if ($raw instanceof RecordFunctionType) {
        return $raw;
    }

    if (is_string($raw) && class_exists($raw)) {
        $instance = new $raw();
        if ($instance instanceof RecordFunctionInterface) {
            return $instance->toFunctionType();
        }
        if ($instance instanceof RecordFunctionType) {
            return $instance;
        }
    }

    // Plain-array configs fall through to middleware_map (not supported by this feature)
    return null;
}
```

- [ ] **Step 5: Integrate into `resolveMiddlewares()`**

In `src/Http/Middleware/RecordRouteMiddleware.php`, modify `resolveMiddlewares()`. Insert the block **after the `$tableMap` assignment** (line ~37) and **before the `$middlewares = array_merge(...)` call** (line ~39):

```php
// Per-function middleware: if set on the function, it replaces middleware_map entirely.
// Only applies to function actions; all other actions fall through to the map below.
if ('global_function' === $action || 'table_function' === $action) {
    $functionName = (string) ($request->route('functionName') ?? '');
    $functionType = $this->resolveFunctionConfig($action, $table, $functionName);
    if (null !== $functionType && null !== $functionType->middleware) {
        return $this->sanitizeMiddlewares(
            $this->normalizeMiddlewares($functionType->middleware)
        );
    }
}

$middlewares = array_merge(   // ← this line already exists, do not duplicate it
```

- [ ] **Step 6: Run feature tests to confirm they pass**

```bash
vendor/bin/phpunit tests/Feature/PerRpcMiddlewareTest.php --testdox
```

Expected: ALL PASS

- [ ] **Step 7: Run full test suite to confirm no regressions**

```bash
vendor/bin/phpunit --testdox
```

Expected: ALL PASS

- [ ] **Step 8: Commit**

```bash
git add src/Http/Middleware/RecordRouteMiddleware.php tests/Feature/PerRpcMiddlewareTest.php
git commit -m "feat: resolve per-function middleware in RecordRouteMiddleware"
```

---

## Task 3: Final verification

- [ ] **Step 1: Run full test suite one final time**

```bash
vendor/bin/phpunit --testdox
```

Expected: ALL PASS

- [ ] **Step 2: Verify the feature end-to-end**

Confirm the final state of each changed file:
- `RecordFunctionType`: `middleware` is the last constructor param, present unconditionally in `toArray`, present with `?? null` in `__set_state` and `fromArray`
- `RecordRouteMiddleware`: `resolveFunctionConfig()` exists as a private method, early-return block is present in `resolveMiddlewares()` before the `array_merge` call

- [ ] **Step 3: Version bump (if applicable)**

Check current version in `composer.json`. Bump the patch version if the project uses manual versioning:

```bash
# Example: 0.4.31 → 0.4.32
# Edit composer.json version field, then:
git add composer.json
git commit -m "chore: bump version to 0.4.32"
```
