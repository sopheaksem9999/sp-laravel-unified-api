# Per-RPC Middleware Design

**Date:** 2026-03-23
**Status:** Approved

## Problem

The package's `middleware_map` only resolves middleware down to the action-group level (`global_function`, `table_function`). There is no way to apply different middleware to individual RPC functions. `RecordFunctionType` has no `middleware` property.

## Goal

Allow per-function middleware to be declared directly on `RecordFunctionType`, covering both global functions (`POST /rpc/{fn}`) and table functions (`POST /{table}/rpc/{fn}`).

## Decisions

- **Location:** middleware declared on `RecordFunctionType` (co-located with function definition, not in `middleware_map`)
- **Conflict resolution:** function-level middleware **replaces** `middleware_map` entirely for that function
- **Scope:** applies to both global functions and table functions

## Design

### 1. `RecordFunctionType` — new `middleware` property

Add an optional `middleware` parameter with default `null`:

```php
public function __construct(
    public array|string|RecordFunctionMethodEnum $httpMethod,
    public string $class,
    public string $functionName,
    public bool $isPublic = false,
    public array|string|null $pmsName = null,
    public bool $disableCache = false,
    public ?int $cacheTTL = null,
    public ?string $description = null,
    public ?array $querySchema = null,
    public ?array $payloadSchema = null,
    public ?array $responseSchema = null,
    public array|string|null $clearCacheTables = null,
    public array|string|null $middleware = null,  // ← new
)
```

Also update `__set_state`, `fromArray`, and `toArray` to include `middleware` so `php artisan config:cache` continues to work.

### 2. `RecordRouteMiddleware` — function-level middleware lookup

In `resolveMiddlewares()`, after the existing table/action resolution, add a step for function actions:

```
if action is 'global_function' or 'table_function':
    get functionName from route parameter
    look up RecordFunctionType from config:
        global_function → config('record.global_functions')[functionName]
        table_function  → config('record.tables.{table}.functions')[functionName]
    if RecordFunctionType found and middleware is not null:
        return sanitizeMiddlewares(normalizeMiddlewares(middleware))
        // does NOT fall through to middleware_map
    else:
        fall through to normal middleware_map resolution
```

The lookup must handle both `RecordFunctionType` objects and legacy array configs.

### 3. Behavior matrix

| Scenario | Result |
|---|---|
| Function has `middleware` set (non-null) | Uses function middleware, `middleware_map` is ignored |
| Function has `middleware: null` (default) | Falls back to `middleware_map` as before |
| Function has `middleware: []` (empty array) | Runs with no middleware — explicit opt-out |

### 4. Usage example

```php
// config/records/globalFunctions/auth.php

'login' => new RecordFunctionType(
    httpMethod: 'POST',
    isPublic: true,
    middleware: ['throttle:10,1'],        // rate-limit login attempts
),

'logout' => new RecordFunctionType(
    httpMethod: 'POST',
    isPublic: false,
    middleware: ['auth:passport'],        // explicit passport guard
),

'auth/me' => new RecordFunctionType(
    httpMethod: 'GET',
    isPublic: false,
    // middleware: null (default) → uses middleware_map
),
```

## Files to Change

| File | Change |
|---|---|
| `src/Types/RecordFunctionType.php` | Add `middleware` param; update `__set_state`, `fromArray`, `toArray` |
| `src/Http/Middleware/RecordRouteMiddleware.php` | Add function-level middleware lookup in `resolveMiddlewares()` |

## Out of Scope

- No changes to `middleware_map` config structure
- No merging behavior — it is always replace-or-fallback
- No middleware support on non-function actions via `RecordFunctionType`
