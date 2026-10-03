---
title: "Configuration and Middleware"
description: "Base config, API prefix, global RPC prefix, table configuration files, middleware stack, middleware map, and request context."
keywords:
  - configuration
  - api prefix
  - rpc prefix
  - middleware map
  - request context
  - table files
---

# Configuration and Middleware

### API Prefix

All endpoints are served under a configurable prefix defined in `config/record.php`:

```php
'api_prefix' => 'api/v1',
'rpc_prefix' => 'rpc',
```

**Default**:

- CRUD API: `/api/v1`
- Global RPC: `/api/v1/rpc`

**Examples**: `/api`, `/api/v1`, `/api/v2`

### Global RPC

Global functions can be executed via the configured RPC prefix. Nested function names are supported.

`POST /api/v1/rpc/{functionName}`

Example:

- `POST /api/v1/rpc/auth/login`
- `POST /api/v1/rpc/system/status`

### Table Configuration Files

Record behavior is driven by `RecordTableType` configurations defined in `config/record.php` and (optionally) in per-table files under `config/records/tables`.

- `config/record.php` contains global options and can inline smaller schemas.
- `config/records/tables/{name}.php` can return a single `RecordTableType` or an array of `[table_name => RecordTableType]` for large schemas.

You can scaffold a new per-table configuration file via Artisan:

```bash
php artisan sp-laravel-api:record customers
```

This creates `config/records/tables/customers.php` with a basic `RecordTableType` definition for the `customers` table. After creating the file and the corresponding database table, you can populate the `columns` metadata from the database schema:

```bash
# Create configs for all tables in your database (ignores system/package tables)
php artisan sp-laravel-api:generate-record-tables-from-db

# Sync columns from database into existing config files (ignores system/package tables)
php artisan sp-laravel-api:sync-record-columns --force
```

Record endpoints use table-level access rules from `config/record.php`:

- If a table/action is configured as public (`RecordTablePublic`), the endpoint is accessible without authentication.
- Otherwise, the controller requires an authenticated user from the guard configured in `config/sp-laravel-api.php` (`sp-laravel-api.auth.guard`, default: `api`) and checks permissions.
- Permission checks support a custom authorization handler via `record.authorization`.
- A user for whom `permissions.super_admin_callback` returns `true` passes every permission check. The same decision (`PermissionUtils::actionDecision()`) authorizes the REST endpoints, the MCP and AI SDK tools, and every nested child write (against the child table).

Custom authorization handler (`record.authorization`) options:

