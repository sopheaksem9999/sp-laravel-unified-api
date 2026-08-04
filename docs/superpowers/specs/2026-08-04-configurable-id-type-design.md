---
title: "Configurable ID Type for Bundled Modules"
description: "Design for a global record.id_type setting governing sp_permissions and sp_roles primary keys, and for fixing client-model reference columns that are hardcoded as integers."
keywords:
  - id type
  - uuid
  - integer
  - primary key
  - record.id_type
  - permissions
  - audit
  - migration
date: 2026-08-04
status: approved
---

# Configurable ID Type for Bundled Modules

## Problem

Client projects using this package split into two camps: some use UUID primary
keys throughout their own tables, others use auto-incrementing integers. The
Record API already handles both correctly for client-owned tables, because it
branches on `columns.id.type` from the client's own `RecordTableType` config.

The gap is in the modules this package ships itself. Their migrations hardcode
ID types, and they disagree with each other:

- `sp_attachments`, `sp_attachment_folders`, `sp_webhook_*` use `uuid('id')->primary()`
- `sp_permissions`, `sp_roles`, `sp_role_permissions`, `sp_model_has_roles`,
  `sp_model_permissions`, `sp_audit_logs` use `bigIncrements('id')` / `id()`

More seriously, several columns that reference **client-owned** models are
hardcoded `unsignedBigInteger`. For a client whose `User` model has a UUID
primary key, those columns cannot physically store the value. This is a
functional bug, not a style inconsistency:

- `sp_model_has_roles.model_id`
- `sp_model_permissions.model_id`
- `sp_audit_logs.entity_id`
- `sp_audit_logs.user_id`

`sp_attachment_links.record_id` already avoids this by using a plain `string`
column, which works for either convention. That is the pattern to generalize.

Corroborating evidence: `tests/TestCase.php` already builds its `sp_audit_logs`
fixture with `entity_id` and `user_id` as `string`, disagreeing with the real
migration. The test harness has been encoding the fix all along.

## Scope

**In scope**

- A single global `record.id_type` setting.
- It governs `sp_permissions.id` and `sp_roles.id`, plus the foreign key
  columns that point at them.
- Fixing the four client-reference columns listed above.

**Out of scope**

- Per-module ID type configuration. One global knob only.
- Conversion tooling for clients who have already migrated. The setting takes
  effect at first migration; existing installs keep their current schema.
- Attachments and webhooks tables. Their primary keys stay UUID permanently.
- Pivot and audit surrogate primary keys. See below.

## What the setting governs

**Governed by `id_type`:**

| Table | Column | Role |
|---|---|---|
| `sp_permissions` | `id` | entity PK, referenced + API-exposed |
| `sp_roles` | `id` | entity PK, referenced + API-exposed |
| `sp_role_permissions` | `permission_id` | FK to `sp_permissions.id` |
| `sp_role_permissions` | `role_id` | FK to `sp_roles.id` |
| `sp_model_has_roles` | `role_id` | FK to `sp_roles.id` |
| `sp_model_permissions` | `permission_id` | FK to `sp_permissions.id` |

**Always `bigIncrements`, never governed:**

`sp_role_permissions.id`, `sp_model_has_roles.id`, `sp_model_permissions.id`,
`sp_audit_logs.id`.

These are surrogate keys. Nothing references them: no foreign key, no API
route, no join. More decisively, their write paths cannot supply an ID:

- Pivot rows are inserted by Eloquent's `sync()` / `syncWithoutDetaching()`,
  which write without an `id` column. A UUID primary key with no database
  default would fail on every insert. Supporting UUID here would require custom
  `Pivot` model classes for all three tables.
- Audit rows are inserted at `src/Services/AuditLogService.php:167` via a raw
  `DB::table(...)->insert($auditData)` whose payload contains no `id`. Same
  failure mode.

Making these configurable would mean writing real machinery to support a
capability no client can observe. They stay `bigIncrements`, which is also the
better choice for the audit table specifically: a monotonic integer key gives
natural time ordering for cursor pagination.

**Always `string`, never governed:** the four client-reference columns. These
point at arbitrary client models whose key type is not knowable from package
config, so a string holds either form.

## Why attachments and webhooks are excluded

The default for `record.id_type` is `integer`, chosen so that existing clients
need to change no configuration at all. Permissions is already integer, so that
default is a no-op for it.

Attachments and webhooks, however, ship UUID today. Their
`RecordTableType.columns['id']['type']` feeds live runtime behavior: UUID
cursor-pagination validation, the `uuid` default-validation rule, and
auto-UUID generation on nested creates. Flipping them to `integer` would break
an existing attachments client at runtime even though its database schema never
changes. Excluding them keeps the default a true no-op for every existing
install.

