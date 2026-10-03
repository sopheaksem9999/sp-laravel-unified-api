---
title: "Record Tenancy (Config and Runtime Usage)"
description: "Focused guide for tenancy setup and usage in sp-laravel-api, including enable flags, tenant resolution priority, validation behavior, and request flow."
keywords:
  - record tenancy
  - enable_tenant_id
  - hasTenantId
  - tenant_header
  - tenant_column
  - resolved_tenant_id
  - record_context tenant_id
  - X-Tenant-ID
---

# Record Tenancy

This guide explains how to configure and use tenancy in `sopheak/sp-laravel-api`.

## Tenancy Activation Rule

Tenant scoping is active only when both are true:

1. Global config enables tenancy: `record.enable_tenant_id = true`
2. Target table is tenant-aware: `RecordTableType(hasTenantId: true)`

If either is false, tenant filtering is not applied for that table.

## Global Config

Set in `config/record.php`:

```php
'enable_tenant_id' => true,
'tenant_column' => 'tenant_id',
'tenant_header' => 'X-Tenant-ID',
```

## Table Config

Enable per table in `RecordTableType`:

```php
'invoices' => new RecordTableType(
    table: 'invoices',
    hasTenantId: true,
    // ...
),
```

## Tenant Resolution Priority

Runtime resolves tenant in this order:

1. `request->attributes['resolved_tenant_id']`
2. `request->attributes['record_context']['tenant_id']`
3. Request header (`record.tenant_header`, default `X-Tenant-ID`)

This behavior is implemented in `RecordUtils::resolveTenantIdFromRequest()`.

## Validation Behavior

When tenant scoping is active and tenant ID is missing, request is rejected with validation error for tenant header.

Example message pattern:

- `header X-Tenant-ID cannot be empty`

A table that is not tenant-scoped answers `422` too when the request embeds or
filters on a tenant-scoped relationship (`?select=*,pets(*)`, `?with=pets(*)`,
`?pets.name=eq.x`, `?or=(pets.name.eq.x,…)`, a `search` over a `searchable` `pets.*` column, at any include depth, or through a tenant-scoped pivot) and no tenant resolved — otherwise those rows would come back
for every tenant:

- `header X-Tenant-ID cannot be empty: it is required to include pets`

## Runtime Usage

With tenancy active (`enable_tenant_id + hasTenantId`), the package auto-scopes:

- List/read queries
- Create/update/delete/restore/upsert writes
- Bulk operations
- Cache keys and cache invalidation scopes
- Relationship includes (`?select=*,rel(*)`), relationship filters (`?rel.col=eq.x`) and nested writes into a tenant-scoped table, using the request tenant even when the parent table is not tenant-scoped

No manual `where('tenant_id', ...)` is needed in normal CRUD flow.

## Trusted Tenant Middleware Pattern

Use custom middleware to attach trusted tenant context before CRUD flow:

```php
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
        $request->attributes->set('record_context', $context);

        return $next($request);
    }
}
```

Then apply it via `record.middleware_map` on desired route groups/tables. For the Data MCP routes add it to `record.mcp.middleware` too, after the auth middleware (`['api', 'auth:sanctum', \App\Http\Middleware\ResolveTenantContext::class]`) — `middleware_map` is not applied there, and without it only the tenant header is left.

## MCP and AI SDK Tools

The MCP data tools and the AI SDK record tools use the same resolution priority, but never take the tenant from tool arguments:

- HTTP MCP: from the MCP request, in the priority above. `record.middleware_map` does not run on the MCP routes — put your tenant middleware in `record.mcp.middleware`.
- stdio (`php artisan sp-laravel-api:mcp`): `--tenant=<id>`.
- AI SDK tools: the current request, or `RecordTools::…->forTenant($tenantId)` for queued/background agents.

A `tenantId` tool argument is only an assertion: it must equal the resolved tenant, or the call is refused (`-32001`).

When tenancy is on and no tenant resolves:

| Call | Result |
|---|---|
| Any action on a `hasTenantId: true` table | `-32001` `Tenant context is required for {table} but none was resolved from the request.` |
| A `hasTenantId: false` table whose query includes a tenant-scoped relation (`select=*,rel(*)`, `with=rel(*)`, `rel.column=…`, `or=(rel.column.…)`, a `searchable` `rel.*` search, at any include depth or through a tenant-scoped pivot) | `-32001` `Tenant context is required to include {aliases}, but none was resolved from the request.` |
| A `hasTenantId: false` table without such includes | runs unscoped |

Over HTTP a tenant-scoped table answers `422` (`header X-Tenant-ID cannot be empty`) instead. The AI SDK tools hand these errors to the model as `{"error": {"code": -32001, "message": …}}`.

## QueryHelpers Compatibility

`QueryHelpersTrait::applyRequestFilters` follows the same tenant resolution priority, so legacy model-based query helpers stay aligned with dynamic CRUD tenant behavior.

## OpenAPI Behavior

When tenancy is active and table is tenant-aware, OpenAPI includes tenant header parameter for that table endpoints.

## Quick Checklist

1. Set `record.enable_tenant_id=true`
2. Set `hasTenantId: true` on tenant tables
3. Send `X-Tenant-ID` (or your configured header) on requests
4. Optionally use trusted middleware with `resolved_tenant_id`
5. Keep middleware_map aligned with your auth/subscription policy
6. With MCP on, add the tenant middleware to `record.mcp.middleware`; use `--tenant` for stdio and `forTenant()` for queued AI agents
