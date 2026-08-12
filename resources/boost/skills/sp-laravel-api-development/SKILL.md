---
name: sp-laravel-api-development
description: Work with sp-laravel-api dynamic CRUD tables, functions, permissions, tenancy, triggers, caching, and tests.
---

# sp-laravel-api Development

## When to use this skill

Use this skill when you are adding or modifying:

- Dynamic CRUD tables (`RecordTableType`)
- Table functions and global functions
- Permission mapping / auth flags
- Tenancy behavior
- Triggers, validators, audit logging
- Query caching and invalidation

## Key rules

- Do not create standard CRUD routes or controllers. Configure tables/functions via `RecordTableType`.
- Do not create custom endpoints for listing/filtering records. Use `GET /api/v1/{table}`.
- Keep backward compatibility unless explicitly asked to break.
- Filters are `{column}={operator}.{value}` query params (e.g. `status=eq.open`) — never `filter[column]=value` or `column[operator]=value` bracket syntax; those aren't supported.
- Every create/update payload key must be a real column or declared relationship alias, or the API returns `422` naming it — don't guess field names.
- A `hasMany`/`belongsToMany`/`hasManyThrough`/`morphMany`/`morphToMany`/`morphByMany`/`spatiePermission` relationship can be written in the same request as its parent (see "Nested relationship writes" below) instead of a separate create call per child table.

## Nested relationship writes (single request)

Write a parent record and its related rows in one call instead of one request per table:

```php
// POST /api/v1/invoices
[
    'customer_id' => 10,               // belongsTo: root FK field, not a nested object
    'status' => 'draft',
    'items' => [                       // hasMany: array of id / {fields} / {id, _delete}
        1,                              // attach/reference existing item id
        ['name' => 'Line A', 'qty' => 1],   // create a new item
        ['id' => 5, '_delete' => true],     // remove an existing item
    ],
]
```

- `allowCreate`/`allowUpdate`/`allowDelete` on the relationship (default `true`) gate which of those are permitted. A disallowed operation (e.g. `_delete` when `allowDelete: false`) returns `422` and rolls back the whole request — it does not silently drop just that item.
- `belongsTo`/`hasOne`/`hasOneThrough`/`morphTo`/`morphOne` are not nested-array writes; use the root FK/discriminator column instead.

## Where things live

- Table configs: `config/records/tables/*.php` (or `config/sp-record.php`; an unmigrated project may still have `config/record.php` instead — edit whichever exists, never create both)
- Global function configs: `config/records/globalFunctions/*.php`
- CRUD orchestration: `Sopheak\Core\Services\RecordService`
- Responses: `Sopheak\Core\Services\RecordApiResponseService`
- Caching: `Sopheak\Core\Services\RecordCacheService` + `Sopheak\Core\Services\QueryCacheService`
- Permission enforcement: `Sopheak\Core\Http\Controllers\Concerns\HasControllerHelpers::authorizeAction()`

## Commands

```bash
php artisan sp-laravel-api:record {name}
php artisan sp-laravel-api:sync-record-columns --force
php artisan sp-laravel-api:generate-record-tables-from-db
php artisan sp-laravel-api:validate
php artisan sp-laravel-api:export-openapi
php artisan sp-laravel-api:export-bruno
php artisan sp-laravel-api:export-postman
vendor/bin/phpunit
```

## Live schema lookups (MCP)

Prefer the `sp-laravel-api` MCP tools over guessing at schema/endpoints:

- `sp_api_list_endpoints` — all registered table/function endpoints
- `sp_api_get_endpoint` — live schema for one endpoint (columns, relationships, auth flags)
- `sp_api_list_permissions` — permission map (pmsName + can* flags + custom permissions)

## Debug a failing record request

Follow this loop in order:

1. **Reproduce the exact request** — method, path, query params, tenant headers (`X-Tenant-ID`), payload.
2. Run `php artisan sp-laravel-api:validate` and fix any reported config issues.
3. Confirm the table is registered — `php artisan sp-laravel-api:list-tables` (or `cache-status` if present); stale schema registry caches are a common cause.
4. Inspect the endpoint's live schema via `sp_api_get_endpoint` (columns, relationship aliases, `isAuthRead`/`isAuthWrite`, `can*` flags).
5. Replay via the exported collection (`sp-laravel-api:export-bruno` / `:export-postman`).
6. Check the response against the `RecordApiResponseService` contract (`success`, `error_code`, `data`, `meta`).
7. For auth/tenant 403s: walk the permission flow (`HasControllerHelpers::authorizeAction()` order), check `sp_api_list_permissions`, verify tenant resolution precedence (request attr → `record_context.tenant_id` → `X-Tenant-ID` header).

## Quality gates before done

Before claiming a change works, run in order and add tests for any behavior change (tenant, auth, filters, permission, triggers, response wrapper):

```bash
composer format-check   # rector:check (dry-run)
composer analyse        # phpstan
composer test           # phpunit
```

Fix any failures, then add/adjust tests and re-run until green.

## Caching mental model

- Cache keys are versioned using namespace tokens so invalidation works across all cache drivers.
- Tenant-aware invalidation should be preferred to avoid cross-tenant cache stampedes.
- Manual clear should call `RecordCacheService` methods (table / table function / global function).

## Example: new table config

```php
<?php

use Sopheak\Core\Types\RecordTableType;

return new RecordTableType(
    pmsName: 'invoice',
    table: 'invoices',
    isAuthRead: true,
    isAuthWrite: true,
    softDeletes: true,
    hasTenantId: true,
    relationships: [],
    functions: [],
    triggers: [],
);
```

