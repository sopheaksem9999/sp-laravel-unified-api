---
title: "Laravel Boost Integration for Agentic Development"
description: "Makes sp-laravel-api a first-class Laravel Boost citizen: AI guidelines, an on-demand agent skill, stdio MCP registration, and a one-command installer so AI agents develop and debug following sp-laravel-api patterns."
keywords:
  - laravel boost
  - agentic development
  - MCP
  - agent skill
  - AI guidelines
  - boost:install
date: 2026-08-10
status: approved
---

# Laravel Boost Integration for Agentic Development

## Problem

Clients build Laravel applications on top of `sp-laravel-api` (config-driven
dynamic CRUD, tenant-aware records, standardized responses). When they use
Laravel Boost (`laravel/boost`, the official MCP server + AI guidelines +
agent skills + docs API for AI-assisted development) in the same project,
agents get deep context about *Laravel* but nothing about *sp-laravel-api*.
An agent asked to "add a customers table" or "debug this 422" has no idea
that:

- tables are config objects (`RecordTableType`) in
  `config/records/tables/*.php`, not Eloquent models + controllers;
- auth is declared via `isAuthRead` / `isAuthWrite` flags, availability via
  `canRead` / `canCreate` / `canUpdate` / `canDelete` / `canUpsert`;
- tenant resolution follows an ordered precedence (request attr →
  `record_context.tenant_id` → `X-Tenant-ID` header);
- every response goes through the `RecordApiResponseService` wrapper contract.

The result: agents generate wrong-shaped code, guess at package APIs, and
debug by trial and error instead of following the package's own validation
and export tooling. Boost has an official third-party package contract
(`resources/boost/guidelines/*`, `resources/boost/skills/{name}/SKILL.md`,
auto-discovered by `boost:install`) that this package currently ignores.

This spec makes sp-laravel-api a first-class Boost citizen so agents
**develop and debug following sp-laravel-api patterns**.

## Decisions

- **Target: Laravel Boost only.** Not the Boot hosting platform. Boost is
  the agentic-development surface (MCP server, guidelines, skills) — this is
  where "agents develop and debug" happens. Boot platform compatibility is
  out of scope.
- **Follow Boost's third-party contract exactly.** Guidelines live at
  `resources/boost/guidelines/core.blade.php`, the skill at
  `resources/boost/skills/sp-laravel-api-development/SKILL.md`. `boost:install`
  and `boost:update --discover` auto-discover these from installed packages —
  no boot code is needed for either.
- **Guidelines: `core` only, no version split.** The package has a single
  active convention stream (0.4.x); version-aware guidelines (`10.x`, `11.x`…)
  buy nothing here.
- **MCP: reuse the existing stdio server.** `sp-laravel-api:mcp`
  (`src/Console/McpServerCommand.php`) already implements a JSON-RPC
  line-delimited stdio loop over `McpServerService`. No new transport code.
- **stdio is local-trust.** The stdio server exposes full tools
  (`schemaOnly: false` — including per-table CRUD tools) because it runs on
  the developer's machine, the same trust model Boost's own `boost:mcp`
  (tinker, database query) uses. The HTTP MCP endpoints stay token-gated and
  unchanged.
