---
title: "Expand record.id_type Governance to Attachments, Webhooks, Pivots, and Audit"
description: "Extends the existing record.id_type setting, currently scoped to sp_permissions/sp_roles, to also govern attachments, webhooks, pivot surrogate ids, and sp_audit_logs.id."
keywords:
  - id type
  - uuid
  - integer
  - primary key
  - record.id_type
  - attachments
  - webhooks
  - pivot
  - audit
  - migration
date: 2026-08-05
status: approved
supersedes-scope-of: docs/superpowers/specs/2026-08-04-configurable-id-type-design.md
---

# Expand `record.id_type` Governance to Attachments, Webhooks, Pivots, and Audit

## Problem

`docs/superpowers/specs/2026-08-04-configurable-id-type-design.md` introduced a global
`record.id_type` setting, deliberately scoped to `sp_permissions.id` and `sp_roles.id`
(plus their foreign key columns) only. Everything else was explicitly excluded:

- `sp_attachments`, `sp_attachment_folders`, `sp_webhook_endpoints`,
  `sp_webhook_subscriptions`, `sp_webhook_deliveries` — hardcoded `uuid('id')->primary()`
  forever.
- `sp_role_permissions.id`, `sp_model_has_roles.id`, `sp_model_permissions.id`,
  `sp_audit_logs.id` — hardcoded `bigIncrements`/`id()` forever.

In practice, a client who sets `record.id_type = 'uuid'` expecting a uniform ID scheme
across every bundled table sees only `sp_permissions`/`sp_roles` change — attachments,
webhooks, pivots, and audit stay exactly as they were. This is confusing: from the
outside there is one setting, and it silently governs only part of the schema.

This spec removes those exclusions. After this change, `record.id_type` governs every
bundled table's own primary key, with two narrow exceptions that remain excluded for
concrete mechanical reasons (see "Still excluded" below).

## Scope

**Newly governed by `id_type`:**

| Table | Column | Role |
|---|---|---|
| `sp_attachments` | `id` | entity PK |
| `sp_attachment_folders` | `id` | entity PK |
| `sp_attachments` | `folder_id` | FK to `sp_attachment_folders.id` |
| `sp_attachment_folders` | `parent_id` | FK to `sp_attachment_folders.id` (self-referential) |
| `sp_webhook_endpoints` | `id` | entity PK |
| `sp_webhook_subscriptions` | `id` | entity PK |
| `sp_webhook_deliveries` | `id` | entity PK |
| `sp_webhook_subscriptions` | `endpoint_id` | FK to `sp_webhook_endpoints.id` |
| `sp_webhook_deliveries` | `endpoint_id` | FK to `sp_webhook_endpoints.id` |
| `sp_role_permissions` | `id` | pivot surrogate PK (previously ungoverned) |
| `sp_model_has_roles` | `id` | pivot surrogate PK (previously ungoverned) |
| `sp_model_permissions` | `id` | pivot surrogate PK (previously ungoverned) |
| `sp_audit_logs` | `id` | entity PK (previously ungoverned) |

**Still excluded, unchanged from the original spec:**

- `sp_attachment_links.id` — a surrogate join-row id in the same category as the pivot
  ids above, but not named in this expansion. Nothing references it, and it is already
  declared `'integer'` in `config/sp-attachments.php`. It stays `bigIncrements` always.
- The four client-reference columns fixed by the original spec
  (`sp_model_has_roles.model_id`, `sp_model_permissions.model_id`,
  `sp_audit_logs.entity_id`, `sp_audit_logs.user_id`) remain `string`, always — they
  point at arbitrary client models whose key type this package cannot know.

**Explicitly out of scope:**

- Conversion tooling for installs that have already migrated. Unchanged from the
  original spec: the setting takes effect at first migration.
- Per-module configuration. Still one global knob.

## Breaking change: attachments and webhooks

The original spec excluded attachments/webhooks specifically to keep `record.id_type`'s
`integer` default a true no-op for every existing install, since attachments/webhooks
ship UUID today. This expansion accepts breaking that guarantee for those two modules:

With `record.id_type` left unset (default `integer`), attachments and webhooks switch
from UUID primary keys to `bigIncrements`. Any install that wants to keep its current
UUID schema must set `'id_type' => 'uuid'` in `config/record.php` before its next fresh
migration. Installs that have already migrated are physically unaffected either way —
this only matters for new environments (fresh dev setups, new staging/prod deploys).
This will ship with a CHANGELOG entry under "Breaking Changes" spelling out the action
required.

Permissions, roles, pivots, and audit are unaffected by this risk: permissions/roles
were already integer by default (a no-op, per the original spec), and pivots/audit
were also already integer by default, so governing them changes nothing for an
install that never sets `id_type`.

