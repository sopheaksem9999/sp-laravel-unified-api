---
title: "Record Type Reference and Class-Based Examples"
description: "RecordTableType parameters, class-based configuration patterns, and Record* type reference for functions, triggers, and relationships."
keywords:
  - RecordTableType
  - RecordFunctionType
  - RecordTableTriggerType
  - RecordValidationType
  - relationship types
---

### RecordTableType Parameters

`RecordTableType` configures a table’s routing, access control, caching, tenancy, validation, and lifecycle hooks.

Constructor (named arguments recommended):

```php
new RecordTableType(
    pmsName: 'invoice',
    table: null,
    hasTenantId: false,
    softDeletes: false,
    disableAuditLog: false,
    disableCache: false,
    disableBroadcast: false,
    canRead: true,
    canCreate: true,
    canUpdate: true,
    canDelete: true,
    isAuthRead: true,
    isAuthWrite: true,
    public: new RecordTablePublic(),
    relationships: [],
    functions: [],
    primaryKey: 'id',
    columns: [],
    columnHiddens: [],
    columnWriteDisabled: [],
    columnIndexes: [],
    searchable: [],
    auditLogFn: null,
    createValidator: null,
    updateValidator: null,
    deleteValidator: null,
    beforeRead: null,
    afterRead: null,
    beforeCreate: null,
    afterCreate: null,
    beforeUpdate: null,
    afterUpdate: null,
    beforeDelete: null,
    afterDelete: null,
    triggers: null,
);
```

#### Identity & Routing

- `pmsName` (?string, default: `null`): Used for permission mapping (e.g. `view:{pmsName}`). If `null`, it falls back to the table name (singular, snake_case) for permission generation.
- `table` (?string, default: `null`): Physical database table name. When `null`, the route table name is used as the DB table name.
- `primaryKey` (?string, default: `'id'`): Primary key column name used by show/update/delete endpoints.

#### Tenancy

- `hasTenantId` (bool, default: `false`): Marks this table as tenant-scoped when `record.enable_tenant_id` is enabled. When enabled and `hasTenantId` is true, requests must include the tenant header (default: `X-Tenant-ID`) and queries are automatically filtered by tenant.

#### Access Control & Endpoint Availability

- `isAuthRead` (bool, default: `true`): Auth requirement flag for read endpoints. `true` forces authentication, `false` makes read endpoints public.
- `isAuthWrite` (bool, default: `true`): Auth requirement flag for write endpoints. `true` forces authentication, `false` makes write endpoints public.
- `public` (RecordTablePublic|bool, legacy compatibility): Derived from `isAuthRead`/`isAuthWrite` for backward compatibility. New config should prefer auth flags directly.
- `canRead` (bool, default: `true`): Enables/disables read endpoints for this table (list/show). When false, read routes respond as “not found”.
- `canCreate` (bool, default: `true`): Enables/disables create endpoint.
- `canUpdate` (bool, default: `true`): Enables/disables update and restore endpoints.
- `canDelete` (bool, default: `true`): Enables/disables delete and force-delete endpoints.

#### Soft Deletes

- `softDeletes` (bool, default: `false`): When true, list endpoints exclude `deleted_at` rows by default and restore/force-delete endpoints become relevant.

#### Caching & Audit

- `disableCache` (bool, default: `false`): Disables query caching for this table (even if `record.cache.enabled` is true).
- `disableAuditLog` (bool, default: `false`): Disables audit log inserts for create/update/delete on this table.
- `disableBroadcast` (bool, default: `false`): Suppresses `RecordMutated` broadcast events for this table even when `record.broadcast_events` is globally enabled. Useful for high-volume tables where real-time broadcasting is not needed.
- `auditLogFn` (?string, default: `null`): Reserved for custom audit log behavior; not used by the current runtime.

#### Schema & Search Metadata

