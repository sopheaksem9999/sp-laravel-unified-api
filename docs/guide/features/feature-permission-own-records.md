---
title: "Own-Records Scoping (viewOwn)"
description: "Restrict list queries to the authenticated user's own records via the viewOwn permission convention."
keywords:
  - viewOwn
  - own records
  - scoping
  - permission
  - created_by
  - created_by_id
  - restrict to own records
---

# Own-Records Scoping

List queries can be automatically restricted to records the authenticated user
created, via the `viewOwn`-style permission convention.

## How It Works

During query building (`QueryBuilderFiltersUtils::apply()`), if the table has a
`pmsName`, a user is authenticated, and the Gate allows the own-records
permission, the list query is auto-scoped:

```text
permission name = {own_records_permission_prefix}{permission_separator}{pmsName}
                 e.g. viewOwn:invoice
```

```sql
WHERE invoices.{owner column} = {authenticated user id}
```

## Owner Column Resolution

The scoping pass picks the owner column from the table's declared schema, in
this order:

1. `created_by_id` when present
2. `created_by` as the legacy fallback
3. Neither → scoping is skipped silently (no error, no filter)

Follow the [Userstamps](/guide/feature-userstamps) convention: declare
`created_by_id` as the raw userstamp column and reserve `created_by` for the
`belongsTo` relationship alias. A table that declares only `created_by_id` is
scoped correctly on that column.

## Config

```php
// config/sp-record.php
'own_records_permission_prefix' => 'viewOwn',   // default
'permission_separator' => ':',                  // default
```

With a table declared as:

```php
new RecordTableType(
    table: 'invoices',
    pmsName: 'invoice',
    columns: [
        // ...
        'created_by_id' => ['type' => 'bigInteger', 'nullable' => true],
    ],
),
```

A user holding `viewOwn:invoice` gets only their own rows from
`GET /{apiPrefix}/invoices`; users without that permission see the full list
(subject to the normal table auth).

## Requirements and Caveats

- **The table must declare `created_by_id` or `created_by` in `columns`** —
  otherwise scoping is skipped and the user sees the full list (subject to the
  normal table auth). See [Userstamps](/guide/feature-userstamps).
- `pmsName` can be a string or an array of aliases; each alias is checked, and
  the first match wins.
- The check runs only when a user is authenticated (`Auth::check()`); guests
  fall through to normal table auth.
- The check applies to list queries only; single-record reads and writes are
  not affected.
- `record.restrict_to_own_records` exists in the shipped config but is
  **not wired up** in the current runtime — the permission-prefix check above
  is the only live own-records path.

## Real-World Example

```php
// Assign the permission
$user->givePermissionTo('viewOwn:invoice');

// GET /api/v1/invoices -> only rows where created_by_id = $user->id
```

For a manual version using record hooks (e.g. team-scoped visibility), see
[Record Hooks](/guide/record-hooks).

## Related Docs

- [Built-in Role/Permission](/guide/feature-permission)
- [Userstamps](/guide/feature-userstamps)