## Mechanism: migrations and config

Both are the same mechanical pattern already established for `sp_permissions`/`sp_roles`
in the original spec — no new helper code:

**Migrations** use the existing `MigrationIdHelper::primary()` / `::foreign()`
(`src/Database/MigrationIdHelper.php`, unchanged) in place of hardcoded column calls:

- `database/migrations/2024_01_01_000000_create_sp_attachments_tables.php`:
  `sp_document_folders.id` / `sp_attachments.id` become `MigrationIdHelper::primary()`;
  `parent_id` / `folder_id` become `MigrationIdHelper::foreign()`.
- `database/migrations/2024_01_01_000001_create_sp_webhooks_tables.php`: the three
  `id` columns become `MigrationIdHelper::primary()`; the two `endpoint_id` columns
  become `MigrationIdHelper::foreign()`.
- `database/migrations/2026_05_13_000000_create_sp_permissions_tables.php`:
  `sp_role_permissions.id`, `sp_model_has_roles.id`, `sp_model_permissions.id` change
  from `bigIncrements('id')` to `MigrationIdHelper::primary()`.
- `database/migrations/2025_01_27_000000_create_audit_logs_table.php`:
  `sp_audit_logs.id` changes from `bigIncrements`/`id()` to
  `MigrationIdHelper::primary()`.

**Config** mirrors the exact pattern already live in `config/sp-permissions.php:148`:

```php
'id' => ['type' => RecordConfigService::idType() === 'uuid' ? 'uuid' : 'bigIncrements', 'nullable' => false],
```

Applied to the `id` columns in `config/sp-attachments.php` (currently hardcoded
`'uuid'` at two places) and `config/sp-webhooks.php` (currently hardcoded `'uuid'` at
three places), and added to `config/sp-audit.php` (currently hardcoded `'integer'`).

## Mechanism: pivot surrogate id generation

`sp_role_permissions.id`, `sp_model_has_roles.id`, and `sp_model_permissions.id` are
never supplied by their write path: `Role::givePermissionTo()` /
`HasRoles::assignRole()` etc. call `sync()`/`attach()`/`syncWithoutDetaching()` with
plain id arrays. Without a custom pivot class, Laravel's `attach()` performs a raw
`insert()` (`InteractsWithPivotTable.php:345`) that never touches the Eloquent model
layer — nothing would generate a uuid for these rows.

The fix: register a custom `Pivot` class via `->using()` on each relationship. Laravel
then routes `attach()` through `attachUsingCustomClass()`
(`InteractsWithPivotTable.php:377-386`), which does
`$this->newPivot($record, false)->save()` — an actual Eloquent create, firing the
`creating` event.

This means the existing `HasConfigurableKey` trait (`src/Authorization/Traits/HasConfigurableKey.php`,
already used by `Role`/`Permission` for their own primary keys, unmodified) works for
pivot models too — it keys off `getKeyName()`/`getKeyType()`, generic to any Eloquent
model.

**New files:**

- `src/Authorization/Models/Pivots/RolePermissionPivot.php` — extends
  `Illuminate\Database\Eloquent\Relations\Pivot`, `protected $table = 'sp_role_permissions'`,
  `use HasConfigurableKey;`
- `src/Authorization/Models/Pivots/ModelHasRolePivot.php` — extends
  `Illuminate\Database\Eloquent\Relations\MorphPivot` (the relation is polymorphic),
  `protected $table = 'sp_model_has_roles'`, `use HasConfigurableKey;`
- `src/Authorization/Models/Pivots/ModelPermissionPivot.php` — extends `MorphPivot`,
  `protected $table = 'sp_model_permissions'`, `use HasConfigurableKey;`

**Wiring — add `->using(...)` to the four relationship definitions touching these
tables:**

- `Role::permissions()` (`src/Authorization/Models/Role.php:101`) → `RolePermissionPivot`
- `Permission::roles()` (`src/Authorization/Models/Permission.php:38`, inverse side of
  the same pivot) → `RolePermissionPivot`
- `HasRoles::roles()` (`src/Authorization/Traits/HasRoles.php:19`) → `ModelHasRolePivot`
- `HasRoles::permissions()` (`src/Authorization/Traits/HasRoles.php:36`) →
  `ModelPermissionPivot`

No call site that invokes `assignRole`/`givePermissionTo`/`syncPermissions`/etc. changes
— they keep passing plain id arrays. `detach()` and sync-removal match rows by the
foreign+related key columns, not the pivot's own `id`, so that path is unaffected.

## Mechanism: audit id generation, and removing redundant manual uuid assignment

**`sp_audit_logs`** is written via a raw `DB::table(...)->insert($auditData)`
(`src/Services/AuditLogService.php:167`), which supplies no `id`. Immediately before
that call, add:

