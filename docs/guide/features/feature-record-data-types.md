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
  - config:cache
  - autoloaded
  - closure serialization
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

::: warning `uuid` is what enables automatic primary key generation
A uuid primary key has no database default and does not auto-increment, so the
package generates one on create — but **only when the primary key column is
declared `uuid`** (or `char(36)`/`varchar(36)`, or a PostgreSQL `udt_name` of
`uuid`). Declaring it `string` or `varchar` will **not** generate a key on any
driver, and `POST /{apiPrefix}/{table}` without a client-supplied `id` then
fails with a not-null violation (`SQLSTATE[23000]` / PostgreSQL 23502 /
MySQL 1364).

This bites hardest when scaffolding against a SQLite development database.
SQLite has no native uuid type, so introspection reports a uuid column as a
bare `varchar`, and `sp-laravel-api:sync-record-columns` writes that verbatim.
`sp-laravel-api:generate-all-configs` chains into the same sync, so it produces
the same result. The written config then silently disables key generation on
**every** driver, including the MySQL or PostgreSQL you deploy to. After
generating or syncing against SQLite, change uuid primary keys to
`'type' => 'uuid'` by hand.

When a table declares no `columns` at all, they are filled from live
introspection instead, and what that yields **is** driver-dependent: `uuid()`
introspects as `char(36)` on MySQL and `uuid` on PostgreSQL — both detected as
uuid — but as a bare `varchar` on SQLite, which is not. Declaring the type
explicitly is the only way to get identical behavior everywhere.

Bare `varchar` is deliberately not treated as a uuid on any driver. That is what
stops a natural string key — say `string('sku')->primary()` — from being
mistaken for a uuid and overwritten.
:::

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
environment variable.

Choose it **before** the package migrations first run, and do not change it
afterwards. The setting is read only while the tables are being created, so a
later change does not alter them — it only makes the setting disagree with the
schema, and that disagreement fails loudly on the next write rather than
degrading quietly. Switching to `'uuid'` after migrating makes role creation
write a UUID into an integer `id` column (PostgreSQL: `invalid input syntax for
type bigint`); switching back to `'integer'` makes it insert no id at all into a
column that has no default (PostgreSQL: `null value in column "id"`). Converting
an already-migrated project means writing your own migration for
`sp_permissions.id`, `sp_roles.id` and every foreign key listed below.

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

- `sp_model_has_roles.model_id`, `sp_model_permissions.model_id` — `varchar(191)`
- `sp_audit_logs.entity_id`, `sp_audit_logs.user_id` — `varchar(191)`
- `sp_attachment_links.record_id` — `varchar(255)`

This is why a UUID-keyed `User` works with roles and audit logging regardless
of what `id_type` is set to.

The two `model_id` columns are bounded to 191 characters because they sit in
composite **unique** indexes alongside `model_type` and a governed foreign key.
At `varchar(255)` under `utf8mb4`, those indexes exceed MySQL's 3072-byte InnoDB
limit once `id_type` is `uuid` and `enable_tenant_id` is on, and the migration
fails outright. 191 is long enough for any UUID or integer key.

`sp_audit_logs.entity_id` and `user_id` share the bound for consistency, though
their indexes are non-unique and were never at risk.
`sp_attachment_links.record_id` predates this work and is unbounded.

**Upgrading an existing install.** Those columns used to be
`unsignedBigInteger`, so a project that migrated earlier still has integer
columns while the package metadata now declares them as strings. Migration
`2026_08_05_000000_convert_client_reference_columns_to_string` converts them in
place. It is a no-op when the columns are already strings and when the table is
absent (`sp_audit_logs` with `audit.enabled` off), and it leaves every index
alone. It does rewrite `sp_audit_logs`, which is usually the largest table in
the schema, so run it in a maintenance window on a big database. Its `down()` is
intentionally a no-op: a UUID cannot be cast back into a `bigint` without
destroying data.

Note that `sp_audit_logs`'s tenant column (present only when
`enable_tenant_id` is on) follows this same reasoning and is **always**
`string` too — it is not governed by `record.tenant_column_type`. That
setting only controls the PostgreSQL RLS cast used by the
`pgsql:enable-rls` command; it has no effect on any migrated column type.

## 6) `config:cache` and closures in autoloaded table/function configs

`config/sp-record.php` ships with `'autoloaded' => true`. With that flag on,
`RecordConfigLoader` reads every file under `config/records/tables/*.php` and
`config/records/global-functions/*.php` while `config/sp-record.php` itself is
being evaluated, so the resulting `RecordTableType` instances — including any
`createValidator`/`updateValidator` closures inside them — become part of the
in-memory `record.tables` / `record.global_functions` array before the request
even starts.

`php artisan config:cache` serializes that entire in-memory config tree to one
PHP file with `var_export()`. On PHP 8.4, `var_export()` does **not** throw or
warn when it hits a `Closure` — it silently writes the non-functional
`\Closure::__set_state(array())`. The failure only surfaces one step later,
when `Illuminate\Foundation\Console\ConfigCacheCommand` `require`s the file it
just wrote, as a self-check: evaluating `\Closure::__set_state(array())`
throws, and the command reports it as:

```
Your configuration files are not serializable.
```

So a table with a `Closure` validator, or a global function declared
`'type' => 'closure'`, runs fine normally but breaks `config:cache` as soon as
`autoloaded` is enabled (the shipped default). Two ways out:

- **Use a class-method callable instead of a `Closure`.** `[MyValidator::class,
  'validate']` is a plain array of strings, so it survives `var_export()` and
  `config:cache` unchanged.
- **Turn `autoloaded` off and go back to the runtime scan.** Remove the
  `RecordConfigLoader::tables(...)` / `RecordConfigLoader::globalFunctions(...)`
  calls from `config/sp-record.php` and drop (or set `false`) the `'autoloaded'`
  key. `RecordConfigService` then scans `config/records/tables` and
  `config/records/global-functions` itself at runtime, exactly as it did before
  this feature existed — `Closure` validators work again, at the cost of
  `config:cache` no longer baking those directories in.