- `columns` (?array, default: `[]`): Column metadata map. In normal usage this is populated at runtime from the database schema; leaving it empty is expected. It is used to whitelist payload fields and to detect audit columns like `created_by`, `created_by_id`, `updated_by`, `last_updated_by`, and `last_updated_by_id`.
- `columnHiddens` (?array, default: `[]`): List of column names to always hide from API responses. This is applied recursively to nested relationships as well. Hidden columns are removed even if their value is `null`.
- `columnWriteDisabled` (?array, default: `[]`): List of column names that are not writable via API payloads (create, update, upsert, and nested relationship writes). These columns are stripped from incoming payloads even if provided by the client.
- `columnIndexes` (?array, default: `[]`): Declares full-text index column sets for search optimization. Format: a list of column name arrays, e.g. `[['name', 'description'], ['content']]`.
- `searchable` (?array, default: `[]`): Explicit fields used by the `?search=` query param. Supports root columns like `'name'` and one-level relationship fields like `'customer.display_name'` or `'items.description'`.

#### Relationships & Table RPC Functions

- `relationships` (?array, default: `[]`): Map of relationship name => relationship config object (e.g. `RecordHasManyType`, `RecordBelongsToType`, `RecordMetaBelongsToManyType`, etc.). Used by `select` relationship includes and nested relationship selections.
- `functions` (?array, default: `[]`): Map of function route name => function config (`RecordFunctionType` or array config). These are exposed under the table RPC route (e.g. `/{api_prefix}/{table}/rpc/{function}`) and can enforce permissions via `pmsName`. Use `disableCache` and `cacheTTL` to control function caching.

#### Validators

- `createValidator`, `updateValidator`, `deleteValidator` (`Closure|RecordValidationType|array|string|null`, default: `null`): Validation rules for write operations. Can be:
  - An array of Laravel validation rules.
  - A `RecordValidationType` instance.
  - A closure returning rules or a `RecordValidationType`.
  - A string representing a class name containing `#[RecordValidator]` attributes.

#### Computed Attributes

- `attributes` (?array, default: `null`): Map of computed field name => callable resolver. Resolvers are **lazy** — they only execute when the field name is explicitly listed in the `?select=` query parameter. When no `?select=` is provided, or the field is not in the requested columns, the resolver is never called.

Supported resolver formats:

| Format | Example |
|---|---|
| Closure | `fn($row, $table) => value` |
| `[Class, method]` array | `[BrandAttribute::class, 'getLogoUrl']` |
| `'Class@method'` string | `BrandAttribute::class . '@getLogoUrl'` |
| `'ClassName'` string | `BrandAttribute::class` → calls `handle($row, $table)` |

The resolver class is resolved via the Laravel container (`app()`), so constructor injection works. `$row` is the raw DB row (`stdClass` in most cases).

**Config example:**

```php
use Sopheak\Core\Types\RecordTableType;

'brands' => new RecordTableType(
    table: 'brands',
    attributes: [
        'full_label' => [\App\Attributes\BrandAttribute::class, 'getFullLabel'],
        'logo_url'   => \App\Attributes\BrandAttribute::class . '@getLogoUrl',
        'is_premium' => fn($row, $table) => ($row->tier ?? null) === 'premium',
    ],
),
```

**Attribute class example:**

```php
namespace App\Attributes;

class BrandAttribute
{
    public function getFullLabel(mixed $row, string $table): string
    {
        return ($row->name ?? '') . ' (' . ($row->code ?? '') . ')';
    }

    public function getLogoUrl(mixed $row, string $table): ?string
    {
        $path = $row->logo_path ?? null;
        return $path ? config('app.url') . '/storage/' . $path : null;
    }
}
```

**Requesting computed attributes via `?select=`:**

```
GET /api/v1/brands                               → no resolvers called
GET /api/v1/brands?select=id,name                → no resolvers called
GET /api/v1/brands?select=id,name,full_label     → only full_label resolver fires
GET /api/v1/brands?select=id,full_label,logo_url → both resolvers fire
GET /api/v1/brands/1?select=id,logo_url          → logo_url fires on single-record endpoint
GET /api/v1/brands?select=*,logo_url             → logo_url fires; * fetches all DB columns
```

> **Note**: Attribute keys are not database columns. They are automatically excluded from the SQL `SELECT` to prevent "Unknown column" errors, while still being resolved and injected into the response after the query.