These tables are package-internal and already interoperate with both client
conventions, because the columns that reference client records
(`sp_attachment_links.record_id`, `sp_attachment_folders.owner_id`) are strings.

## Resulting behavior

With `record.id_type` left at its default of `integer`, every table keeps
exactly the type it has today. Nothing changes for any existing install.

Set to `uuid`, only these change:

| Table | Column | `integer` (default) | `uuid` |
|---|---|---|---|
| `sp_permissions` | `id` | `bigIncrements` | `uuid` primary |
| `sp_roles` | `id` | `bigIncrements` | `uuid` primary |
| `sp_role_permissions` | `permission_id` | `unsignedBigInteger` | `uuid` |
| `sp_role_permissions` | `role_id` | `unsignedBigInteger` | `uuid` |
| `sp_model_has_roles` | `role_id` | `unsignedBigInteger` | `uuid` |
| `sp_model_permissions` | `permission_id` | `unsignedBigInteger` | `uuid` |

## Components

### `RecordConfigService::idType(): string`

Reads `config('record.id_type', 'integer')`, trims and lowercases it, and
returns `'uuid'` or `'integer'`.

Any other value throws `InvalidArgumentException`. Config is a system boundary,
and a typo such as `'uuidv4'` must fail loudly at boot rather than silently
producing integer primary keys that are expensive to discover later.

### `src/Database/MigrationIdHelper.php`

Three static methods. The helper earns its place by making the distinction
between the three column roles explicit at every call site.

```php
MigrationIdHelper::primary(Blueprint $t, string $col = 'id'): void
```
`$t->uuid($col)->primary()` when the type is uuid, otherwise
`$t->bigIncrements($col)`.

```php
MigrationIdHelper::foreign(Blueprint $t, string $col): ColumnDefinition
```
`$t->uuid($col)` or `$t->unsignedBigInteger($col)`. For foreign keys pointing at
`sp_permissions.id` / `sp_roles.id`, which must match so the existing
`->foreign()->references()` constraints stay valid.

```php
MigrationIdHelper::morph(Blueprint $t, string $col): ColumnDefinition
```
Always `$t->string($col)`, regardless of setting. For foreign keys pointing at
**client** models.

### `src/Authorization/Traits/HasConfigurableKey.php`

The `Role` and `Permission` Eloquent models currently rely on Laravel's
defaults: `$incrementing = true`, `$keyType = 'int'`, and no ID generation.
Under `id_type = 'uuid'` those defaults are wrong on both counts — the column
has no database default, so an insert without an ID fails.

A trait applied to both models resolves this at runtime:

- `getIncrementing(): bool` returns `false` when the type is uuid
- `getKeyType(): string` returns `'string'` when the type is uuid
- a `creating` hook assigns `(string) Str::uuid()` when the type is uuid and no
  key is set

Eloquent boots trait hooks automatically via its `bootTraits()` mechanism, so
this composes with `Role`'s existing `booted()` method rather than conflicting
with it.

### Config file changes

`config/record.php` gains a plain literal, with no `env()` indirection. A client
sets it by editing their published config file:

```php
'id_type' => 'integer', // uuid|integer
```

documented as governing `sp_permissions` and `sp_roles` only.

This is a deployment-invariant structural choice, not a per-environment one: a
given client's tables have one ID shape across local, staging, and production,
and it is fixed at first migration. An env var would imply it can differ between
environments, which would be actively misleading.

`config/permissions.php` replaces its two literal
`'id' => ['type' => 'bigIncrements']` entries with values derived from
`RecordConfigService::idType()`, so the Record API's runtime UUID detection
tracks whatever the schema actually is.

`config/audit.php` needs no `id` change, since `sp_audit_logs.id` is not
governed.

### Config load order

This resolution works without any load-order workaround. `config/record.php` is
publish-only — the provider never calls `mergeConfigFrom` on it — so Laravel's
`LoadConfiguration` bootstrapper loads it before any provider registers. By the
time `CoreSpLaravelApiProvider::register()` merges `permissions.php`,
`config('record.id_type')` is populated.

It also survives `config:cache`: `mergeConfigFrom` is skipped when config is
cached, but the cached payload already holds the value resolved at cache-build
time, when `record.php` was loaded normally.

A client that has not published `config/record.php` has no `record.id_type` key
at all, and `config('record.id_type', 'integer')` yields the default. That is
the correct outcome, and it is why the default must be `integer`.

## Bug fixes

