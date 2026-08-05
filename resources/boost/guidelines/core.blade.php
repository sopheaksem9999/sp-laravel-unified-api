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
    httpMethod: ['POST'],
),
</code-snippet>
@endverbatim

