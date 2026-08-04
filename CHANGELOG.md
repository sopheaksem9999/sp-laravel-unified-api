# Changelog

All notable changes to `sp-laravel-api` will be documented in this file.

## [Unreleased]

### Added
- **Upsert Support**: Added `POST /{table}/upsert` and `POST /{table}/bulk/upsert` endpoints for atomic create-or-update operations.
- **Match On Parameter**: Required `match_on` query parameter for upsert operations to define matching columns dynamically.
- **Configuration**: Added `$canUpsert` to `RecordTableType` to control upsert endpoint availability (default: `true`).
- **OpenAPI**: Updated OpenAPI generator to include single and bulk upsert endpoints with schema definitions.
- **API Client Exporters**: Two new Artisan commands to export the OpenAPI spec to API client collections.
  - `sp-laravel-api:export-bruno` writes a Bruno collection folder (`api-client/bruno/`) with `bruno.json`, `collection.bru`, one subfolder of `.bru` files per table/RPC tag, and an `environments/Local.bru` environment (`baseUrl`/`apiPrefix` from `config('app.url')`/`config('record.api_prefix')`, `bearerToken` as a secret var).
  - `sp-laravel-api:export-postman` writes a Postman v2.1 collection to `api-client/postman/collection.json`.
  - Both commands support `--output=<path>`, `--regen=<list|all>` (case-insensitive against OpenAPI tags; `rpc` is a wildcard that regenerates every RPC-prefixed folder at once, e.g. `RPC`, `RPC - Auth`, `RPC - Media`), and `--dry-run`.
  - Both commands are diff-aware: existing requests are skipped unless listed in `--regen`; new requests are added.
  - RPC endpoints are grouped into one folder per real OpenAPI tag (`RPC`, `RPC - Auth`, `RPC - Media`, ...) instead of a single collapsed `RPC` folder, matching the tags already shown in the docs UI.
  - Each generated request's auth requirement reflects the table's `isAuthRead`/`isAuthWrite` or function's `isPublic` flag: public endpoints render `auth: none` (Bruno) / `"auth":{"type":"noauth"}` (Postman) instead of always inheriting the collection's bearer auth.
  - If `config('record.api_docs.login_api')` matches a generated RPC request's path, that request gets an auto-generated script (Bruno `script:post-response`, Postman `"test"` event) that captures the access token from the response (same key-search algorithm as the docs UI's login proxy) and writes it to the `bearerToken` variable — run "Login" once and every other request in the session is authenticated.
  - See `docs/guide/modules/module-api-clients.md` for full usage.
- **Relationships**: Added `RecordMorphHasManyType` for polymorphic one-to-many relationships (`morphMany`, no pivot table) — a related table with a discriminator column (e.g. `target_type`) and FK column (e.g. `target_id`), scoped per parent table via `morphClass`. Supports nested writes; client-supplied discriminator values in the payload are always overridden server-side. See `docs/core-concepts/relationships.md` (Morph Relationships) and `docs/guide/api/api-type-reference-and-examples.md` (Type Reference).

### Changed

- **Configurable ID Type**: New `record.id_type` setting (`'integer'` default, or `'uuid'`) governs the primary keys of the package's own `sp_permissions` and `sp_roles` tables and the foreign keys that reference them. The default is a no-op for every existing install. Pick it before the package migrations first run; it is not safe to change afterwards (see the comment in `config/record.php`). `sp_attachments`, `sp_attachment_folders` and `sp_webhook_*` keep uuid keys; the pivot ids and `sp_audit_logs.id` stay auto-incrementing integers. See `docs/guide/features/feature-record-data-types.md`.
- **Client Reference Columns**: The four columns that point at arbitrary client-owned models — `sp_model_has_roles.model_id`, `sp_model_permissions.model_id`, `sp_audit_logs.entity_id` and `sp_audit_logs.user_id` — are now `varchar(191)` instead of `unsignedBigInteger`, so a project whose `User` (or any audited model) has a uuid primary key can actually store its key. `config/audit.php` declares `entity_id` and `user_id` as `string` to match.
- **⚠️ Migration — existing installs**: `2026_08_05_000000_convert_client_reference_columns_to_string` **ALTERs those four columns in place** so an already-migrated project's schema matches the metadata the package now ships. It is a no-op on a fresh install and when a table is absent (e.g. `sp_audit_logs` with `audit.enabled` false), and it does not drop or recreate any index. **Plan for it on large tables**: `sp_audit_logs` is usually the biggest table in the schema and MySQL rewrites the whole table for this change, holding a metadata lock for the duration; PostgreSQL takes an `ACCESS EXCLUSIVE` lock and rewrites too. Run it in a maintenance window, or use an online-schema-change tool and mark the migration as run. `down()` is intentionally a no-op — a uuid cannot be cast back into a bigint without destroying data.
- **Attachments**: Renamed the `sp_document_folders` table to `sp_attachment_folders`. The old config key stays registered as a deprecated alias pointing at the same table (so the generic `/{table}` CRUD route under the old name keeps working); the `/folders` and `/folders/{id}` endpoints are unaffected since they never exposed the table name in the URL. See `docs/guide/features/feature-attachments-folders.md`.