#### Column Casts

`RecordTableType` accepts a top-level `casting` property — a `[column => cast]` map that mirrors Laravel's `$casts` on Eloquent models. Casting is **opt-in** — only columns listed in `casting` are transformed; all others pass through unchanged. `null` values are always preserved as-is. Columns that use `compositeFields` are automatically skipped.

Flat keys target main-table columns. **Dot-notation keys** target columns inside eagerly-loaded relationships — `'relation.column'` casts `column` on every row of that relation, regardless of whether the relation is a single object (`belongsTo`/`hasOne`) or a collection (`hasMany`/`hasManyThrough`).

Supported built-in cast strings (Laravel-compatible names):

| Cast | PHP transformation |
|---|---|
| `int` / `integer` | `(int) $value` |
| `float` / `double` / `real` | `(float) $value` |
| `decimal` | `(float) $value` |
| `decimal:N` | `number_format((float) $value, N, '.', '')` |
| `string` | `(string) $value` |
| `bool` / `boolean` | `(bool) $value` |
| `array` / `json` | `json_decode($value, true)` |
| `object` | `json_decode($value)` |
| `date` | `Carbon::parse($value)->toDateString()` |
| `datetime` | `Carbon::parse($value)->toISOString()` |
| `timestamp` | `Carbon::parse($value)->getTimestamp()` |

Custom cast forms — the callable receives `($value, $column, $row)`:

| Format | Example |
|---|---|
| Closure | `fn($v, $col, $row) => strtoupper($v)` |
| `[Class, 'method']` array | `[GlobalCasting::class, 'bool']` — static or instance |
| `'Class@method'` string | `'App\\Casts\\MoneyCast@get'` |
| `'ClassName'` string | `MoneyCast::class` → calls `->get($value, $column, $row)` |

> Static methods are preferred automatically — the implementation checks `is_callable([ClassName, method])` first before falling back to container instantiation.

**Config example:**

```php
use App\Record\Casts\GlobalCasting;

// config/record.php
'casting' => [
    'is_active' => 'bool',
    'amount' => 'decimal:2',
],

// table config
'brands' => new RecordTableType(
    table: 'brands',
    columns: [
        'is_active' => ['type' => 'boolean'],
        'quantity' => ['type' => 'bigint'],
        'price' => ['type' => 'decimal(12,2)'],
        'name' => ['type' => 'varchar', 'nullable' => false],
    ],
    casting: [
        // explicit override (custom output format)
        'price'      => fn($v) => number_format((float) $v, 2, '.', ''),
        // overrides global record.casting['amount'] when table-level is defined
        'amount'     => 'string',
        // explicit override from inferred integer
        'quantity'   => 'string',
        // explicit override from inferred boolean
        'is_active'  => 'bool',
        'metadata'   => 'array',
        'score'      => 'decimal:4',
        'created_at' => 'datetime',
        // custom static method
        'is_cloud'   => [GlobalCasting::class, 'bool'],
        // inline Closure
        'status'     => fn($v) => strtoupper($v),
    ],
),
```

**Custom cast class example** (static methods work; no interface required):

```php
namespace App\Record\Casts;

class GlobalCasting
{
    public static function bool(mixed $value, string $column, mixed $row): bool
    {
        return (bool) $value;
    }
}
```

> **Note**: Columns that also define `compositeFields` are skipped by cast processing — composite type conversion takes precedence.

**Relationship (dot-notation) casting example:**

```php
'orders' => new RecordTableType(
    table: 'orders',
    casting: [
        // flat main-table casts
        'total'       => 'float',
        'placed_at'   => 'datetime',

        // hasMany — cast each item row
        'items.price'    => 'float',
        'items.qty'      => 'int',
        'items.metadata' => 'array',

        // belongsTo — cast the single related object
        'customer.is_verified' => 'bool',
        'customer.score'       => 'decimal:2',

        // Closure on a relation column
        'customer.tier' => fn($v) => strtoupper($v),
    ],
),
```

