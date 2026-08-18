## sp-laravel-api (Sopheak\Core)

This package provides a config-driven dynamic CRUD API for database tables and custom RPC functions.

### Core Rules

- Do not create normal CRUD routes in `routes/api.php`. Standard endpoints are automatically provided via the package runtime.
- Do not create standard CRUD controllers. Use `RecordTableType` configuration.
- Do not create custom RPC endpoints just to list/filter records. Use `GET /api/v1/{table}` with filters, pagination, and relationship loading.

### Where to Configure Endpoints

- Table CRUD endpoints: `config/sp-record.php` and/or `config/records/tables/*.php` returning `RecordTableType`. A project that has not migrated yet may still have `config/record.php`; edit whichever file is already there and do not create the other.
- Table-specific function endpoints: `RecordTableType::$functions`
- Global function endpoints: `config/records/globalFunctions/*.php`

### Auth / Permissions Mental Model

- Auth flags: `RecordTableType::$isAuthRead` and `RecordTableType::$isAuthWrite`
- Permission enforcement: `HasControllerHelpers::authorizeAction()` (table public/auth check, resolve user, map permission, optional custom authorizer, Gate fallback)
- Tenancy: resolved in request attributes first, then record_context, then tenant header (`record.tenant_header`)

### Caching (Important)

- Request caching is controlled by `RecordCacheService::isCacheableRequest()` and table config (`disableCache`) plus per-table overrides.
- Invalidation uses namespace versioning (no Redis `KEYS`, no DB `LIKE`, no cache tags) so it works across all Laravel cache drivers:
  - CRUD write or manual clear bumps a small namespace version key.
  - Reads automatically use the latest namespace token.
  - Old cached entries expire by TTL.

### Filtering

- Filters are top-level query parameters in the form `{column}={operator}.{value}` (e.g. `status=eq.open`, `created_at=gte.2026-08-01T00:00:00.000`). Do not wrap filters in a `filter[...]` key or use bracket syntax like `column[operator]=value` — that shape is rejected (or silently ignored), not supported.
- Combine filters on the same column with grouped logic: `and=(created_at.gte.X,created_at.lte.Y)`.

### Create/Update Payloads

- Every top-level payload key must be a real column or a declared relationship alias for that table. An unknown key (typo or invented field) returns `422` naming it and listing every valid column/relationship, instead of being silently dropped and the write succeeding anyway.
- Nested relationship writes (single request): a relationship declared `hasMany`, `belongsToMany`, `hasManyThrough`, `morphMany`, `morphToMany`, `morphByMany`, or `spatiePermission` can be written in the *same* create/update payload as the parent — no separate request per child table. Send an array mixing plain ids (`1`, attach/reference), objects (`{"name": "..."}`, create), and `{"id": 5, "_delete": true}` (remove).
- `belongsTo`/`hasOne`/`hasOneThrough`/`morphTo`/`morphOne` are **not** nested-array writes — set the root FK/discriminator field instead (e.g. `"customer_id": 10`, not `"customer": {"id": 10}`).
- `allowCreate`/`allowUpdate`/`allowDelete` (default `true` each) on a relationship gate which nested operations are permitted. Sending a disallowed one (e.g. a `_delete` item when `allowDelete: false`) returns `422` and rolls back the entire request — it does not silently skip just that item.

### Triggers / Validators

You can attach triggers and validators using either:

- `RecordTableType` hook properties (`beforeCreate`, `afterUpdate`, etc.)
- or a `RecordTableType::$triggers` array of handler classes that use attributes:
  - `#[RecordTrigger('beforeCreate')]`
  - `#[RecordValidator('create')]`

### Common Workflows

#### Add a New Table

1. Create config:
   - `php artisan sp-laravel-api:record users --table=users --pms-name=user --tenant`
2. Sync columns:
   - `php artisan sp-laravel-api:sync-record-columns --force`
3. Validate setup:
   - `php artisan sp-laravel-api:validate`

#### Add a Table Function

Add to the table config `functions:`:

@verbatim
<code-snippet name="Table function example" lang="php">
'recalculate' => new \Sopheak\Core\Types\RecordFunctionType(
    type: 'class',
    class: \App\Services\InvoiceService::class,
    functionName: 'recalculate',
    httpMethod: [\Sopheak\Core\Enums\RecordFunctionMethodEnum::POST->value],
),
</code-snippet>
@endverbatim
