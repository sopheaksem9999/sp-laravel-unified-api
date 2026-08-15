---
title: "Userstamps (created_by / updated_by)"
description: "How the package auto-populates created_by, created_by_id, updated_by, last_updated_by, and last_updated_by_id columns from the authenticated user, the overrideUserstamps escape hatch, and the audit-log relationship."
keywords:
  - userstamps
  - created_by
  - created_by_id
  - updated_by
  - last_updated_by
  - last_updated_by_id
  - overrideUserstamps
  - audit user tracking
---

# Userstamps

The package auto-populates audit-style user columns on create/update when the
table schema declares them. This is the data source behind own-records scoping
(see [Own-Records Scoping](/guide/features/feature-permission)).

## Supported Column Names

| Column | Filled on | Description |
|---|---|---|
| `created_by` | create | ID of the user who created the row |
| `created_by_id` | create | Alias-style variant of `created_by` |
| `updated_by` | create + update | ID of the last user who wrote the row |
| `last_updated_by` | create + update | Alias-style variant of `updated_by` |
| `last_updated_by_id` | create + update | Alias-style variant of `updated_by` |

Any subset works — the package only touches columns present in the table's
`columns` map.

## How It Works

On every create/update through the dynamic record API, the payload extractor
and `RecordService::applyTimestampsAndAuditFields()` inspect the authenticated
user and the table schema:

```php
// create: only created_by / created_by_id filled
// update: updated_by / last_updated_by / last_updated_by_id filled
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
    columns: [
        // ...
        'created_by' => ['type' => 'bigInteger', 'nullable' => true],
        'updated_by' => ['type' => 'bigInteger', 'nullable' => true],
    ],
),
```

```php
// Migration for the columns
Schema::table('invoices', function (Blueprint $table) {
    $table->unsignedBigInteger('created_by')->nullable();
    $table->unsignedBigInteger('updated_by')->nullable();
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
        'created_by' => ['type' => 'bigInteger', 'nullable' => true],
    ],
),
```

When `true`, a client-provided `created_by`/`updated_by` value in the payload
is kept as-is; when `false` (default), the authenticated user's ID always wins.

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
- [Record Type Reference](/guide/api/api-type-reference-and-examples)
- [Record Hooks](/guide/record-hooks)