> The relation key (`items`, `customer`) must match the property name returned in the JSON response — i.e. the key used in `with()` / `$appends`. Nesting deeper than one level (e.g. `'order.items.price'`) is not supported; handle deeper nesting with a Closure on the intermediate relation.

#### Per-Action Permission Map

- `permissions` (?array, default: `null`): Per-table map that overrides the auto-generated `pmsName`-based permissions for specific actions. Only the actions listed in this map are affected — all other actions still fall back to the standard `PermissionUtils::mapPermissions()` logic using `pmsName` and `permission_separator`.

Supported action keys:

| Key | Applied by |
|---|---|
| `'read'` | `listRecords` (GET list) and `getRecordById` (GET single) |
| `'create'` | `createRecord` and the create-check in `upsertRecord` |
| `'update'` | `updateRecord` and the update-check in `upsertRecord` |
| `'delete'` | `destroyRecord` (soft-delete) |
| `'force_delete'` | `forceDeleteRecord` (permanent delete — independent, no fallback to `'delete'`) |
| `'restore'` | `restoreRecord` |

The value for each action can be a single permission string or an array of strings. Any one matching permission grants access (same OR logic used by the standard permission resolver).

**Config example:**

```php
'items' => new RecordTableType(
    table: 'items',
    pmsName: 'item',  // still used for actions not listed in permissions
    permissions: [
        'read'    => 'view_list_item',
        'create'  => 'insert_new_item',
        'update'  => 'update_existing_item',
        'delete'       => 'remove_item',
        'force_delete' => 'permanently_remove_item',
        'restore'      => 'restore_item',
        // partial overrides also work — e.g. only override 'read':
        // 'read' => ['view_item', 'admin_access'],
    ],
),
```

> **Backward compatibility**: When `permissions` is `null` (the default), all actions use the existing `pmsName`-based permission generation unchanged.

#### Triggers

- `beforeRead`, `afterRead`, `beforeCreate`, `afterCreate`, `beforeUpdate`, `afterUpdate`, `beforeDelete`, `afterDelete`, `beforeRestore`, `afterRestore` (`RecordTableTriggerType|array|null`, default: `null`): Lifecycle triggers. Each value can be:
  - a `RecordTableTriggerType` instance,
  - a single array trigger config (`['class' => ..., 'functionName' => ..., 'description' => ...]`),
  - or an array of trigger configs to run sequentially.
- `triggers` (`array|null`, default: `null`): An array of trigger class names. The package will automatically scan these classes for `#[RecordTrigger]` attributes and map them to the appropriate lifecycle hooks.

### Class-Based Configuration (Lazy Loading)

For large applications with many tables or complex schemas, you can define configurations in separate classes. This improves performance by only loading the necessary configuration for the requested endpoint (Lazy Loading).

#### 1. Table Configuration

Create a class extending `Sopheak\Core\Resources\RecordResource`:

```php
namespace App\Api\Tables;

use Sopheak\Core\Resources\RecordResource;
use Sopheak\Core\Types\RecordTableType;

class UserTable extends RecordResource
{
    public function configure(): RecordTableType
    {
        return new RecordTableType(
            table: 'users',
            canCreate: true,
            canUpdate: true,
            canDelete: false,
            // ...
        );
    }
}
```

#### 2. Global Function Configuration

Create a class extending `Sopheak\Core\Resources\GlobalFunction`:

```php
namespace App\Api\Functions\Auth;

use Sopheak\Core\Resources\GlobalFunction;
use Sopheak\Core\Types\RecordFunctionType;

class LoginFunction extends GlobalFunction
{
    public function configure(): RecordFunctionType
    {
        return new RecordFunctionType(
            httpMethod: 'POST',
            class: \App\Services\AuthService::class,
            functionName: 'login',
            payloadSchema: [ ... ]
        );
    }
}
```

#### 3. Registration

Register your classes in `config/record.php` or via `SchemaRegistry::register()`:

```php
// config/record.php
return [
    'tables' => [
        'users' => \App\Api\Tables\UserTable::class,
    ],
    'global_functions' => [
        'auth/login' => \App\Api\Functions\Auth\LoginFunction::class,
    ],
];
```

