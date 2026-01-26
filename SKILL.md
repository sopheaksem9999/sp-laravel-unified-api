# Skills: `sopheak/sp-laravel-api`

This package provides a set of capabilities that can be combined to build robust, config-driven APIs on top of Laravel.

## Dynamic Record API

- Define tables via `RecordTableType` in `config/records/tables/*.php` (or resource classes).
- Automatic CRUD endpoints (list, show, create, update, delete, bulk operations).
- Column-level control: visibility, write protection, indexes, tenant-aware behavior.
- Configurable validation hooks (`createValidator`, `updateValidator`, `deleteValidator`).

## Advanced Query & Filtering

- Request-driven filters via query string (e.g. `status=eq.ACTIVE`, `amount=gt.100`).
- Relationship-aware filtering and selection using `select` syntax.
- `applyRequestFilters` macro on `Illuminate\Database\Query\Builder` for reusable query logic.
- Cursor and page-based pagination, with caching support via `QueryCacheService`.

## Relationship Resolution

- Declarative relationships (`RecordHasManyType`, `RecordBelongsToType`, etc.).
- Nested loading and mapping handled by `RelationshipResolver` / `RelationshipResolverUtils`.
- Support for deep relationships and aliasing in `select` queries.

## Audit Logging

- Centralized audit handling via `AuditLogService`.
- Event types captured by `AuditLogEventEnum` (create, update, delete, auth events, etc.).
- Configurable per-table audit behavior (including `customAuditLog` callbacks).
- Query helpers to fetch audit stats, timelines, and per-field history.

## Error Handling & Response Shaping

- Standardized JSON envelope via `RecordApiResponseService`:
  - `success`, `errorCode`, `data`, `meta.request_id`.
  - Consistent error mapping using `HttpErrorCodeConstant`.
- Helper methods for common HTTP outcomes: success, created, not found, validation error, server error.

## OpenAPI Generation

- `OpenApiService` generates an OpenAPI 3 specification that matches runtime behavior.
- Documents:
  - Dynamic CRUD endpoints for all configured tables.
  - Filters, sorting, selection, and tenant headers.
  - Custom functions (RPC-style endpoints).
  - Standard response shapes including `errorCode`.

## Console Tooling

- `sp-laravel-api:sync-record-columns`
  - Scans DB schema and syncs `columns` definitions into `RecordTableType` configs.
  - Supports `--force` to regenerate columns even if they exist.
  - Supports `--table=<name>` to sync a single table.

- Additional commands (from the provider):
  - Generate OpenAPI spec.
  - Generate record schema cache.
  - Create new `RecordTableType` stubs.

These skills are designed to be **composable**: configuration changes in `Types/` and `config/` flow through controllers, services, OpenAPI docs, and console tooling without manual duplication.
