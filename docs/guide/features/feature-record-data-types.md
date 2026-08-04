---
title: "Record Data Types"
description: "Reference for data types used in RecordTableType columns, automatic default-validation type rules, and response casting types Columns, Validation, Casting."
keywords:
  - record data types
  - columns type mapping
  - default validation types
  - cast types
  - uuid integer numeric boolean
  - datetime json array
  - id_type
  - primary key type
  - client reference columns
---

# Record Data Types

This page collects all practical type mappings used by the package in one place.

## 1) Column Metadata Types in `RecordTableType::columns`

You can store database-like type strings in `columns.{field}.type`, for example:

- `uuid`
- `integer`, `bigint`, `smallint`
- `numeric`, `decimal(12,2)`, `float`, `double`
- `boolean`
- `date`, `timestamp`, `datetime`
- `varchar`, `char`, `text`
- `json`, `jsonb`

Example:

```php
'orders' => new RecordTableType(
    table: 'orders',
    columns: [
        'id' => ['type' => 'uuid', 'nullable' => false],
        'customer_id' => ['type' => 'bigint', 'nullable' => false],
        'total' => ['type' => 'decimal(12,2)', 'nullable' => false],
        'is_paid' => ['type' => 'boolean', 'nullable' => false, 'default' => false],
        'placed_at' => ['type' => 'timestamp', 'nullable' => true],
        'metadata' => ['type' => 'jsonb', 'nullable' => true],
        'note' => ['type' => 'text', 'nullable' => true],
    ],
)
```

## 2) Default Validation Type Mapping (`DefaultValidationUtils`)

When `record.default_validation.types=true`, column type is mapped to Laravel rules as:

| DB type pattern | Validation rule |
|---|---|
| contains `uuid` | `uuid` |
| `int2,int4,int8,integer,bigint,smallint,serial,bigserial` | `integer` |
| `numeric,decimal,float4,float8,real,double precision,double,float` | `numeric` |
| `bool,boolean` | `boolean` |
| contains `json` | `array` |
| contains `date` or `timestamp` | `date` |
| contains `time` | `string` |
| contains `char,text,varchar` | `string` |

## 3) Response Casting Types (`RecordTableType::casting`)

Supported cast strings:

- `int`, `integer`
- `float`, `double`, `real`
- `decimal`, `decimal:N`
- `string`
- `bool`, `boolean`
- `array`, `json`
- `object`
- `date`
- `datetime`
- `timestamp`

Custom cast forms:

- `Closure`
- `'Class@method'`
- `[ClassName::class, 'method']`
- Class name with `get()` method

Example:

```php
'orders' => new RecordTableType(
    table: 'orders',
    casting: [
        'total' => 'decimal:2',
        'is_paid' => 'boolean',
        'placed_at' => 'datetime',
        'metadata' => 'array',
        'customer.score' => 'decimal:2', // dot-notation for relationship column
    ],
)
```

## 4) Relationship Enum Type Values (`RecordRelationshipsEnum`)

Available relationship types:

- `belongsTo`
- `hasMany`
- `hasOne`
- `belongsToMany`
- `hasManyThrough`
- `hasOneThrough`
- `morphTo`
- `morphOne`
- `morphMany`
- `morphToMany`
- `morphByMany`
- `spatiePermission`

## 5) Bundled Module ID Type (`record.id_type`)

The package's own `sp_permissions` and `sp_roles` tables can use either
auto-incrementing integer or UUID primary keys, so their API surface matches
your project's convention.

```php
// config/record.php
'id_type' => 'integer', // uuid|integer
```

The default is `'integer'`, set as a plain literal — there is no `SP_ID_TYPE`
environment variable. This setting is read only when the package migrations
first run. Changing it on a project that has already migrated does **not**
alter existing tables.

### What it governs

| Table | Column |
|---|---|
| `sp_permissions` | `id` |
| `sp_roles` | `id` |
| `sp_role_permissions` | `role_id`, `permission_id` |
| `sp_model_has_roles` | `role_id` |
| `sp_model_permissions` | `permission_id` |

### What always stays `bigIncrements`, regardless of the setting

- `sp_role_permissions.id`, `sp_model_has_roles.id`, `sp_model_permissions.id`
  and `sp_audit_logs.id`. These are surrogate keys: nothing references them,
  and their insert paths (including Eloquent's `sync()` for the pivot tables)
  supply no id, so they must stay auto-incrementing regardless of `id_type`.

### What always stays `uuid`, regardless of the setting

- `sp_attachments`, `sp_attachment_folders` (renamed from
  `sp_document_folders`) and `sp_webhook_*` (`sp_webhook_endpoints`,
  `sp_webhook_subscriptions`, `sp_webhook_deliveries`) always use `uuid`
  primary keys. They do not consult `record.id_type`.

### What always stays `string` — the client-reference columns

Columns that point at **your** models are always strings, because the
package cannot know your key type. A string holds a UUID or an integer key
equally well:

- `sp_model_has_roles.model_id`, `sp_model_permissions.model_id`
- `sp_audit_logs.entity_id`, `sp_audit_logs.user_id`
- `sp_attachment_links.record_id`

This is why a UUID-keyed `User` works with roles and audit logging regardless
of what `id_type` is set to.

Note that `sp_audit_logs`'s tenant column (present only when
`enable_tenant_id` is on) follows this same reasoning and is **always**
`string` too — it is not governed by `record.tenant_column_type`. That
setting only controls the PostgreSQL RLS cast used by the
`pgsql:enable-rls` command; it has no effect on any migrated column type.
