# Changelog

All notable changes to `sp-laravel-api` will be documented in this file.

## [Unreleased]

### Added
- **Upsert Support**: Added `POST /{table}/upsert` and `POST /{table}/bulk/upsert` endpoints for atomic create-or-update operations.
- **Match On Parameter**: Required `match_on` query parameter for upsert operations to define matching columns dynamically.
- **Configuration**: Added `$canUpsert` to `RecordTableType` to control upsert endpoint availability (default: `true`).
- **OpenAPI**: Updated OpenAPI generator to include single and bulk upsert endpoints with schema definitions.
- **API Client Exporters**: Two new Artisan commands to export the OpenAPI spec to API client collections.
  - `sp-laravel-api:export-bruno` writes a Bruno v3 collection to `api-clients/bruno/collection.bru`.
  - `sp-laravel-api:export-postman` writes a Postman v2.1 collection to `api-clients/postman/collection.json`.
  - Both commands support `--output=<path>`, `--regen=<list|all>` (case-insensitive against OpenAPI tags), and `--dry-run`.
  - Both commands are diff-aware: existing requests are skipped unless listed in `--regen`; new requests are added.
  - See `docs/guide/modules/module-api-clients.md` for full usage.
