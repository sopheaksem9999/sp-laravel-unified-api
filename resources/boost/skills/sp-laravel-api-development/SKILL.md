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
vendor/bin/phpunit
```

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