```php
if (RecordConfigService::idType() === 'uuid' && !array_key_exists('id', $auditData)) {
    $auditData['id'] = (string) Str::uuid();
}
```

This is the raw-insert equivalent of what `RecordService::createRecord()` already does
generically for Record-API-driven creates (`src/Services/RecordService.php:69-71`,
keyed off `SchemaRegistryUtils::isUuidColumnType()`) — audit's insert doesn't go
through that path, so it needs its own copy of the same check.

**Attachments/webhooks**, by contrast, already go through
`RecordService::executeCreate()`, which means `RecordService::createRecord()`'s
existing generic uuid-generation already applies to them once their config declares
`'type' => 'uuid'`. Four call sites currently short-circuit that by hardcoding the id
onto the payload before calling create — this becomes actively wrong under
`id_type = 'integer'` (a uuid string inserted into a `bigIncrements` column fails
outright), so the hardcoded assignment is removed, not replaced:

- `src/Http/Controllers/AttachmentUploadController.php:467` (`sp_attachment_folders` create)
- `src/Http/Controllers/AttachmentUploadController.php:766` (`sp_attachments` create)
- `src/Http/Controllers/AttachmentUploadController.php:869` (second `sp_attachments` create branch)
- `src/Triggers/WebhookTrigger.php:117` (`sp_webhook_deliveries` create)

In each case, drop the `'id' => Str::uuid()->toString(),` line from the payload array.
`RecordService::createRecord()` fills it in correctly either way.

## Testing

**Tests that currently encode the exclusion being removed, and must flip:**

- `tests/Feature/PackageTableUuidPrimaryKeyTest.php` — runs at the default `id_type`
  (unset) and asserts attachments/webhooks are always UUID. Becomes
  `id_type`-parametrized (mirroring `IdTypeUuidTest`'s `getEnvironmentSetUp` pattern,
  since Testbench migrates before `setUp()` runs): under `integer` (default), these
  tables now assert `bigIncrements`/auto-increment; under `uuid`, they keep asserting
  UUID. `sp_attachment_links`'s dedicated "stays auto-incrementing" test is unchanged.
- `tests/Feature/IdTypeUuidTest.php` (whole class runs under `id_type = 'uuid'`):
  - `surrogate_pivot_ids_stay_auto_incrementing_integers` — under `uuid` this must now
    assert `Str::isUuid()` instead. Add a sibling test (new test case or class running
    at the default) proving pivot ids still stay integer when `id_type` is unset.
  - `audit_log_id_stays_an_auto_incrementing_integer` — same treatment: invert under
    `uuid`, add an integer-default sibling.
  - `attachment_tables_still_use_uuid_keys` — no assertion change; this test already
    runs under `id_type = 'uuid'`, where old and new behavior agree.

**New coverage:**

- Pivot writes succeed end-to-end under `uuid` for `sp_model_has_roles` and
  `sp_model_permissions` (via `assignRole` / generic model-permission assignment) —
  `role_permission_pivot_writes_succeed_with_uuid_keys` already covers
  `sp_role_permissions` alone.
- Audit entries write and read back correctly under both `id_type` values.

**Known wider blast radius, to sweep during implementation:** any test elsewhere in the
suite that creates an attachment/webhook record under default config and asserts a
UUID-shaped id will break, since that default is intentionally changing. Grep the suite
for `Str::isUuid` combined with attachment/webhook fixtures and fix each on sight.

## Files affected

- `database/migrations/2024_01_01_000000_create_sp_attachments_tables.php`
- `database/migrations/2024_01_01_000001_create_sp_webhooks_tables.php`
- `database/migrations/2026_05_13_000000_create_sp_permissions_tables.php`
- `database/migrations/2025_01_27_000000_create_audit_logs_table.php`
- `config/sp-attachments.php`
- `config/sp-webhooks.php`
- `config/sp-audit.php`
- `src/Services/AuditLogService.php`
- `src/Http/Controllers/AttachmentUploadController.php`
- `src/Triggers/WebhookTrigger.php`
- `src/Authorization/Models/Pivots/RolePermissionPivot.php` — new
- `src/Authorization/Models/Pivots/ModelHasRolePivot.php` — new
- `src/Authorization/Models/Pivots/ModelPermissionPivot.php` — new
- `src/Authorization/Models/Role.php`
- `src/Authorization/Models/Permission.php`
- `src/Authorization/Traits/HasRoles.php`
- `tests/Feature/PackageTableUuidPrimaryKeyTest.php`
- `tests/Feature/IdTypeUuidTest.php`
- `docs/guide/features/feature-record-data-types.md`
- `CHANGELOG.md` — breaking-change entry
