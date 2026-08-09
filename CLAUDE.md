# Project Overview

- **Package:** `sopheak/sp-laravel-api` — namespace `Sopheak\Core` (PSR-4: `src/`)
- **Entrypoint:** `Sopheak\Core\CoreSpLaravelApiProvider` in `src/CoreSpLaravelApiProvider.php` (registered via `composer.json extra.laravel.providers`)
- **Architecture:** Config-driven dynamic CRUD. Table schemas are `RecordTableType` objects in `config/records/tables/*.php`; behaviour controlled by `config/record.php`
- **Key classes:** `RecordService` (CRUD orchestration), `RecordApiResponseService` (standardized response contract), `HasControllerHelpers` (auth/tenant/validation wiring), `SchemaRegistryUtils` (cached table registry)
- **DB support:** MySQL, PostgreSQL, SQLite — avoid DB-specific SQL

# Commands

```bash
composer test                    # vendor/bin/phpunit
composer analyse                 # phpstan src tests
composer format                  # rector:fix
composer format-check            # rector:check (dry-run)
composer quality                 # format-check -> analyse -> test (order matters)
vendor/bin/php-cs-fixer fix --dry-run --diff   # lint (used by /lint shortcut)
```

Artisan commands (in `src/Console/`, 15 total):
- `php artisan sp-laravel-api:setup` — publish configs + migrations
- `php artisan sp-laravel-api:record {name}` — scaffold a table config
- `php artisan sp-laravel-api:validate` — validate current config
- `php artisan sp-laravel-api:export-openapi` — generate OpenAPI spec
- `php artisan sp-laravel-api:export-bruno` / `sp-laravel-api:export-postman` — API client collections

# Testing

- PHPUnit with Orchestra Testbench (`tests/Unit/`, `tests/Feature/`)
- SQLite `:memory:` (see `phpunit.xml`), random execution order
- Coverage excluded: `src/Console`, `src/CoreServiceProvider.php`
- Cache dir: `.phpunit.cache/`

# Key Conventions

- Use named arguments for `RecordTableType` and all relationship type constructors
- Auth flags: `isAuthRead` / `isAuthWrite` (primary); `public` is derived/deprecated
- Availability flags: `canRead`, `canCreate`, `canUpdate`, `canDelete`, `canUpsert`
- Permission flow in `HasControllerHelpers::authorizeAction()`: table auth check → user resolution → per-table permission map → custom authorizer → `Gate::forUser()->allows()`
- Tenant resolution: request attr `resolved_tenant_id` → `record_context.tenant_id` → `X-Tenant-ID` header
- Backward compatibility must be maintained unless explicitly asked to break
- Add tests for every core behaviour change (tenant, auth, filters, permission, triggers, response wrapper)

# Documentation

- **Do NOT edit** files in `sp-laravel-api-docs/` (they are auto-generated)
- Only update docs in `package/docs/` (VitePress + Mermaid + OpenAPI format)
- For writing/updating docs, follow `docs/docs-authoring-guide.md`

# Tooling Quirks

- Rector does the actual formatting (imports, dead code, type declarations, early returns). php-cs-fixer only runs `@auto` rules.
- PHPStan is strict (`larastan/larastan` v3) — use `--memory-limit=1G` for large runs
- `config:cache` must handle `RecordTableType::__set_state()` (implemented — handles legacy `can_write`/`can_read` keys)
