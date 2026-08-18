---
title: "Backend Setup Prompt"
description: "Copy-paste prompt that initializes the local agentic setup of an existing Laravel backend using the sp-laravel-api package."
keywords:
  - backend setup
  - bootstrap prompt
  - laravel
  - recordtabletype
  - config driven crud
  - triggers
  - validators
  - permissions
---

# Backend Setup Prompt

```markdown
You are initializing a LOCAL agentic setup in an EXISTING Laravel backend that
uses the `sopheak/sp-laravel-api` package. The project already has its own
structure, rules, and conventions — your job is NOT to rebuild them. You ADD the
missing sp-laravel-api-specific knowledge so future agents implement features
through the package correctly (config-driven CRUD, triggers, validators,
permission maps, RPC functions) instead of writing custom controllers/routes or
reverse-engineering the package source.

Project name: {project-name}
API prefix: {api-prefix}   (e.g. api/v1)
RPC prefix: {rpc-prefix}   (e.g. rpc)
Package path: vendor/sopheak/sp-laravel-api

Follow the steps below in order.

## 1. Learn the package first (read the docs the smart way)

The package is config-driven — every CRUD behavior is declared in a
`RecordTableType` config, not written in controllers. Read ONLY what you need, in
this order:

1. Mental model + architecture:
   - `vendor/sopheak/sp-laravel-api/AGENTS.md` (entrypoint + core rules)
   - `vendor/sopheak/sp-laravel-api/docs/getting-started/mental-model.md`
   - `vendor/sopheak/sp-laravel-api/docs/getting-started/architecture.md`
2. The chunked AI reference under `vendor/sopheak/sp-laravel-api/docs/guide/*` —
   read the ONE page relevant to the feature you are building:
   - `features/feature-permission.md` — permission maps + `viewOwn` scoping
   - `features/feature-record-trigger-functions.md` — triggers + validators
   - `api/api-type-reference-and-examples.md` — `RecordTableType` + relationship
     type reference
   - `api/api-config-and-middleware.md` — table config + middleware
   - `api/api-validation.md` — table validators + default validation
   - `records/record-hooks.md` — lifecycle triggers (canonical)
   - `api/api-crud-operations.md` — the endpoint surface the config produces
3. `vendor/sopheak/sp-laravel-api/docs/core-concepts/relationships.md` —
   relationship types + write payload rules.

Rule: prefer the vendor package docs and your project's `.agents/context/` over
reading package `src/` directly — open `src/` only when the docs are stale or you
need an exact signature. The package GitHub repo is private — never fetch it.

## 2. Internalize the non-negotiable package rules

- **Config over code**: do NOT create custom CRUD controllers or routes. Standard
  endpoints come from `RecordTableType` configs. Do NOT add custom RPC endpoints
  just to list/filter records — use `GET /{api-prefix}/{table}` with filters,
  pagination, and relationship loading.
- **Table configs** live in `config/records/tables/{table}.php` (one
  `RecordTableType` per file, or an array of them). Global functions in
  `config/records/global-functions/{domain}.php`. Table-level functions on
  `RecordTableType::$functions`.
- **No inline closures in config files** — `config:cache` cannot serialize
  closures. Use class array callables (`[UserValidators::class, 'createValidator']`)
  or attributes (`#[RecordTrigger]`, `#[RecordValidator]`, `#[RecordFunction]`)
  — attributes are a legacy path for old ORM-based projects; prefer record
  config.
- **Domain modules** live in `app/Record/{Domain}/`: `{Domain}Triggers.php`,
  `{Domain}Validators.php`, `{Domain}Functions.php`, `{Domain}Service.php`.
- **Response envelope** is `{ success, error_code, data, meta }` — never hand-roll
  a different shape.
- **Filters** are top-level query params `{column}={operator}.{value}` (e.g.
  `status=eq.open`, `created_at=gte.2026-08-01`). Do NOT wrap them in a
  `filter[...]` key or bracket syntax; that shape is rejected or silently ignored.
- **Writes**: unknown payload keys (typo / invented field / undeclared relation)
  return `422`, not silently dropped. Nested relationship writes work only for the
  alias-array types (`hasMany`, `belongsToMany`, `hasManyThrough`, `morphMany`,
  `morphToMany`, `morphByMany`, `spatiePermission`); `belongsTo`/`hasOne`/… set the
  root FK/discriminator field instead. `allowCreate`/`allowUpdate`/`allowDelete`
  gate nested writes and reject (not skip) disallowed items.
- **Permissions**: `permissions: ['read' => ['view:X', 'viewOwn:X']]` on
  `RecordTableType` auto-registers the names; grants live in the app seeder.
  `viewOwn:*` auto-scopes to own rows and MUST NOT be granted to `super_admin`.
- **Auth**: `isAuthRead` / `isAuthWrite` flags; the app's default guard must be
  the API/JWT guard for auth and own-record scoping to apply.
- **Audit columns**: the package auto-fills `created_by_id` (create) /
  `last_updated_by_id` (update) when declared in `columns`; write-disable them.
  Reserve `created_by` / `last_updated_by` as the `belongsTo` relationship alias
  keys, not raw audit columns.
- **Caching**: request caching with namespace-versioned invalidation (no Redis
  KEYS / DB LIKE / cache tags). Cache is opt-in: default `disableCache: true`,
  enable per table/function with `disableCache: false`.
- **Triggers/validators run AFTER authorization** — permission-scoped rows are
  already filtered when a `beforeRead` trigger runs.

## 3. The table workflow (migration → config → sync → configure → validate)

When adding or changing a table, follow this order and never skip validation:

1. Create + run the migration: `php artisan make:migration create_{table}_table`
   then `php artisan migrate`. NEVER run `migrate:fresh`, `migrate:refresh`,
   `migrate:fresh --seed`, or any DROP/TRUNCATE — they wipe the shared database.
   Ask for explicit approval if a destructive reset is genuinely needed.
2. Generate the config: `php artisan sp-laravel-api:make-record-table {table}`
   (alias `sp-laravel-api:record {table}`; `sp-laravel-api:generate-all-configs`
   for all tables at once).
3. Sync columns from the DB: `php artisan sp-laravel-api:sync-record-columns`.
   Re-check afterwards — it rewrites config files and can drop hand-written
   `permissions` / `triggers` / `relationships` / `columnWriteDisabled` args.
4. Configure permissions, relationships, casting, audit flags, trigger hooks, and
   validators in the config (class callables / attributes, never closures).
5. Validate: `php artisan sp-laravel-api:validate`, and
   `php artisan sp-laravel-api:list-tables` to confirm registration.
6. Config cache: after config changes run `php artisan config:clear`. Do NOT run
   `config:cache` in local/dev — it ignores phpunit env overrides (e.g. sqlite
   `:memory:`) and makes `php artisan test` hit the real database. Cache only for
   production deployments.

## 4. Extend the existing agent context (do NOT rebuild it)

The project already has a root `AGENTS.md`/`CLAUDE.md`, `.agents/rules/`
(architecture, coding-standards, metadata), and `.agents/context/` (e.g.
`sp-laravel-api.md`, auth context). Those are already correct — do NOT recreate or
rewrite them.

1. Read the current folder pattern first: open the root `AGENTS.md`/`CLAUDE.md`
   and every file under `.agents/rules/` and `.agents/context/` (especially the
   sp-laravel-api context) to learn the project's conventions and any distilled
   known-behaviors / bug reports. Reuse them as-is.
2. ADD only the missing sp-laravel-api-specific knowledge, matching existing
   naming and style (e.g. a `config/records/` README note, a domain-module
   convention doc, or a bug report in the project's bug-report folder).
3. Do NOT duplicate what is already documented — extend in place.

## 5. Verify

- Run `vendor/bin/pint --dirty --format agent` after modifying PHP files.
- Run `composer rector` (inspect) / `composer rector:fix` (apply) for code quality.
- Run `php artisan test --compact` (Pest) — write or update a test for every
  behavior change.

## Deliverable

Return: (1) the files you ADDED or UPDATED (a diff-style summary, not a full
rebuild), (2) the sp-laravel-api conventions you encoded into the project's
`.agents/` docs, and (3) the tables/endpoints you validated via
`sp-laravel-api:validate` / the schema MCP.
```