Four columns change from `unsignedBigInteger` to `string`, in both the migration
and the corresponding `RecordTableType.columns` metadata:

| Table | Column | References |
|---|---|---|
| `sp_model_has_roles` | `model_id` | any client model |
| `sp_model_permissions` | `model_id` | any client model |
| `sp_audit_logs` | `entity_id` | any client record |
| `sp_audit_logs` | `user_id` | client User model |

`config/audit.php` currently declares `entity_id` and `user_id` as
`'type' => 'integer'`, which makes default validation reject UUID values
outright. Both become `string`.

### Related fix: audit tenant column

`sp_audit_logs` hardcodes its tenant column as `unsignedBigInteger`. Every other
package migration declares it `string` — attachments, webhooks, and permissions
all use `$table->string($tenantColumn)`. Audit is the outlier, and as an integer
column it cannot hold a UUID tenant id, which is the same bug class as
`entity_id` and `user_id`.

It becomes an unconditional `string`, matching its siblings and holding either
tenant id shape.

**Correction:** an earlier draft of this spec claimed `record.tenant_column_type`
"already exists for this purpose and the attachments, webhooks, and permissions
migrations already honor it," and proposed driving the column type from it. That
premise was false. `grep -rn "tenantColumnType" src database` shows the only
readers are `RecordConfigService` itself and `EnablePgsqlRlsCommand`, which uses
it for PostgreSQL RLS cast expressions — not for column declarations. Driving the
audit column from that setting would have made `sp_audit_logs` the only table
whose tenant column type varies with config, giving it `bigint` while every
sibling stayed `varchar`.

### PostgreSQL parameter binding

`PermissionRegistrar::resolveUserPermissions` compares a now-`varchar`
`model_id` against `$user->getKey()`, which may be an integer.

This is expected to work. `pdo_pgsql` sends bound parameters in text format
without explicit type OIDs, so PostgreSQL infers the parameter type from the
column context — `varchar = $1` resolves `$1` as `varchar`. The value binds as
the string `'42'` and compares correctly.

What must be avoided is a **join** between `model_id` and a client's integer
`users.id`, which has no valid operator in PostgreSQL. The existing query is
safe: its only join is `sp_role_permissions.role_id = sp_model_has_roles.role_id`,
both package columns of identical type. No future change may join `model_id`
directly against a client key column.

This cannot be proven by this repository's test suite, which runs on in-memory
SQLite, where dynamic typing makes the comparison succeed regardless. The
reasoning above is the basis for the decision; a PostgreSQL integration test
would be the way to confirm it if one is ever added.

## Testing

Feature tests that run the package migrations under both `record.id_type`
values, set via the test case's config rather than an environment variable:

1. **Primary key types.** Under `integer`, `sp_permissions.id` and
   `sp_roles.id` are integer; under `uuid`, they are uuid.
2. **Ungoverned tables hold.** The three pivot `id` columns and
   `sp_audit_logs.id` are integer under both settings.
3. **Exclusion holds.** `sp_attachments`, `sp_attachment_folders`, and
   `sp_webhook_*` have uuid PKs under both settings.
4. **The bug, as a failing test first.** A client `User` model with a UUID
   primary key can be assigned a role and have it read back. This fails against
   current `main`.
5. **Integer regression.** The same flow with an integer-keyed `User` still
   works.
6. **Role creation under uuid.** Creating a `Role` with `id_type = 'uuid'`
   produces a valid UUID key and its pivot writes succeed — the check that the
   `HasConfigurableKey` trait is wired correctly.
7. **Audit both ways.** Audit entries write and read back for both UUID-keyed
   and integer-keyed entities and users.
8. **Backward compatibility.** The existing suite passes with `record.id_type`
   absent entirely and no config changes. This is the proof that existing
   installs are untouched.
9. **Boundary validation.** An invalid `record.id_type` throws
   `InvalidArgumentException`.

## Files affected

- `config/record.php` — add `id_type`
- `config/permissions.php` — derive both `id` column types
- `config/audit.php` — `entity_id` / `user_id` to string
- `src/Services/RecordConfigService.php` — add `idType()`
- `src/Database/MigrationIdHelper.php` — new
- `src/Authorization/Traits/HasConfigurableKey.php` — new
- `src/Authorization/Models/Role.php` — apply trait
- `src/Authorization/Models/Permission.php` — apply trait
- `database/migrations/2026_05_13_000000_create_sp_permissions_tables.php`
- `database/migrations/2025_01_27_000000_create_audit_logs_table.php`
- `docs/guide/features/feature-record-data-types.md` — document `id_type`
