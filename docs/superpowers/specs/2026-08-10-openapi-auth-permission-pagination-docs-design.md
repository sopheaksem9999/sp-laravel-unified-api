---
title: "Document Config-Derived Auth, Permission, and Pagination in OpenAPI Output"
description: "Adds human-readable Authorization lines and a machine-readable x-sp-auth extension to every OpenAPI operation, plus configured pagination behavior docs to the main document and list endpoints — so users and AI agents know the permission and pagination contract straight from config."
keywords:
  - openapi
  - authorization
  - permissions
  - x-sp-auth
  - pagination
  - middleware_map
date: 2026-08-10
status: approved
---

# Document Config-Derived Auth, Permission, and Pagination in OpenAPI Output

## Problem

The OpenAPI spec the package generates marks each operation with
`security: [['bearerAuth' => []]]` or `security: []`, but it never explains
*what* the requirement is or *where* it comes from. A user or AI agent
reading the spec cannot tell:

- whether an endpoint needs auth because of `isAuthRead` / `isAuthWrite`
  (table config) or `isPublic` (function config);
- which permission scope(s) they must hold (table `permissions` map /
  function `pmsName`-derived scopes);
- which extra route middleware stack applies (`middleware_map` /
  `RecordFunctionType::$middleware`);
- whether the table is tenant-scoped (`hasTenantId`);
- which pagination mode the API defaults to (`record.pagination.default_mode`
  is `offset` or `cursor`, plus cursor defaults) — the main document mentions
  `default_mode` only in passing, and list endpoints document the parameters
  but never the configured default.

## Decisions

- **Every operation gets an `**Authorization:**` description line and a
  machine-readable `x-sp-auth` extension.** Description lines for humans in
  any viewer; the extension for agents and tooling. No emojis.
- **The `security` arrays stay exactly as they are.** They are the OpenAPI
  auth contract; this feature adds documentation only.
- **Permission scopes come from the table's `permissions[action]` map**
  (the same source `HasControllerHelpers::authorizeAction()` uses). When
  absent, the key is omitted — never a guessed scope.
- **Middleware is resolved with the same precedence as
  `RecordRouteMiddleware`**: function-level `middleware` (when set on a
  `RecordFunctionType`) replaces the map entirely; otherwise
  `middleware_map.default.*` + `middleware_map.tables.{table}.*` merged
  across `*` / group (`read`|`write`|`function`) / exact action.
- **Pagination docs go in both places**: the main document's Pagination
  section is enriched with the configured values, and every list GET
  operation gets a one-line note stating the effective default mode and
  default cursor column, with the config key named.
- **Backward compatible, additive only.** Existing operation fields are
  untouched except appending to `description`.

## Changes — `src/Services/OpenApiService.php`

### 1. New private helpers

- `authDocs(bool $requiresAuth, string $mode, string $flag, bool $flagValue,
  bool $public, string $source, array $permissions, array $middleware,
  bool $tenant): array` — builds the `**Authorization:**` description
  line(s) and the `x-sp-auth` value:

  ```json
  {
    "auth": "bearer" | "public",
    "mode": "read" | "write" | "function",
    "flag": "isAuthRead",
    "flag_value": true,
    "public": false,
    "permissions": ["invoice:create"],
    "middleware": ["auth:sanctum", "subscribed"],
    "tenant": true,
    "source": "config/records/tables/invoices.php"
  }
  ```

  Description format (only the lines that exist):

  ```
  **Authorization:** Bearer token required — write auth (`isAuthWrite=true` in config/records/tables/invoices.php)
  **Permission scope(s):** `invoice:create`
  **Route middleware:** `auth:sanctum`, `subscribed`
  ```

  Public form: `**Authorization:** Public — no authentication required
  (`isAuthRead=false` in config/records/tables/customers.php)`.

- `appendAuthDocs(array $operation, ...): array` — appends the lines to
  `description` (with `\n\n` separator) and sets `x-sp-auth`.
- `permissionScopesForAction(RecordTableType $config, string $action):
  array` — normalizes `$config->permissions[$action]` to a string list.
- `middlewareForAction(string $table, string $action,
  ?RecordFunctionType $function): array` — replicates the
  `RecordRouteMiddleware` precedence using `RecordConfigService::middlewareMap()`.

### 2. Applied per operation type

| Operation | Mode | Flag | Source |
| --- | --- | --- | --- |
| Table list / show | `read` | `isAuthRead` | `config/records/tables/{name}.php` |
| Table create / update / delete / upsert | `write` | `isAuthWrite` | same |
| Global functions | `function` | `isPublic` (default `true`) | `config/records/global-functions/{name}.php` |
| Table RPC functions | `function` | `isPublic` (default `false`) | table config file |

Public status for table operations derives from the existing
`RecordTableType::derivePublicFromAuthFlags()` result (`$config->public`).

### 3. Pagination docs

- **Main document** (`generateInternal()` description section): the
  Pagination subsection gains a "Configured defaults" block naming
  `record.pagination.default_mode` (value `offset`/`cursor`),
  `record.pagination.cursor.default_column`, `cursor.composite_enabled`, and
  `record.pagination.skip_total_default`.
- **List GET operations**: append `**Pagination:** Default mode: \`{mode}\`
  (config \`record.pagination.default_mode\`); cursor pagination via
  \`cursor\` parameter (default cursor column \`{column}\`).`

## Testing — `tests/Feature/OpenApiTest.php`

- public table → list/create ops: description contains "Public",
  `x-sp-auth.auth='public'`, `flag_value=false`, source path present.
- authed table → bearer, `mode=read`/`write`, `permissions` from
  `permissions[action]`, `middleware` from `middleware_map` default + table
  merge.
- function with `middleware` override vs function without (falls through to
  map); global function default public, table RPC default bearer;
  `isPublic=true` flips to public.
- pagination: with `default_mode=cursor` the main doc and the list GET
  description state `cursor`; offset case states `offset`.
- regression: `security` arrays unchanged.

## Documentation

- Update the OpenAPI guide page in `package/docs/` (per
  `docs/docs-authoring-guide.md`): the `x-sp-auth` contract, the
  Authorization description format, and the pagination config docs.
- `sp-laravel-api-docs/` untouched (auto-generated).

## Out of scope

- Changing the `security` arrays or any auth behavior at runtime.
- Permission scope derivation beyond what config already defines
  (`permissions[action]`; function `pmsName` scopes when permissions
  enabled).
- Per-table pagination overrides (none exist in config).
