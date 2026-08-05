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
the comparison was against the wrong thing — and it fired for any install whose
default connection happened to be **named** `sqlite`, whatever driver that
connection actually used.

That is not a rare accident. Stock Laravel 11/12 `config/database.php` ships:

```php
'default' => env('DB_CONNECTION', 'sqlite'),
```

against a connection key literally named `sqlite`. So on a default install the
guard fired routinely: the rename was skipped, the table stayed
`sp_document_folders`, and `config/attachments.php` already pointed at
`sp_attachment_folders` — every folder query failed with a missing-table error.
Installs that renamed their connection (this package's own test suite calls it
`testing`) never saw it, which is why the bug survived as long as it did.

The condition has been removed rather than corrected to `DB::getDriverName()`,
which would have skipped SQLite for real and stranded the table on every SQLite
install. `Schema::rename` works on every supported driver, so the rename now
always runs when `sp_document_folders` exists and `sp_attachment_folders` does
not.

If you are on a version that still has the guard, rename the table by hand:

```sql
ALTER TABLE sp_document_folders RENAME TO sp_attachment_folders;
```

The fixed migration detects a table renamed this way and does nothing.

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

## Config files renamed to `sp-*`

The package's five previously-unprefixed config files have been renamed so it
is obvious at a glance which files in your `config/` directory belong to this
package:

| Old filename | New filename |
|---|---|
| `record.php` | `sp-record.php` |
| `permissions.php` | `sp-permissions.php` |
| `audit.php` | `sp-audit.php` |
| `attachments.php` | `sp-attachments.php` |
| `webhooks.php` | `sp-webhooks.php` |

**No action is required.** Only the filename changed — the config *namespace*
each file feeds did not move, though the two groups of files get there by
different routes:

- `record` is **publish-only** — the package has never merged a vendor default
  into it (see [Re-publishing](#re-publishing) below, unchanged by this
  rename). Whichever filename you have on disk, Laravel loads it under its own
  basename exactly like any other config file: `config/record.php` loads as
  `record`, `config/sp-record.php` loads as `sp-record`. There is no
  `mergeConfigFrom()` call for `record` anywhere in the package.
- `permissions`, `audit`, `attachments` and `webhooks` are still merged the
  same way they always were, via `mergeConfigFrom()` — only the **package's
  own vendor-shipped file** that gets merged changed name, from e.g.
  `config/attachments.php` to `config/sp-attachments.php` inside the package.
  The canonical key each merges into (`attachments`, and so on) is unchanged.

Either way, `config('record.tables')` (and every other call site reading
`record.*`, `permissions.*`, `audit.*`, `attachments.*` or `webhooks.*`) in
your own code is unaffected and keeps resolving exactly as before.

If you have already published an **old**-named file, that alone is the entire
compatibility story: Laravel loads `config/record.php` under key `record` the
same way it always has, and nothing else needs to run for it to keep working.

The `ConfigNamespaceBridge` only has work to do when a **new**-named file is
also present — for example if you (or an earlier partial migration) published
`config/sp-record.php`. Its `adopt()` pass folds that `sp-record`-keyed value
into the canonical `record` key during `register()`, before the merges above
run. It does this with `array_replace_recursive($old, $new)` where `$new` is
the new-named file's value — **the new-named file's values win over the
old-named file's for any key both set.** Its `mirror()` pass then copies the
resolved value back onto the new-named key in `boot()`, so a project that
only ever published the old file can still be read under the new name too.

**Console commands log a deprecation notice** for any old-named file still
found in your `config/` directory (an info-level log line naming the old and
new filename) — this runs on every Artisan invocation, not just once. It is
gated on `runningInConsole()`, so it never fires on HTTP/API requests — under
PHP-FPM the application boots fresh on every request, and without that gate
the same log line would have appeared on every single web request instead of
only when a console command boots the app. Silence it with:

```php
// config/sp-laravel-api.php
'suppress_config_rename_notice' => true,
```

### Migrating voluntarily

There is no deadline to do this, but if you want your `config/` directory to
show only the new names, **rename the files directly rather than running
`vendor:publish`.**

`vendor:publish` writes the package's packaged *defaults*, not your
customizations. Given the precedence rule above — a new-named file's values
win over an old-named file's for any key both set — publishing a fresh
`config/sp-record.php` while your customized `config/record.php` is still on
disk would silently apply the packaged defaults over your customizations for
every key both files set, for as long as both files coexist. That window is
real: it lasts until the next time something boots the app after you publish
and before you finish copying your customizations across and deleting the old
file — a request, a queued job, or even `php artisan sp-laravel-api:validate`
itself.

Renaming avoids that window entirely, because the new-named file is never
anything other than what your old file already was:

1. `git mv config/record.php config/sp-record.php` (repeat for
   `permissions.php` → `sp-permissions.php`, `audit.php` → `sp-audit.php`,
   `attachments.php` → `sp-attachments.php`, `webhooks.php` →
   `sp-webhooks.php`, for whichever of these you've published). A plain `mv`
   works the same outside of git.
2. Optional: check whether the package added any keys since you last
   published. For `permissions`, `audit`, `attachments` and `webhooks` this
   barely matters — a missing key is filled in from the package's own vendor
   default by `mergeConfigFrom()` regardless of which filename you use. For
   `record` a missing key stays missing (see
   [Re-publishing](#re-publishing)), so it's worth diffing against the
   package's current default without publishing it:
   `vendor/sopheak/sp-laravel-api/config/sp-record.php`.
3. Run `php artisan sp-laravel-api:validate` to confirm.

No diff-and-copy step against a freshly published default is needed, because
renaming never puts an uncustomized default file in your live `config/`
directory in the first place.

**One limitation to know about:** the mirroring from canonical name to `sp-*`
name happens once, during `boot()`. A runtime `config()->set('sp-record.x', …)`
made afterwards (for example in your own service provider or a test) does not
propagate back to `record.x`. If your code sets config at runtime, keep doing
it against the canonical name (`record`, `permissions`, `audit`, `attachments`,
`webhooks`), not the `sp-*` one.

Design notes live in the repository at
`docs/superpowers/specs/2026-08-04-config-namespace-prefix-design.md`.