### Type Reference

#### RecordTablePublic

Legacy public access flags for a table. New configurations should use `isAuthRead` and `isAuthWrite` on `RecordTableType`.

- `read` (bool, default: `false`): Allows unauthenticated read actions (`read`, `view`).
- `write` (bool, default: `false`): Allows unauthenticated write actions (`create`, `update`, `delete`, `restore`).

```php
use Sopheak\Core\Types\RecordTablePublic;

$public = new RecordTablePublic(
    read: true,
    write: false,
);
```

#### RecordTableTriggerType

Trigger configuration for table lifecycle events.

- `class` (string, required): Trigger handler class name.
- `functionName` (string, required): Static method to call on the class.
- `description` (?string, default: `null`): Optional description.

```php
use Sopheak\Core\Types\RecordTableTriggerType;

$beforeCreate = new RecordTableTriggerType(
    class: \App\Record\Triggers\InvoiceTriggers::class,
    functionName: 'beforeCreate',
);
```

#### RecordFunctionType

Defines a callable RPC endpoint config (table RPC or global RPC).

- `httpMethod` (array|string|RecordFunctionMethodEnum, required): Allowed HTTP methods.
- `class` (string, required): Handler class.
- `functionName` (string, required): Method name on handler class.
- `pmsName` (array|string|null, default: `null`): Permission(s). When `null`, the function is public (no permission check).
- `disableCache` (bool, default: `false`): Disable caching for this function.
- `cacheTTL` (?int, default: `null`): Custom cache TTL (seconds). When set, overrides the default cache TTL.
- `clearCacheTables` (array|string|null, default: `null`): Tables to clear after successful write methods (`POST`, `PUT`, `PATCH`, `DELETE`). If omitted for table functions, the current table is cleared.
- `description` (?string, default: `null`): Optional description.
- `querySchema`, `payloadSchema`, `responseSchema` (?array, default: `null`): Optional schema metadata used by OpenAPI generation.

```php
use Sopheak\Core\Types\RecordFunctionType;

$function = new RecordFunctionType(
    httpMethod: ['POST'],
    class: \App\Services\ReportService::class,
    functionName: 'generate',
    pmsName: 'view_report',
    disableCache: false,
    clearCacheTables: ['reports'],
    description: 'Generate a report',
);
```

#### RecordBelongsToType

Belongs-to relationship configuration.

- `table` (string, required): Related table name.
- `type` (RecordRelationshipsEnum, default: `RecordRelationshipsEnum::BELONGS_TO`)
- `foreignKey` (?string, default: `null`): FK column on the source table.
- `ownerKey` (?string, default: `'id'`): PK column on the target table.

```php
use Sopheak\Core\Types\RecordBelongsToType;
use Sopheak\Core\Enums\RecordRelationshipsEnum;

$customer = new RecordBelongsToType(
    table: 'customers',
    type: RecordRelationshipsEnum::BELONGS_TO,
    foreignKey: 'customer_id',
    ownerKey: 'id',
);
```

#### RecordHasManyType

Has-many relationship configuration (also used for nested writes when enabled).

- `table` (string, required): Related table name.
- `foreignKey` (string, required): FK column on the related table pointing back to parent.
- `type` (RecordRelationshipsEnum, default: `RecordRelationshipsEnum::HAS_MANY`)
- `localKey` (string, default: `'id'`): Parent key column.
- `with` (?array, default: `[]`): Default nested includes hint.
- `allowCreate`, `allowUpdate`, `allowDelete` (bool, default: `true`): Controls nested write operations for this relationship.

```php
use Sopheak\Core\Types\RecordHasManyType;
use Sopheak\Core\Enums\RecordRelationshipsEnum;

$items = new RecordHasManyType(
    table: 'invoice_items',
    foreignKey: 'invoice_id',
    type: RecordRelationshipsEnum::HAS_MANY,
    localKey: 'id',
    with: [],
    allowCreate: true,
    allowUpdate: true,
    allowDelete: true,
);
```

#### RecordHasManyThroughType

Has-many-through relationship configuration.

