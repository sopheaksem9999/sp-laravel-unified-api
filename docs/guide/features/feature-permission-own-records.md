---
title: "Own-Records Scoping (viewOwn)"
description: "Restrict list queries to the authenticated user's own records via the viewOwn permission convention."
keywords:
  - viewOwn
  - own records
  - scoping
  - permission
  - ownerColumn
  - created_by
  - created_by_id
  - user_id
  - restrict to own records
---

# Own-Records Scoping

List queries can be automatically restricted to the authenticated user's own
records, via the `viewOwn`-style permission convention.

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

`created_by_id` / `created_by` are **audit stamps**: they record *who inserted
the row*, which may be an admin, support agent, or system worker acting on a
customer's behalf. Many domain tables track the record's actual **owner** — the
subject or beneficiary — in a separate column such as `user_id`.

Scoping on the audit stamp when the two differ hides the record from the user
who owns it. Example: an admin approves a purchase for a customer, so
`created_by_id = {admin}` and `user_id = {customer}`; scoping on `created_by_id`
returns zero rows for that customer.

The owner column is resolved in this order — **the first candidate the table
actually declares in `columns` wins**:

1. The table's explicit `ownerColumn`.
2. Each entry of `record.own_records_owner_columns`, in order
   (default: `created_by_id`, then `created_by`).
3. Nothing matched → scoping is skipped silently (no error, no filter).

### Per-table (recommended)

Declare `ownerColumn` on the tables whose owner is the record's subject:

```php
new RecordTableType(
    table: 'video_purchases',
    pmsName: 'video_purchase',
    ownerColumn: 'user_id',   // audit author stays in created_by_id
    columns: [
        // ...
        'user_id' => ['type' => 'uuid', 'nullable' => false],
        'created_by_id' => ['type' => 'uuid', 'nullable' => true],
    ],
),
```

Now `viewOwn:video_purchase` scopes on `video_purchases.user_id`, so rows an
admin created or approved on the customer's behalf stay visible to that
customer.

### Globally

When most domain tables track ownership in `user_id`, change the fallback order
once instead of annotating every table:

```php
// config/sp-record.php
'own_records_owner_columns' => ['user_id', 'created_by_id', 'created_by'],
```

Tables that do not declare `user_id` fall through to the audit stamps, so
audit-owned tables keep working unchanged. A per-table `ownerColumn` still wins
over this list.

> **Changing this list changes who can see what.** Only put `user_id` first if
> `user_id` really means "the user this row belongs to" on every table that
> declares it. On a table where `user_id` is some *other* user reference (the
> employee a review is about, a message recipient), putting it first would
> expose rows the user did not previously see. Prefer the per-table
> `ownerColumn` when your tables are not consistent.

### Why `last_updated_by_id` is not a candidate

`last_updated_by_id` / `last_updated_by` / `updated_by` are deliberately **not**
in the default resolution order, and should not be added to it. They record the
*last writer*, so they change on every write: an admin editing a customer's row
would move `last_updated_by_id` to the admin, taking visibility away from the
customer and granting it to the admin. Ownership must be stable, so use
`user_id` (or another immutable owner column) via `ownerColumn`.

The resolver accepts any column the table declares, so `ownerColumn:
'last_updated_by_id'` is *possible* — it is not recommended.

Note the exact spelling: the package's userstamp columns are `created_by_id` and
`last_updated_by_id`. A column named `last_update_by_id` (no `d`) is not
recognised by the userstamp auto-fill and is not auto-detected here — see
[Userstamps](/guide/feature-userstamps).

## Config

```php
// config/sp-record.php
'own_records_permission_prefix' => 'viewOwn',                  // default
'permission_separator' => ':',                                 // default
'own_records_owner_columns' => ['created_by_id', 'created_by'], // default
```

A user holding `viewOwn:invoice` gets only their own rows from
`GET /{apiPrefix}/invoices`; users without that permission see the full list
(subject to the normal table auth).

## Requirements and Caveats

- **The resolved owner column must be declared in `columns`** — otherwise
  scoping is skipped and the user sees the full list (subject to the normal
  table auth). This includes a typo'd or not-yet-migrated `ownerColumn`: it is
  ignored and resolution falls through to the next candidate rather than
  erroring on an unknown column. Verify with
  `php artisan sp-laravel-api:validate` after changing it.
- Follow the [Userstamps](/guide/feature-userstamps) convention: declare
  `created_by_id` as the raw userstamp column and reserve `created_by` for the
  `belongsTo` relationship alias.
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
$user->givePermissionTo('viewOwn:video_purchase');

// Table declares ownerColumn: 'user_id'
// GET /api/v1/video_purchases -> only rows where user_id = $user->id,
// including those an admin created or approved for this user.
```

For a manual version using record hooks (e.g. team-scoped visibility), see
[Record Hooks](/guide/record-hooks).

## Related Docs

- [Built-in Role/Permission](/guide/feature-permission)
- [Userstamps](/guide/feature-userstamps)
- [Type Reference](/guide/api-type-reference-and-examples)
