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

## Read the docs FIRST — before reading source

The package ships a docs guide under `vendor/sopheak/sp-laravel-api/docs/`.
It documents features, config keys, auth flows, and gotchas that reading source
alone can miss (or misread). **Always check the matching doc page before
opening `src/` to debug or implement.**

Routing map (task → doc to read first):

| Task | Doc |
|---|---|
| Any attachment work (upload, view, download, folders, visibility, temp) | `docs/features/attachments.md` + `docs/guide/features/feature-attachments-*.md` |
| Permissions / roles / auth flags / own-records scoping | `docs/guide/features/feature-permission.md` |
| `created_by` / `updated_by` / userstamps | `docs/guide/features/feature-userstamps.md` |
| Audit logging | `docs/features/audit-logging.md` + `docs/guide/features/feature-audit-*.md` |
| Table/type config reference (`RecordTableType`, relationships) | `docs/guide/api/api-type-reference-and-examples.md` |
| CRUD / filter / select / pagination behavior | `docs/guide/api/api-crud-operations.md` + `docs/guide/api/api-index.md` |
| Tenancy | `docs/guide/records/record-tenancy.md` (if present) or grep docs for tenant |
| Caching / invalidation | `docs/guide/records/record-cache.md` |
| Triggers / hooks / validators | `docs/guide/records/record-hooks.md` + `docs/guide/api/api-config-validation-triggers.md` |
| Response envelope / errors / rate limits | `docs/guide/api/api-errors-rate-security.md` |

Rules:

- If the doc exists for the task, read it before `src/` — it is the faster,
  authoritative answer. Go to source only for what the doc doesn't cover.
- If the doc and source disagree, **flag it** — docs should match the code.
- When your change alters behavior, config keys, or endpoints, update the doc
  in the same change (frontmatter + per `docs/docs-authoring-guide.md`).

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
- `sp_api_get_endpoint` — live schema for one endpoint (columns, relationships, auth flags); pass `actions: ["list", "create"]` to keep it small, and follow any `{"$ref": "#/…"}` it contains
- `sp_api_list_permissions` — permission map (pmsName + can* flags + custom permissions)
- `sp_api_get_api_guidance` — headers, query syntax, operators for the current database, paging, errors, rate limits, nested-write rules and recipes for the enabled modules; read it once

## Debug a failing record request

Follow this loop in order — **docs before source**:

1. **Reproduce the exact request** — method, path, query params, tenant headers (`X-Tenant-ID`), payload.
2. **Read the doc for the failing area first** (see the routing map above). The doc usually names the config key, auth order, or gotcha directly; source diving without it wastes cycles and can mislead.
3. Run `php artisan sp-laravel-api:validate` and fix any reported config issues.
4. Confirm the table is registered — `php artisan sp-laravel-api:list-tables` (or `cache-status` if present); stale schema registry caches are a common cause.
5. Inspect the endpoint's live schema via `sp_api_get_endpoint` (columns, relationship aliases, `isAuthRead`/`isAuthWrite`, `can*` flags).
6. Replay via the exported collection (`sp-laravel-api:export-bruno` / `:export-postman`).
7. Check the response against the `RecordApiResponseService` contract (`success`, `error_code`, `data`, `meta`).
8. For auth/tenant 403s: walk the permission flow (`HasControllerHelpers::authorizeAction()` order), check `sp_api_list_permissions`, verify tenant resolution precedence (request attr → `record_context.tenant_id` → `X-Tenant-ID` header).
9. **Only now read `src/`** — for whatever the doc + schema inspection didn't answer.

## Quality gates before done

Before claiming a change works, run in order and add tests for any behavior change (tenant, auth, filters, permission, triggers, response wrapper):

```bash
composer format-check   # rector:check (dry-run)
composer analyse        # phpstan
composer test           # phpunit
```

Fix any failures, then add/adjust tests and re-run until green.

If behavior, config keys, or endpoints changed, update the matching docs page
in the same change and run `composer docs:validate` — see the routing map
above and `docs/docs-authoring-guide.md`.

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
