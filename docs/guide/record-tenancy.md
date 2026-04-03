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

## Runtime Usage

With tenancy active (`enable_tenant_id + hasTenantId`), the package auto-scopes:

- List/read queries
- Create/update/delete/restore/upsert writes
- Bulk operations
- Cache keys and cache invalidation scopes

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

Then apply it via `record.middleware_map` on desired route groups/tables.

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
