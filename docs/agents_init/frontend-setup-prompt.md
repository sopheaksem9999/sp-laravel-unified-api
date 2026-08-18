---
title: "Frontend Setup Prompt"
description: "Copy-paste prompt that initializes the local agentic setup of an existing frontend/mobile client project consuming sp-laravel-api."
keywords:
  - frontend setup
  - bootstrap prompt
  - onboarding
  - crud api
  - schema mcp
  - query syntax
---

# Frontend Setup Prompt

```markdown
You are initializing a LOCAL agentic setup in an EXISTING client project
(frontend, mobile, or any HTTP consumer) that talks to a backend built on
`sopheak/sp-laravel-api`. The project already has its own structure, rules, and
conventions — your job is NOT to rebuild them. You ADD the missing
sp-laravel-api-specific knowledge so future agents integrate with the API
correctly, without repeating the classic mistakes (bracket filters, `select=*`,
invented relations, debugging the backend from the frontend).

Project name: {project-name}
API base URL: {api-host}            (e.g. http://{app}.test/api/v1)
Schema MCP URL: {schema-mcp-url}    (e.g. http://{app}.test/api/v1/mcp/schema)
Auth: Bearer token, tenancy via X-Tenant-ID header where applicable.

Follow the steps below in order.

## 1. Learn the package first (read the docs the smart way)

The package ships a token-efficient, chunked reference. Read ONLY what you need,
in this order:

1. Mental model + architecture:
   - `docs/getting-started/mental-model.md` (config-driven CRUD: no per-table
     controllers/models, one `RecordService` orchestrates everything)
   - `docs/getting-started/architecture.md`
2. The chunked API reference under `docs/guide/*` — read the ONE page relevant to
   the feature you are building, not the whole tree:
   - `docs/guide/api/api-crud-operations.md` — list/get/create/update/delete/
     upsert/restore/force-delete, all query params, and the filter operators
   - `docs/guide/modules/module-pagination.md` — offset + cursor pagination +
     total-count control
   - `docs/guide/api/api-nested-and-bulk-operations.md` — nested relationship
     writes + bulk create/update/delete/upsert
   - `docs/guide/api/api-errors-rate-security.md` — error envelope + rate limits
3. `docs/core-concepts/relationships.md` — relationship selection
   (`select=` / `with=`) and the Relationship Write Payload Guide.

Rule: load one small chunked page for context instead of re-reading large source
files. Open package `src/` only when you need exact implementation detail.

## 2. Internalize the non-negotiable API contract

Encode these rules in the project's own agent docs (step 4). They are fixed:

- Response envelope is ALWAYS `{ "success": bool, "error_code": int, "data": ...,
  "meta": { ... } }`. Errors set `success: false` and add `message` + `errors`.
- Auth: `Authorization: Bearer <access_token>`; tenancy via the `X-Tenant-ID`
  header when the backend has tenant scoping.
- **Filters** are top-level query params in PostgREST-style dot notation
  `{column}={operator}.{value}` (e.g. `status=eq.active`, `amount=gte.100`,
  `id=in.a,b,c`). Do NOT wrap filters in a `filter[...]` key — bracket-wrapped
  filters are not supported and are silently ignored or return 422. Grouped
  logic uses `or=(...)` / `and=(...)`.
- **Sort**: `sortby=<column>&order=asc|desc` (single column only).
- **Search**: prefer `s=<text>` (auto-detected searchable columns). `search=<text>`
  only works when the table has explicit `RecordTableType(searchable:[...])` config.
- **Pagination**: offset (`page` / `per_page`) or cursor (`cursor` / `direction`,
  optional `cursor_column`) depending on the backend's
  `record.pagination.default_mode`. Use `total=false` to skip the COUNT query;
  `skip_total` / `add_total` are legacy aliases.
- **Relationships**: request includes via `select=col1,col2,rel(cols),nested(...)`
  or the alias `with=`. Relation names come from the table's registered
  `relationships` config — verify them via the schema MCP, never invent them (422).
- **Minimal selects**: never `select=*` on large tables; project only the columns
  the code consumes. Computed accessors (e.g. an attachment `url`) only
  materialize when the relation is selected with `rel(*)` — explicit columns
  return the accessor as null.
- **Writes**: create `POST /{table}`, update `PUT`/`PATCH /{table}/{id}`, delete
  `DELETE /{table}/{id}` (add `?force=true` to hard-delete), upsert
  `POST /{table}/upsert?match_on=col`, restore `POST /{table}/{id}/restore`,
  force-delete `DELETE /{table}/{id}/force`, bulk `POST /{table}/bulk/*`.
  Unknown payload fields or relations return 422 (not silently dropped).

## 3. Discover the API surface + CRUD operations via the Schema MCP

Register the backend's Schema MCP (`{schema-mcp-url}`). It exposes 3 read-only
schema tools — call them BEFORE writing any API client code:

- `sp_api_list_endpoints` — every route/table with its HTTP method(s), URI, and
  the enabled `actions` per endpoint.
- `sp_api_get_endpoint` (with `?endpoint={table}`) — the full contract for one
  table. Top-level metadata: `primaryKey`, `softDeletes`, `hasTenantId`,
  `isAuthRead`/`isAuthWrite`, `authGuard`. Plus:
  - `actions` — every enabled CRUD operation with its HTTP method + URI + note
  - `fields` — name, type, nullable, `in: [read|write]`, and `enum` values when a
    column is constrained
  - `filters` — which operators each field supports
  - `sorts` — sortable columns
  - `includes` — relationships (type, table, foreignKey, `writable`,
    `allowCreate`/`allowUpdate`/`allowDelete`, and a `payloadHint` with the exact
    write shape)
  - `rpcFunctions` — custom table functions (name, method, uri, description)
  - `permissions` — required permissions per action
  - `validation` — table create/update/delete validator descriptions
  - `scopes` — the fields the `search=` param covers (from `searchable` config)
- `sp_api_list_permissions` — all permission names across the app.

Use the `actions` map as the authoritative source for HOW to call each CRUD
operation — do not guess URLs. Map it like this:

| `actions` key                                   | HTTP call |
|-------------------------------------------------|-----------|
| `list`                                          | `GET /{table}` |
| `read`                                          | `GET /{table}/{id}` |
| `create`                                        | `POST /{table}` |
| `update`                                        | `PUT` / `PATCH /{table}/{id}` |
| `delete`                                        | `DELETE /{table}/{id}` (+ `?force=true` to hard-delete) |
| `upsert`                                        | `POST /{table}/upsert?match_on=col1,col2` |
| `restore`                                       | `POST /{table}/{id}/restore` (soft-deleted tables only) |
| `forceDelete`                                   | `DELETE /{table}/{id}/force` |
| `bulkCreate` / `bulkUpdate` / `bulkDelete` / `bulkUpsert` / `bulkMixed` | `POST /{table}/bulk/*` (JSON array body; `bulkMixed` auto-detects each item's operation) |

The MCP schema is metadata only — it tells you WHICH actions/fields/operators/
relations exist, but it does NOT carry the global documentation. It is MISSING:

- the error-code table (`0`, `10000`–`10014`) — from
  `docs/guide/api/api-errors-rate-security.md`
- the full filter-operator reference (eq/neq/in/between/grouped logic/Postgres
  native) — from `docs/guide/api/api-crud-operations.md`
- the pagination guide (offset/cursor/total control) — from
  `docs/guide/modules/module-pagination.md`
- bulk payload examples (array shapes, per-item auto-detect) — from
  `docs/guide/api/api-nested-and-bulk-operations.md`
- the Relationship Write Payload Guide (FK scalar vs alias array, `_delete`) —
  from `docs/core-concepts/relationships.md`

Pair the MCP schema (WHAT exists) with the docs above (HOW to use it), and
encode the confirmed query syntax in the project's `.agents/context/`
(step 4).

## 4. Extend the existing agent context (do NOT rebuild it)

The project already has its own setup: a root `AGENTS.md`/`CLAUDE.md`,
`.agents/rules/` (core commands, architecture, coding standards, metadata), and
`.agents/context/`. Those are already correct — do NOT recreate or rewrite them.

1. Read the current folder pattern first. Open the root `AGENTS.md`/`CLAUDE.md`
   and every file under `.agents/rules/` and `.agents/context/` to learn the
   project's conventions (commands, folder structure + routing, state management,
   HTTP client, UI patterns). The architecture rule and the metadata rule already
   describe the project layout — reuse them as-is.

2. ADD only the missing sp-laravel-api-specific knowledge, matching the existing
   naming and style:
   - A `.agents/context/backend-boundaries.md` (or the project's equivalent) with:
     the confirmed query-syntax table (correct form vs what to avoid), the
     `select=` projection rules, and any caching gotchas you learn during setup.
   - A short API-integration rule — add to the existing coding-standards rule or
     a new numbered file — covering: always query the schema MCP before creating
     or modifying any API integration; always use minimal column-level `select=`
     (never `select=*` on large tables); relation names must be verified via the
     schema MCP, never invented.
   - Point the root `AGENTS.md`/`CLAUDE.md` at the new files if it does not
     already reference them.

3. Do NOT duplicate what is already documented — extend in place.

## 5. Frontend-only scope + API issue reporting

The client is frontend-only. The MCP OpenAPI metadata is the source of truth for
API behavior. Never read or debug the Laravel backend to explain API behavior.

Triage before reporting — fix in the client and do NOT report:
- Wrong query syntax, stale cache, expired token, or bad params.
Only report genuine API bugs (response deviates from the MCP schema, a
reproducible 4xx/5xx with a correct request, or the schema itself is stale) to
the backend team, with `meta.request_id` and a minimal repro. Create a
`docs/api-reports/` folder with a `_TEMPLATE.md` for this.

## 6. Verify

- Run the client's linter/analyzer/type-check on the touched package.
- Grep new API call sites for known-wrong patterns (bracket filters, `select=*`)
  and fix them before finishing.

## Deliverable

Return: (1) the files you ADDED or UPDATED (a diff-style summary of what changed,
not a full rebuild), (2) the API-contract rules you encoded in
`.agents/context/backend-boundaries.md`, and (3) the list of endpoints/fields
you confirmed via the schema MCP.
```
