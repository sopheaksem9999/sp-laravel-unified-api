---
title: "Upgrade 0.4.80 → 0.4.82"
description: "Config and schema changes between 0.4.80 and 0.4.82, the one migration that alters existing tables, and why no action is required."
keywords:
  - upgrade
  - migration
  - id_type
  - configuration
  - breaking changes
  - sp_audit_logs
---

# Upgrade 0.4.80 → 0.4.82

Two things in this release need your attention. Everything else defaults to the
behavior you already had.

1. **Breaking:** if any client calls `/{apiPrefix}/sp_document_folders`, that
   route is gone. See [Folder table rename](#3-sp_document_folders--sp_attachment_folders).
2. **Operational:** one migration ALTERs existing tables. If `sp_audit_logs` is
   large, read [The migration](#the-migration) before deploying.

## Config changes at a glance

| File | Change | Action |
|---|---|---|
| `config/record.php` | New `id_type` key | None — defaults to today's behavior |
| `config/audit.php` | `entity_id`, `user_id` now `string` | None |
| `config/permissions.php` | `id` type derived from `id_type` | None |
| `config/attachments.php` | `sp_document_folders` key replaced by `sp_attachment_folders` | **Update any URL using the old table name** |

If you have never published these files, there is nothing to do for the first
three. If you have, see [Re-publishing](#re-publishing).

## 1. `record.id_type` (new)

```php
// config/record.php
'id_type' => 'integer', // uuid|integer
```

Controls the primary key type of the package's own `sp_permissions` and
`sp_roles` tables, and the foreign keys that reference them. Set it to `'uuid'`
if your project uses UUID primary keys and you want the roles and permissions
API surface to match.

**The default `'integer'` reproduces exactly what 0.4.80 did.** Leave it alone
and nothing changes.

**Choose it before the package migrations first run.** It is read at migration
time, not at runtime. Changing it on a project that has already migrated does
not alter existing tables — and role creation then fails loudly with a type
error, because the model generates a key of one shape and the column expects
the other. If you need to switch an existing project, treat it as a data
migration you write yourself.

Not governed by this setting:

- `sp_attachments`, `sp_attachment_folders` and `sp_webhook_*` always use uuid
  primary keys.
- `sp_role_permissions.id`, `sp_model_has_roles.id`, `sp_model_permissions.id`
  and `sp_audit_logs.id` are always auto-incrementing integers.

Full reference: [Record Data Types](/guide/feature-record-data-types).

## 2. Client-reference columns are now strings

Four columns point at *your* models rather than at package tables. They were
`unsignedBigInteger`, which meant a project whose `User` (or any audited model)
has a UUID primary key could not store its key at all — role assignment and
audit writes were silently broken for those projects.

They are now `varchar(191)`:

- `sp_model_has_roles.model_id`
- `sp_model_permissions.model_id`
- `sp_audit_logs.entity_id`
- `sp_audit_logs.user_id`

`config/audit.php` declares `entity_id` and `user_id` as `string` to match. A
string holds an integer key or a UUID equally well, so integer-keyed projects
are unaffected.

191 rather than 255 because the two `model_id` columns sit in composite unique
indexes; at 255 under `utf8mb4` those indexes exceed MySQL's 3072-byte InnoDB
limit once `id_type` is `uuid` and tenancy is on, and the migration fails
outright.

## 3. `sp_document_folders` → `sp_attachment_folders`

**This is the one breaking change in the release.**

The table was renamed by migration
`2026_08_02_000000_rename_sp_document_folders_table`, and the old key has been
removed from `config/attachments.php`. At 0.4.80 that file registered
`'sp_document_folders'`; it now registers only `'sp_attachment_folders'`.

A deprecated alias existed briefly during the transition and has since been
removed. It is **not** present in 0.4.82.

### What breaks

Any request to the generic CRUD route under the old table name:

```
GET  /{apiPrefix}/sp_document_folders        -> 404
POST /{apiPrefix}/sp_document_folders        -> 404
```

Replace `sp_document_folders` with `sp_attachment_folders` in those URLs.

Also check for the old name in:

- `config/record.php` — `rate_limits`, `middleware_map.tables`,
  `cache.per_table`, `cache.per_table_ttl`, `index_hints`
- permission names, if you generated them from the table name
- any exported Bruno or Postman collection (re-run the export command)

### What does not break

`/folders` and `/folders/{id}` never exposed the table name in the URL and are
unaffected.

### If folder queries fail with "no such table"

An earlier revision of the rename migration carried a guard intended to skip
SQLite:

```php
config('database.default') !== 'sqlite'
```

`config('database.default')` returns the connection **name**, not the driver, so
it compared two different things and never reliably matched. The condition has
been removed: `Schema::rename` works on every supported driver, SQLite included,
and the rename now always runs when `sp_document_folders` exists and
`sp_attachment_folders` does not.

If you are on a version that still has the guard and your connection happens to
be named `sqlite`, rename the table by hand:

```sql
ALTER TABLE sp_document_folders RENAME TO sp_attachment_folders;
```

See [Attachment Folders](/guide/feature-attachments-folders).

## The migration

`2026_08_05_000000_convert_client_reference_columns_to_string` ALTERs the four
columns above in place, so an already-migrated project's schema matches the
metadata the package now ships. Without it, `?entity_id=empty.null`-style
filters break: PostgreSQL returns a 500, MySQL silently returns wrong rows.

It is a no-op on a fresh install, and when a table is absent (for example
`sp_audit_logs` with `audit.enabled` false). It does not drop or recreate any
index.

**⚠️ Plan for it on large tables.** `sp_audit_logs` is usually the biggest table
in the schema:

- **MySQL** rewrites the whole table and holds a metadata lock for the duration.
- **PostgreSQL** takes an `ACCESS EXCLUSIVE` lock and rewrites too.

Run it in a maintenance window, or apply the change with an online schema change
tool (`pt-online-schema-change`, `gh-ost`) and then mark the migration as run.

`down()` is intentionally a no-op. A UUID cannot be cast back into a bigint
without destroying data, so reversing is not offered rather than offered
destructively.

## Re-publishing

You do not need to re-publish anything. The package merges its own defaults
under your published files, so new keys appear automatically — with one
exception.

**`config/record.php` is publish-only.** The package never merges defaults into
it, so a new key like `id_type` will *not* appear in your copy. It still works:
`RecordConfigService::idType()` falls back to `'integer'` when the key is
absent, which is the same value the published default carries. Add the key by
hand only when you want to set it to `'uuid'`.

To pull in the updated comments and defaults for the other files:

```bash
php artisan vendor:publish --tag=sp-laravel-api-config --force
```

`--force` overwrites your customizations. Diff before you run it.

## Verifying the upgrade

```bash
php artisan migrate
php artisan sp-laravel-api:validate
```

To confirm the column change landed:

```sql
-- MySQL
SHOW COLUMNS FROM sp_audit_logs LIKE 'entity_id';   -- varchar(191)

-- PostgreSQL
\d sp_audit_logs                                     -- entity_id | character varying(191)
```

## Coming next

The package's five unprefixed config files — `record.php`, `permissions.php`,
`audit.php`, `attachments.php`, `webhooks.php` — will be renamed to `sp-record.php`,
`sp-permissions.php` and so on, so it is obvious at a glance which files in your
`config/` directory belong to this package.

**That change will also require no action.** The old names will keep working
indefinitely, and `config('record.tables')` in your own code will continue to
resolve. This section will be filled in with the details when the change ships;
it is listed here only so the rename is not a surprise.

Design notes live in the repository at
`docs/superpowers/specs/2026-08-04-config-namespace-prefix-design.md`.
