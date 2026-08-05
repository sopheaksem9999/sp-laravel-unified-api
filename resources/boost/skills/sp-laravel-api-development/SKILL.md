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

