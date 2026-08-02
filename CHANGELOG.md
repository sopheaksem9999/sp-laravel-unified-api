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

- **Attachments**: Renamed the `sp_document_folders` table to `sp_attachment_folders`. The old config key stays registered as a deprecated alias pointing at the same table (so the generic `/{table}` CRUD route under the old name keeps working); the `/folders` and `/folders/{id}` endpoints are unaffected since they never exposed the table name in the URL. See `docs/guide/features/feature-attachments-folders.md`.
