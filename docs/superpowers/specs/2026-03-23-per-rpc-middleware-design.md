# Per-RPC Middleware Design

**Date:** 2026-03-23
**Status:** Approved

## Problem

The package's `middleware_map` only resolves middleware down to the action-group level (`global_function`, `table_function`). There is no way to apply different middleware to individual RPC functions. `RecordFunctionType` has no `middleware` property.

## Goal

Allow per-function middleware to be declared directly on `RecordFunctionType`, covering both global functions (`POST /rpc/{fn}`) and table functions (`POST /{table}/rpc/{fn}`).

## Decisions

- **Location:** middleware declared on `RecordFunctionType` (co-located with function definition, not in `middleware_map`)
- **Conflict resolution:** function-level middleware **replaces** `middleware_map` entirely for that function; when `middleware` is `null` (default), full `middleware_map` resolution applies as before
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

**`__set_state` update** — add with null default so `config:cache` round-trips safely:
```php
middleware: $properties['middleware'] ?? null,
```

**`fromArray` update** — same:
```php
middleware: $config['middleware'] ?? null,
```
Updating `fromArray` ensures that any caller who constructs a `RecordFunctionType` from an array representation (e.g. after a `toArray()` round-trip) also receives the `middleware` field.

**`toArray` update** — emit unconditionally in the base `$config` array, following the same pattern as `clearCacheTables` (same type: `array|string|null`):
```php
$config = [
    // ... existing fields ...
    'clearCacheTables' => $this->clearCacheTables,
    'middleware'       => $this->middleware,   // ← add here
];
```

---

### 2. `RecordRouteMiddleware` — function-level middleware lookup

Add a private method `resolveFunctionConfig(string $action, string $table, string $functionName): ?RecordFunctionType` that mirrors the exact lookup logic of `RecordService`.

Note: `$table` is only used when `$action === 'table_function'`; it is ignored for `global_function`. For `table_function`, if `$table` is an empty string, `SchemaRegistryUtils::getTable('')` returns null and the function registry falls back to `[]`, so the lookup finds nothing and the caller falls through to `middleware_map`.

**Lookup logic:**

```
if action is NOT 'global_function' and NOT 'table_function':
    return null  (not a function action, skip)

get the function registry:
    global_function → RecordConfigService::globalFunctions()   ($table is not used)
    table_function  → SchemaRegistryUtils::getTable($table)?->functions ?? []

step 1 — exact match:
    if registry[$functionName] exists → $raw = registry[$functionName]

step 2 — pattern match (only if exact match failed):
    foreach registry as $configuredKey => $config:
        $pattern = preg_replace('/\{[^}]+\}/', '(\d+)', (string) $configuredKey)
        $pattern = '/^' . str_replace('/', '\/', $pattern) . '$/'
        if preg_match($pattern, $functionName):
            $raw = $config
            break

if $raw is null → return null  (not found; caller falls through to middleware_map)

step 3 — resolve config value to RecordFunctionType:
    if $raw is already a RecordFunctionType instance → return $raw directly (most common case)

    if $raw is a string and class_exists($raw):
        $instance = new $raw()
        if $instance instanceof RecordFunctionInterface → $raw = $instance->toFunctionType()
        elseif $instance instanceof RecordFunctionType  → $raw = $instance

if $raw is not a RecordFunctionType instance → return null
    (plain-array configs are not supported by this feature; they fall through to middleware_map)

return $raw
```

**Integration into `resolveMiddlewares()`:**

```
existing: resolve $table and $action from request/route

if action is 'global_function' or 'table_function':
    $functionName = (string) ($request->route('functionName') ?? '')
    $functionType = $this->resolveFunctionConfig($action, $table, $functionName)
    if $functionType !== null && $functionType->middleware !== null:
        return $this->sanitizeMiddlewares(
            $this->normalizeMiddlewares($functionType->middleware)
        )
    // null middleware → fall through to middleware_map resolution below

// existing middleware_map resolution (unchanged)
```

---

### 3. Function names with slashes (`auth/me`, `auth/password/reset`)

Slashes in function names are supported by both route variants:

**Global functions (no rpc_prefix):**
Route: `{functionName}` with `->where('functionName', $globalFunctionWhere)`
The `$globalFunctionWhere` is built by running `preg_quote` on each config key (e.g. `auth/me` → `auth\/me`), then joining as an alternation. Laravel compiles the where-constraint directly into the named capture group regex, so the router matches `auth/me` as a single `{functionName}` value including the slash. The `{functionName}` received in middleware is the literal string `auth/me` — which is also the exact config key, so exact-match lookup succeeds.

**Table functions (no rpc_prefix):**
Route: `{table}/{functionName}` with `->where('functionName', '(?!...)...+')`. The `.+` matches any character including slashes, so table function names with slashes are captured in full.

**Both variants (with rpc_prefix):**
`{functionName}` constraint is `'.*'` — full slash support.

---

### 4. Behavior matrix

| Scenario | Result |
|---|---|
| Function has `middleware` set (non-null) | Uses function middleware; `middleware_map` is ignored |
| Function has `middleware: null` (default) | Falls back to `middleware_map` resolution as before |
| Function has `middleware: []` (empty array) | Runs with no middleware — explicit opt-out |
| Function name not found in registry | Falls back to `middleware_map` resolution |
| Function registered as plain array config | Falls back to `middleware_map` resolution (not supported) |

---

### 5. Usage example

```php
// config/records/globalFunctions/auth.php

'login' => new RecordFunctionType(
    httpMethod: 'POST',
    class: \App\Record\Services\AuthService::class,
    functionName: 'login',
    isPublic: true,
    middleware: ['throttle:10,1'],         // rate-limit login attempts
),

'logout' => new RecordFunctionType(
    httpMethod: 'POST',
    class: \App\Record\Services\AuthService::class,
    functionName: 'logout',
    isPublic: false,
    middleware: ['auth:passport'],         // explicit passport guard
),

'auth/me' => new RecordFunctionType(
    httpMethod: 'GET',
    class: \App\Record\Services\AuthService::class,
    functionName: 'me',
    isPublic: false,
    // middleware omitted (null default) → uses middleware_map
),
```

---

## Files to Change

| File | Change |
|---|---|
| `src/Types/RecordFunctionType.php` | Add `middleware` param; update `__set_state`, `fromArray`, `toArray` |
| `src/Http/Middleware/RecordRouteMiddleware.php` | Add `resolveFunctionConfig()` and integrate into `resolveMiddlewares()` |

## Out of Scope

- No changes to `middleware_map` config structure
- No merging behavior — when `middleware` is non-null it replaces the map; when null the map applies fully
- No middleware support on non-function actions via `RecordFunctionType`
- Plain-array function configs do not gain per-function middleware support
