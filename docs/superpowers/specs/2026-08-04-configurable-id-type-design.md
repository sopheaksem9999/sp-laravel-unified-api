---
title: "Configurable ID Type for Bundled Modules"
description: "Design for a global record.id_type setting governing permissions and audit primary keys, and for fixing client-model reference columns that are hardcoded as integers."
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

- `sp_attachments`, `sp_document_folders`, `sp_webhook_*` use `uuid('id')->primary()`
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

## Scope

**In scope**

- A single global `record.id_type` setting.
- It governs the primary keys and package-internal foreign keys of the
  permissions and audit tables only.
- Fixing the four client-reference columns listed above.

**Out of scope**

- Per-module ID type configuration. One global knob only.
- Conversion tooling for clients who have already migrated. The setting takes
  effect at first migration; existing installs keep their current schema.
- Attachments and webhooks tables. Their primary keys stay UUID permanently.

## Why attachments and webhooks are excluded

The default for `record.id_type` is `integer`, chosen so that existing clients
need to add no configuration at all. Permissions and audit are already integer,
so that default is a no-op for them.

Attachments and webhooks, however, ship UUID today. Their
`RecordTableType.columns['id']['type']` feeds live runtime behavior: UUID
cursor-pagination validation, the `uuid` default-validation rule, and
auto-UUID generation on nested creates. Flipping them to `integer` would break
an existing attachments client at runtime even though its database schema never
changes. Excluding them keeps the default a true no-op for every existing
install.

These tables are package-internal and already interoperate with both client
conventions, because the columns that reference client records
(`sp_attachment_links.record_id`, `sp_document_folders.owner_id`) are strings.

## Resulting behavior

With `SP_ID_TYPE` unset (default `integer`):

| Table | `id` type | Change |
|---|---|---|
| `sp_permissions` | `bigIncrements` | unchanged |
| `sp_roles` | `bigIncrements` | unchanged |
| `sp_role_permissions` | `bigIncrements` | unchanged |
| `sp_model_has_roles` | `bigIncrements` | unchanged |
| `sp_model_permissions` | `bigIncrements` | unchanged |
| `sp_audit_logs` | `bigIncrements` | unchanged |
| `sp_attachments` | `uuid` | unchanged |
| `sp_document_folders` | `uuid` | unchanged |
| `sp_webhook_*` | `uuid` | unchanged |

With `SP_ID_TYPE=uuid`, the six permissions/audit tables get `uuid` primary
keys and their package-internal foreign keys (`role_id`, `permission_id`)
become `uuid` to match. The attachments and webhooks rows are unaffected.

## Components

### `RecordConfigService::idType(): string`

Reads `config('record.id_type', 'integer')`, trims and lowercases it, and
returns `'uuid'` or `'integer'`.

Any other value throws `InvalidArgumentException`. Config is a system boundary,
and a typo such as `'uuidv4'` must fail loudly at boot rather than silently
producing integer primary keys that are expensive to discover later.

### `src/Database/MigrationIdHelper.php`

Three static methods, used roughly fourteen times across the two affected
migrations. The helper earns its place by making the distinction between the
three column roles explicit at every call site.

```php
MigrationIdHelper::primary(Blueprint $t, string $col = 'id'): void
```
`$t->uuid($col)->primary()` when the type is uuid, otherwise
`$t->bigIncrements($col)`.

```php
MigrationIdHelper::foreign(Blueprint $t, string $col): ColumnDefinition
```
`$t->uuid($col)` or `$t->unsignedBigInteger($col)`. For foreign keys that point
at **package** tables (`role_id`, `permission_id`), which must match the
governed primary key type so the existing `->foreign()->references()`
constraints remain valid.

```php
MigrationIdHelper::morph(Blueprint $t, string $col): ColumnDefinition
```
Always `$t->string($col)`, regardless of setting. For foreign keys that point at
**client** models, which may be either type and are not knowable from package
config.

### Config file changes

`config/record.php` gains:

```php
'id_type' => env('SP_ID_TYPE', 'integer'), // uuid|integer
```

documented as governing the permissions and audit tables only, with a note that
attachments and webhooks are always UUID.

`config/permissions.php` and `config/audit.php` replace their literal
`'id' => ['type' => 'bigIncrements'|'integer']` entries with values derived from
`RecordConfigService::idType()`, so the Record API's runtime UUID detection
tracks whatever the schema actually is.

### Config load order

This resolution works without any load-order workaround. `config/record.php` is
publish-only — the provider never calls `mergeConfigFrom` on it — so Laravel's
`LoadConfiguration` bootstrapper loads it before any provider registers. By the
time `CoreSpLaravelApiProvider::register()` merges `permissions.php` and
`audit.php`, `config('record.id_type')` is populated.

It also survives `config:cache`: `mergeConfigFrom` is skipped when config is
cached, but the cached payload already holds the value resolved at cache-build
time, when `record.php` was loaded normally.

Reading through `config()` rather than `env()` directly means the setting works
whether a client sets `SP_ID_TYPE` in `.env` or edits a published
`config/record.php` by hand.

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

`sp_audit_logs` hardcodes its tenant column as `unsignedBigInteger`, ignoring
the `record.tenant_column_type` setting that already exists for this purpose and
that the attachments, webhooks, and permissions migrations already honor. It
will honor it too. Same root cause, same change, so it is included here rather
than deferred.

### Open item to verify during implementation

`PermissionRegistrar` at `src/Authorization/PermissionRegistrar.php:107` runs:

```php
->where('sp_model_has_roles.model_id', $user->getKey())
```

With `model_id` as `varchar` and an integer `getKey()`, PostgreSQL may reject
the comparison depending on how PDO binds the parameter. This must be settled by
a test rather than assumed. If it does fail, the fix is a `(string)` cast at the
`HasRoles` query sites (`src/Authorization/Traits/HasRoles.php`, lines 23 and
40) and in `PermissionRegistrar`.

## Testing

Feature tests that run the package migrations under both `SP_ID_TYPE` values:

1. **Primary key types.** Under `integer`, the six governed tables have
   integer PKs; under `uuid`, they have uuid PKs.
2. **Exclusion holds.** `sp_attachments`, `sp_document_folders`, and
   `sp_webhook_*` have uuid PKs under both settings.
3. **The bug, as a failing test first.** A client `User` model with a UUID
   primary key can be assigned a role and have it read back. This fails against
   current `main`.
4. **Integer regression.** The same flow with an integer-keyed `User` still
   works.
5. **Audit both ways.** Audit entries write and read back for both UUID-keyed
   and integer-keyed entities and users.
6. **Backward compatibility.** The existing suite passes with no `SP_ID_TYPE`
   set and no config changes. This is the proof that existing installs are
   untouched.
7. **Boundary validation.** An invalid `record.id_type` throws
   `InvalidArgumentException`.

## Files affected

- `config/record.php` — add `id_type`
- `config/permissions.php` — derive `id` column type; `model_id` to string
- `config/audit.php` — derive `id` column type; `entity_id`/`user_id` to string
- `src/Services/RecordConfigService.php` — add `idType()`
- `src/Database/MigrationIdHelper.php` — new
- `database/migrations/2026_05_13_000000_create_sp_permissions_tables.php`
- `database/migrations/2025_01_27_000000_create_audit_logs_table.php`
- `src/Authorization/Traits/HasRoles.php` — pending the open item above
- `src/Authorization/PermissionRegistrar.php` — pending the open item above
- `docs/guide/features/feature-record-data-types.md` — document `id_type`
