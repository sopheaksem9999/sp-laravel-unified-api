# Project Overview
- **Package:** `sopheak/sp-laravel-api`
- **Namespace:** `Sopheak\Core`
- **Purpose:** Unified dynamic CRUD API package with standardized responses, filter/query engine, relationship loading, tenancy, middleware mapping, permissions, and audit logging.
- **Main runtime entry:** `Sopheak\Core\CoreServiceProvider`

# Stack
- **Language:** PHP 8+
- **Framework:** Laravel
- **Architecture style:** Config-driven dynamic API (`config/record.php` + `RecordTableType`)
- **Core areas:** `src/Services`, `src/Types`, `src/Utilities`, `src/Http`, `src/Traits`, `src/Console`
- **Testing:** PHPUnit (`tests/Unit`, `tests/Feature`)

# Key Commands
- `composer analyse -- --memory-limit=1G`
- `composer test`
- `vendor/bin/phpunit`
- `php artisan sp-laravel-api:record {name}`
- `php artisan sp-laravel-api:generate-record-tables-from-db`
- `php artisan sp-laravel-api:sync-record-columns --force`
- `php artisan sp-laravel-api:validate`

# Architecture Notes
- `RecordService` is the main CRUD orchestration layer (tenant filter, triggers, validators, cache invalidation, relationship include/write).
- `RecordApiResponseService` is the single response contract (`success`, `error_code`, `meta.request_id`).
- **NO ROUTES FOR CRUD:** Never write `Route::get(...)` in `routes/api.php` for standard API endpoints.
- **NO CONTROLLERS FOR CRUD:** Never write standard CRUD controllers.
- **NO RPC FOR STANDARD READS:** Never create custom RPC endpoints (like `getLogs`) just to list or filter records. The package automatically provides full-featured listing, filtering, and pagination via `GET /api/v1/{table}`. Only use custom functions for complex aggregations (like stats or timelines) or actions that the standard CRUD cannot handle.
- **CONFIG-DRIVEN ENDPOINTS:** All built-in API routes (both table CRUD and custom table functions) MUST be configured on the record table definition using `RecordTableType` (e.g., in `config/record.php` or `config/records/tables/`). Use the `functions` property for table-specific RPC endpoints, and `global_functions` for global endpoints.
- `RecordTableType` controls endpoint behavior:
  - **Auth flags:** `isAuthRead`, `isAuthWrite` (primary)
  - **Legacy compatibility:** `public` is still supported/derived
  - **Availability flags:** `canRead`, `canCreate`, `canUpdate`, `canDelete`, `canUpsert`
  - **Tenant support:** `hasTenantId`
- Permission flow lives in `HasControllerHelpers::authorizeAction()`:
  - table public/auth check
  - auth guard user resolution
  - permission mapping
  - optional custom authorizer via `record.authorization`
  - fallback to `Gate::forUser(...)->allows(...)`
- Tenant resolution priority is built-in:
  - `request->attributes['resolved_tenant_id']`
  - `request->attributes['record_context']['tenant_id']`
  - tenant header (`record.tenant_header`)

# Conventions
- Always keep backward compatibility for clients unless explicitly asked to break.
- Prefer named arguments when instantiating `Type` objects (`RecordTableType`, relationship types).
- Keep config-driven behavior centralized in `RecordConfigService`, `RecordTableType`, and docs.
- Add/update tests for every behavior change in core flow (tenant, auth, filters, permission, triggers, response wrapper).
- Update docs when behavior changes (`README.md`, `docs/api-documentation.md`).
- **IMPORTANT DOCS RULE:** ONLY update documentation files inside the `sp-laravel-api/docs` directory. DO NOT edit files in the `sp-laravel-api-docs` directory, as they are auto-generated via a bash script from the main package.
- **IMPORTANT SCOPE RULE:** Do not edit files outside the `/Users/sopheak/Documents/Sopheak-dev/QBO Finance/Package/sp-laravel-api` directory when working on this package.
- Keep strict typing and PSR-12 style.
- Do **not** run `composer format-check`.
- Do **not** run code format/lint/test just for editing this large AI context rule file under `.trae/rules/`; treat it as documentation context content.