- `null` (default): use `Gate::forUser($user)->allows($permission)`; with `permissions.enabled`, the built-in module answers through its `Gate::before` hook (see [Laravel Gate, `@can` and Telescope](/guide/feature-permission#laravel-gate-can-and-telescope)).
- class-string: resolved from container and called as `handle($user, $permission, $table, $action): bool`
- closure/callable: called as `fn($user, string $permission, string $table, string $action): bool`

`$action` is the action being authorized (`read`, `create`, `update`, `delete`, `restore`, `force_delete`), or `view_own` when the package asks whether the user holds `viewOwn:{pmsName}`. For a nested child write, `$table` is the child table. A handler that throws for a `viewOwn:*` permission is reported and treated as not granting it.

Example:

```php
// config/record.php
'authorization' => \App\Security\RecordAuthorization::class,
```

```php
<?php

namespace App\Security;

final class RecordAuthorization
{
    public function handle(mixed $user, string $permission, string $table, string $action): bool
    {
        return \Illuminate\Support\Facades\Gate::forUser($user)->allows($permission);
    }
}
```

### MCP Endpoints

The Data MCP is configured under `record.mcp`, the Schema MCP in `config/sp-api-mcp.php`. See [MCP Support](/guide/module-mcp).

| Key | Env | Default | Effect |
|---|---|---|---|
| `record.mcp.enabled` | `SP_MCP_ENABLED` | `false` | Registers the Data MCP routes |
| `record.mcp.read_only` | `SP_MCP_READ_ONLY` | `true` | Removes the `create_*`, `update_*`, `delete_*` tools (MCP only; the [AI SDK record tools](/guide/module-ai-sdk) ignore it) |
| `record.mcp.driver` | `SP_MCP_DRIVER` | `legacy` | `legacy` (the package's own JSON-RPC server) or `laravel` (`laravel/mcp`; `composer require laravel/mcp`) |
| `record.mcp.oauth` | `SP_MCP_OAUTH` | `false` | OAuth discovery routes; `laravel` driver and `laravel/passport` only |
| `record.mcp.run_record_hooks` | `SP_MCP_RUN_RECORD_HOOKS` | `true` | Run record hooks, validators, after-hooks/webhooks and broadcasts for the MCP and AI SDK tools |
| `record.mcp.middleware` | — | `['api', 'auth:sanctum']` | Middleware on the Data MCP routes |
| `record.mcp.route_prefix` | `SP_MCP_ROUTE_PREFIX` | `mcp` | **Deprecated, no effect** — routes are always `/{api_prefix}/mcp/...` |
| `sp-api-mcp.enabled` | `SP_API_MCP_ENABLED` | `false` | Registers `POST /{api_prefix}/mcp/schema` |
| `sp-api-mcp.token` | `SP_API_MCP_TOKEN` | `null` | Schema MCP bearer token; required outside `local` |

`record.middleware_map` and the `throttle:api-*` groups do not apply to the Data MCP routes — add tenant middleware, `pgsql.tenant` and any `throttle:` limiter to `record.mcp.middleware`. Tool permission checks read the user from `sp-laravel-api.auth.guard` (default `api`), like the CRUD controller, so that guard must accept the same credential as `record.mcp.middleware`.

### Middleware Stack

- `api` - API middleware group
- `request.id` - Request ID tracking for audit trails
- Rate limiting with different throttles for different operation types
- `record.route.middleware:{action}` - Dynamic middleware dispatcher resolved from `config/record.php` `middleware_map`
- Data MCP routes get `api`, `request.id` and `record.mcp.middleware` only (no throttle group, no `record.route.middleware`)

### Middleware Map (Public / Auth / Auth+Subscription)

Use `middleware_map` in `config/record.php` to apply middleware by endpoint group/action and per table.
For a focused version (merge order, groups, function override), see [Record Middleware Map](/guide/record-middleware-map).

```php
'middleware_map' => [
    'default' => [
        '*' => [],
        'read' => [],
        'write' => ['auth:sanctum'],
        'function' => ['auth:sanctum'],
    ],
    'tables' => [
        'customers' => [
            'read' => [],
        ],
        'orders' => [
            'write' => ['auth:sanctum', 'subscribed'],
            'table_function' => ['auth:sanctum', 'subscribed'],
        ],
    ],
],
```

How this matches common client requirements:

- Public query route: keep `read` empty (or only safe middleware like throttling).
- Auth-only route: use `write => ['auth:sanctum']` or per-action `create`, `update`, `delete`.
- Auth + subscription route: add `subscribed` in table/action stack (e.g. `orders.write`).

### Request Context in Hooks and Custom Audit

Request context is available to hooks and custom audit callback via:
For a focused tenancy setup/runtime guide, see [Record Tenancy](/guide/record-tenancy).

- `$context['request_context']` in trigger/audit callback params
- `request()->attributes->get('record_context')`

Built-in `request_context` payload:

- `tenant_id` (`string|int|null`)
- `tenant_column` (`string`, usually `tenant_id`)
- `tenant_source` (`attribute|header|null`)
- `user` (`array|null`) with keys:
  - `id` (`mixed`)
  - `guard` (`string`)
- `request_id` (`string|null`)
- `table` (`string`)
- `action` (`string|null`)

Source priority behavior (built-in):

- `attribute`: recommended for trusted middleware-populated tenant (`resolved_tenant_id`) or context tenant (`record_context.tenant_id`)
- `header`: fallback to tenant header (`X-Tenant-ID` by default)

This same priority is also used by Eloquent trait filtering (`QueryHelpersTrait::scopeApplyRequestFilters`), so model queries remain aligned with dynamic CRUD tenant behavior.

Tenant filtering in `QueryHelpersTrait` is applied when tenant mode is enabled and tenant column exists by any of:

- model `fillable`
- registered `RecordTableType` columns
- database schema column check

Example middleware to set trusted tenant (`resolved_tenant_id`) and enrich `record_context`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ResolveTenantContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenantId = $request->user()?->tenant_id;

        if ($tenantId !== null && $tenantId !== '') {
            $request->attributes->set('resolved_tenant_id', $tenantId);
        }

        $context = $request->attributes->get('record_context', []);
        if (!is_array($context)) {
            $context = [];
        }

        $context['tenant_id'] = $tenantId;
        $context['tenant_source'] = 'attribute';
        $context['client_app'] = 'backoffice';
        $request->attributes->set('record_context', $context);

        return $next($request);
    }
}
```

Then register middleware in `record.middleware_map` for table/action route groups so CRUD/trigger flow can consume the context.

Example trigger (`beforeCreate`) using context for auto value:

```php
public static function beforeCreate(\Illuminate\Http\Request $request, string $table, array $context): array
{
    $ctx = $context['request_context'] ?? $request->attributes->get('record_context', []);
    $tenantId = $ctx['tenant_id'] ?? null;
    $userId = $ctx['user']['id'] ?? null;

    $payload = $request->all();
    $payload['tenant_id'] = $tenantId;
    $payload['created_by_id'] = $userId;
    $request->replace($payload);

    return [$request, $table, $context];
}
```

## Related Docs

- [Validation](/guide/api-validation) — table validators and default validation
- [Record Hooks](/guide/record-hooks) — lifecycle triggers
- [Record Middleware Map](/guide/record-middleware-map)
- [Record Tenancy](/guide/record-tenancy)
