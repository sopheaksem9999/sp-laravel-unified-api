---
title: "Userstamps (created_by_id / last_updated_by_id)"
description: "Recommended audit/userstamp convention: use created_by_id and last_updated_by_id as the raw user-id columns, and reserve created_by / last_updated_by as belongsTo relationship aliases. Covers auto-population, overrideUserstamps, and the audit-log relationship."
keywords:
  - userstamps
  - created_by_id
  - last_updated_by_id
  - created_by
  - last_updated_by
  - overrideUserstamps
  - audit user tracking
---

# Userstamps

The package auto-populates audit-style user columns on create/update when the
table schema declares them. This is the data source behind own-records scoping
(see [Own-Records Scoping](/guide/features/feature-permission)).

## Recommended Convention

Use the `_id`-suffixed names as the raw userstamp columns, and reserve the
non-`_id` names as the `belongsTo` relationship aliases to the users table:

| Name | Role |
|---|---|
| `created_by_id` | **Userstamp column** — id of the user who created the row |
| `last_updated_by_id` | **Userstamp column** — id of the last user who wrote the row |
| `created_by` | **Relationship alias** — `belongsTo` to the users table |
| `last_updated_by` | **Relationship alias** — `belongsTo` to the users table |

Do **not** use `created_by` / `last_updated_by` as the raw audit columns — those
names are meant for the relationship key. Declare `created_by_id` /
`last_updated_by_id` in `columns` (and write-disable them), then expose the
`created_by` / `last_updated_by` relationships separately.

## Supported Column Names

The package detects the following names for backward compatibility. Any subset
works — it only touches columns present in the table's `columns` map:

| Column | Filled on | Notes |
|---|---|---|
| `created_by_id` | create | **Recommended** |
| `created_by` | create | legacy / relationship-key variant |
| `last_updated_by_id` | create + update | **Recommended** |
| `last_updated_by` | create + update | legacy / relationship-key variant |
| `updated_by` | create + update | legacy variant |

## How It Works

On every create/update through the dynamic record API, the payload extractor
and `RecordService::applyTimestampsAndAuditFields()` inspect the authenticated
user and the table schema:

```php
// create: only created_by_id / created_by filled
// update: last_updated_by_id / last_updated_by / updated_by filled
// (created_by* untouched on update)
```

The value is always `auth('api')->user()->id`. Client-supplied values for
these columns are **ignored** unless the table opts out (see
[overrideUserstamps](#overrideuserstamps)).

**Requirement:** the column must exist in the table's `columns` map. A column
in the database but missing from config is treated as an unknown field, and a
column in config but missing from the database makes writes fail.

```php
// config/sp-record.php — example table
new RecordTableType(
    table: 'invoices',
    pmsName: 'invoice',
    columnWriteDisabled: ['created_by_id', 'last_updated_by_id'],
    relationships: [
        'created_by' => new RecordBelongsToType(
            table: 'users',
            foreignKey: 'created_by_id',
            ownerKey: 'id',
        ),
        'last_updated_by' => new RecordBelongsToType(
            table: 'users',
            foreignKey: 'last_updated_by_id',
            ownerKey: 'id',
        ),
    ],
    columns: [
        // ...
        'created_by_id' => ['type' => 'bigInteger', 'nullable' => true],
        'last_updated_by_id' => ['type' => 'bigInteger', 'nullable' => true],
    ],
),
```

```php
// Migration for the columns
Schema::table('invoices', function (Blueprint $table) {
    $table->unsignedBigInteger('created_by_id')->nullable();
    $table->unsignedBigInteger('last_updated_by_id')->nullable();
});
```

## overrideUserstamps

Set `overrideUserstamps: true` on a `RecordTableType` to let clients supply
these columns explicitly (e.g. bulk imports where the importer identity is
passed per row):

```php
new RecordTableType(
    table: 'invoices',
    pmsName: 'invoice',
    overrideUserstamps: true,
    columns: [
        'created_by_id' => ['type' => 'bigInteger', 'nullable' => true],
    ],
),
```

When `true`, a client-provided `created_by_id`/`last_updated_by_id` value in the
payload is kept as-is; when `false` (default), the authenticated user's ID always
wins.

## Interaction with Own-Records Scoping

The own-records scoping pass reads the same columns: it prefers
`created_by_id`, falls back to `created_by`, and skips scoping when neither is
declared. A table that only declares `created_by_id` works correctly — see
[Own-Records Scoping](/guide/features/feature-permission) for details.

## Audit Logs

These columns are **not** the same as the audit module. The audit module writes
event history into `sp_audit_logs` (with its own `user_id` column); userstamps
live on your business tables and answer "who created/updated this row?" on the
row itself. Both can be enabled independently:

- [Audit Module](/features/audit-logging)
- [Audit in Dynamic Record API](/guide/feature-audit-record-hooks)
- [Audit Management Endpoints](/guide/api/api-audit-management-endpoints)

## Related Docs

- [Built-in Role/Permission](/guide/features/feature-permission)
- [Own-Records Scoping](/guide/feature-permission-own-records)
- [Record Type Reference](/guide/api/api-type-reference-and-examples)
- [Record Hooks](/guide/record-hooks)