- **Installer merges, never clobbers.** `boost:install` regenerates
  `.mcp.json` from scratch. `sp-laravel-api:boost` therefore does a
  read-modify-write merge that adds only the `sp-laravel-api` server entry
  and preserves every other server (Boost's `laravel-boost` entry included).
  Idempotent; safe to re-run after any `boost:install`.
- **Backward compatible, additive only.** One new artisan command
  (`sp-laravel-api:boost`), plus `McpServerCommand` becoming always
  registered instead of gated on `record.mcp.enabled`. Nothing else changes.

## Components

### 1. AI Guidelines — `resources/boost/guidelines/core.blade.php`

Blade template (Boost convention) with `@verbatim` + `<code-snippet>`
blocks. Content, kept concise per Boost's guidance:

- **What the package is**: config-driven dynamic CRUD; no per-table
  controllers/models; standardized responses; tenant-aware records.
- **File structure**: `config/records/tables/*.php` (`RecordTableType`
  instances, one per table), `config/sp-record.php` (global behavior),
  `config/records/global-functions/*.php` (RPC-style global functions).
- **Conventions the agent must follow**:
  - named arguments for every `RecordTableType` constructor and all
    relationship type constructors;
  - auth flags `isAuthRead` / `isAuthWrite` (primary); `public` derived and
    deprecated;
  - availability flags `canRead` / `canCreate` / `canUpdate` / `canDelete` /
    `canUpsert`;
  - tenant resolution precedence (request attr `resolved_tenant_id` →
    `record_context.tenant_id` → `X-Tenant-ID` header);
  - permission flow: table auth check → user resolution → per-table
    permission map → custom authorizer → `Gate::forUser()->allows()`;
  - every response uses the `RecordApiResponseService` wrapper contract.
- **Golden commands**:
  - `php artisan sp-laravel-api:record {name}` — scaffold a table config;
  - `php artisan sp-laravel-api:validate` — validate current config;
  - `php artisan sp-laravel-api:export-openapi` — OpenAPI spec;
  - `php artisan sp-laravel-api:export-bruno` / `sp-laravel-api:export-postman`
    — API client collections.
- **MCP pointer**: use `sp_api_list_endpoints`, `sp_api_get_endpoint`,
  `sp_api_list_permissions` for live schema/permission lookups instead of
  guessing.

### 2. Agent Skill — `resources/boost/skills/sp-laravel-api-development/SKILL.md`

Agent Skills format (YAML frontmatter with `name` and `description`, plus
Markdown instructions). The skill is activated on demand — "when working
with sp-laravel-api features". Workflows:

- **Develop — scaffold a table**: run `sp-laravel-api:record {name}`, then
  fill in the generated config: columns, auth flags, availability flags,
  relationships (named-argument constructors, supported types), tenant
  settings. Reference snippet: a minimal `RecordTableType` config.
- **Develop — add a relationship**: where it lives in the table config,
  which relationship types exist, named-argument constructor shape, `select`
  parameter implications.
- **Debug — failing record request**: the ordered loop
  1. reproduce the exact request (method, path, query, tenant headers);
  2. run `sp-laravel-api:validate` and fix reported config issues;
  3. confirm the table is registered (schema registry cache;
     `sp-laravel-api:cache-status` if present);
  4. inspect the endpoint's live schema via `sp_api_get_endpoint`;
  5. replay via the exported Bruno/Postman collection
     (`sp-laravel-api:export-bruno` / `:export-postman`);
  6. check the response against the `RecordApiResponseService` contract;
  7. if fixed, run the quality gates below before claiming done.
- **Debug — auth/tenant 403s**: walk the permission flow order, check
  `sp_api_list_permissions`, verify tenant resolution precedence.
- **Quality gates before done**: `composer format-check` →
  `composer analyse` → `composer test` (the package's own `composer quality`
  ordering), with tests added for any behavior change (tenant, auth,
  filters, permission, triggers, response wrapper).

### 3. MCP stdio registration (reuse `sp-laravel-api:mcp`)

- Provider change in `src/CoreSpLaravelApiProvider.php`: register
  `McpServerCommand` unconditionally alongside the other console commands;
  remove the `if (config('record.mcp.enabled', false))` gate
  (lines 98-102). HTTP MCP routes and `record.mcp.*` gating are unchanged.
- Agents register the server per their tool's syntax, e.g.:
  `claude mcp add -s local -t stdio sp-laravel-api -- php artisan sp-laravel-api:mcp`
  (mirrors how Boost itself is registered).
- `.mcp.json` entry shape written by the installer:
  `{ "mcpServers": { "sp-laravel-api": { "command": "php", "args": ["artisan", "sp-laravel-api:mcp"] } } }`.

### 4. One-command installer — `sp-laravel-api:boost`

New `src/Console/BoostInstallCommand.php`:

1. **`.mcp.json` merge** (project root): read if present, decode JSON, add
   the `sp-laravel-api` entry under `mcpServers`, preserve all existing
   entries, write back with stable formatting. Create the file (with
   `mcpServers` object) when missing. Idempotent: if the entry already
   matches, leave the file untouched and say so. Fails gracefully with an
   actionable message on unparseable JSON (no destructive rewrite).
2. **Validate**: run `sp-laravel-api:validate` via `$this->call(...)` so its
   output is visible in the install run, and summarize its outcome (pass /
   warnings / errors) in the command's final report.
3. **Next steps output**: tell the user to run `boost:install` (or
   `boost:update --discover`) first so the guidelines + skill install, and
   to re-run `sp-laravel-api:boost` afterwards because `boost:install`
   regenerates `.mcp.json`.

Registered in the provider's console command list.

## Testing

- `tests/Feature/BoostInstallCommandTest.php`:
  - merges `sp-laravel-api` entry into an existing `.mcp.json` and preserves
    the `laravel-boost` entry and unrelated servers;
  - creates `.mcp.json` when missing;
  - idempotent (no change on re-run with identical content);
  - refuses to clobber unparseable `.mcp.json`;
  - runs `sp-laravel-api:validate` and reports result.
- `tests/Unit/BoostAssetsTest.php`:
  - `resources/boost/guidelines/core.blade.php` exists and contains required
    sections (`RecordTableType`, commands);
  - `resources/boost/skills/sp-laravel-api-development/SKILL.md` exists,
    parses YAML frontmatter with required `name` and `description`, and
    contains the debug workflow.
- Regression: `McpServerCommand` registration change covered by existing
  console/provider tests.

## Documentation

- New guide page in `package/docs/` (e.g.
  `guide/modules/module-boost-agentic-development.md`) following
  `docs/docs-authoring-guide.md`: what Boost is, install order
  (`composer require laravel/boost --dev` → `boost:install` →
  `sp-laravel-api:boost`), the three integration surfaces, and the debug
  workflow.
- `sp-laravel-api-docs/` stays untouched (auto-generated).

## Out of scope

- Boot platform (hosting) compatibility.
- Changes to the HTTP MCP endpoints, tokens, or their auth model.
- New MCP tools (the existing schema + data toolset is reused as-is).
- Version-split guidelines or multiple skills.