- `table` (string, required): Target table name.
- `through` (string, required): Intermediate table name.
- `firstKey` (string, required): FK on intermediate table referencing the source model.
- `secondLocalKey` (string, default: `''`): FK on intermediate table referencing the target model.
- `secondKey` (string, default: `'id'`): PK on the target table.
- `localKey` (string, default: `'id'`): PK on the source table.
- `orderBy` (array, default: `['date' => 'desc']`): Sort configuration.
- `type` (RecordRelationshipsEnum, default: `RecordRelationshipsEnum::HAS_MANY_THROUGH`)
- `allowCreate`, `allowUpdate`, `allowDelete` (bool, default: `true`): Controls nested write operations for this relationship.

```php
use Sopheak\Core\Types\RecordHasManyThroughType;
use Sopheak\Core\Enums\RecordRelationshipsEnum;

$payments = new RecordHasManyThroughType(
    table: 'payments',
    through: 'invoice_payments',
    firstKey: 'invoice_id',
    secondKey: 'id',
    localKey: 'id',
    secondLocalKey: 'payment_id',
    orderBy: ['payment_date' => 'desc'],
    type: RecordRelationshipsEnum::HAS_MANY_THROUGH,
    allowCreate: true,
    allowUpdate: true,
    allowDelete: false, // Prevent deleting payments via this relationship
);
```

#### RecordMetaBelongsToManyType

Many-to-many relationship configuration with optional pivot details.

- `related` (string, required): Related model class name or related table name.
- `type` (RecordRelationshipsEnum, default: `RecordRelationshipsEnum::BELONGS_TO_MANY`)
- `table` (?string, default: `null`): Pivot table.
- `foreignPivotKey`, `relatedPivotKey` (?string, default: `null`): Pivot key columns.
- `parentKey`, `relatedKey` (?string, default: `null`): Key columns on source/target tables.
- `relation` (?string, default: `null`): Morph relation name (when using morph pivot patterns).
- `withPivot` (array, default: `[]`): Extra pivot columns to return.
- `wherePivot` (array, default: `[]`): Pivot constraints as `['pivot_col' => value]`.
- `withTimestamps` (bool, default: `false`): Include pivot timestamps.
- `select` (array, default: `[]`): Columns to select from related table.
- `pivotWhere` (array, default: `[]`): Legacy format; converted into `wherePivot` if `wherePivot` is empty.
- `allowCreate`, `allowUpdate`, `allowDelete` (bool, default: `true`): Controls nested write operations (attaching/detaching/updating).

```php
use Sopheak\Core\Types\RecordMetaBelongsToManyType;
use Sopheak\Core\Enums\RecordRelationshipsEnum;

$roles = new RecordMetaBelongsToManyType(
    related: 'roles',
    type: RecordRelationshipsEnum::BELONGS_TO_MANY,
    table: 'user_roles',
    foreignPivotKey: 'user_id',
    relatedPivotKey: 'role_id',
    withPivot: ['assigned_at'],
    wherePivot: [],
    withTimestamps: true,
    select: ['roles.id', 'roles.name'],
    allowCreate: true,  // Allow attaching roles
    allowUpdate: false, // Prevent updating role details
    allowDelete: true,  // Allow detaching roles
);
```

#### RecordSpatiePermissionType

Specialized relationship config for `spatie/laravel-permission` morph pivot tables.

- Requires `spatie/laravel-permission` to be installed; the constructor throws if it is missing.
- Automatically ensures `model_type` is included in `withPivot`. If `teamsEnabled` is true, it also adds the team key to `withPivot`.

```php
use Sopheak\Core\Types\RecordSpatiePermissionType;
use Sopheak\Core\Enums\RecordRelationshipsEnum;

$userRoles = new RecordSpatiePermissionType(
    related: config('permission.models.role'),
    relation: 'model',
    type: RecordRelationshipsEnum::SPATIE_PERMISSION,
    table: config('permission.table_names.model_has_roles'),
    foreignPivotKey: config('permission.column_names.model_morph_key'),
    relatedPivotKey: 'role_id',
    teamsEnabled: true,
);
```
