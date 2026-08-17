---
title: "Coding Standards"
description: "Coding standards, PSR-12 conventions, package guidelines, and tooling quirks."
keywords:
  - coding standards
  - psr-12
  - conventions
  - testing
  - tooling
---

# Coding Standards (Shared)

## General
- Keep changes minimal and consistent with existing style
- Prefer small functions and clear naming
- Avoid breaking public APIs without discussion
- Never add secrets
- Follow PSR-12 coding standards

## Project Conventions
- **Naming**: descriptive names, camelCase for variables/methods, PascalCase for classes
- **Folder conventions**: follow Laravel package structure patterns
- **Error handling**: Laravel exceptions and proper HTTP status codes
- **Logging**: Laravel's logging facade with appropriate context
- **Testing**: all new features include tests; use PHPUnit

## Key Conventions (package-specific)
- Use named arguments for `RecordTableType` and all relationship type constructors
- Auth flags: `isAuthRead` / `isAuthWrite` (primary); `public` is derived/deprecated
- Availability flags: `canRead`, `canCreate`, `canUpdate`, `canDelete`, `canUpsert`
- Backward compatibility must be maintained unless explicitly asked to break
- Add tests for every core behaviour change (tenant, auth, filters, permission, triggers, response wrapper)

## Testing
- PHPUnit with Orchestra Testbench (`tests/Unit/`, `tests/Feature/`)
- SQLite `:memory:` (see `phpunit.xml`), random execution order
- Coverage excluded: `src/Console`, `src/CoreServiceProvider.php`
- Cache dir: `.phpunit.cache/`

## Database Compatibility
- Support MySQL, PostgreSQL, and SQLite
- Use database-agnostic SQL when possible
- Avoid database-specific functions unless wrapped in compatibility layers
- Test across all supported databases

## API Design
- Use consistent JSON response format
- Follow RESTful principles
- Include proper error handling and validation
- Support field selection and filtering

## Documentation

### Where to edit
- **Do NOT edit** files in `sp-laravel-api-docs/` (auto-generated; synced by `sync_docsify_docs.sh`)
- Only update docs in `docs/` (VitePress + Mermaid + OpenAPI format)
- For writing/updating docs, follow `docs/docs-authoring-guide.md`

### docs/guide — the AI-facing chunked reference
`docs/guide/*` is the token-efficient, chunked reference split into `api/`, `features/`, `modules/`, `records/`. Agents should read it *first* for module/feature context.

**When you MUST update `docs/guide/*` (mandatory):**
- You change core behaviour (CRUD, auth, tenancy, hooks, triggers, response envelope, OpenAPI export)
- You add or change a module (e.g. `module-mcp`, `module-webhooks`, `module-pagination`) or a feature (attachments, audit, permission)
- You add or change config keys that control behaviour

**Why:** `docs/guide/*` is the single retrieval layer that lets an agent load one small page and answer accurately instead of re-reading large source files. If source and docs drift, agents act on stale context and make wrong changes. Keep source and guide in lock-step.

### Context-loading strategy (save tokens)
1. Read the relevant `docs/guide/*` page first for high-level module/feature context
2. Open full source (`src/`) only when you need implementation detail — exact signatures, edge cases, or when the guide looks stale

## Tooling Quirks
- Rector does the actual formatting (imports, dead code, type declarations, early returns). php-cs-fixer only runs `@auto` rules.
- PHPStan is strict (`larastan/larastan` v3) — use `--memory-limit=1G` for large runs
- `config:cache` must handle `RecordTableType::__set_state()` (implemented — handles legacy `can_write`/`can_read` keys)
