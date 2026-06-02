---
title: "API Client Exporters (Bruno + Postman)"
description: "Design spec for two new Artisan commands that export the package's OpenAPI spec to Bruno v3 and Postman v2.1 collection files, with request-level merge and explicit regeneration semantics."
keywords:
  - api client
  - bruno
  - postman
  - export
  - openapi
  - collection
  - regen
  - crud
  - scalar
---

# Design: API Client Exporters (Bruno + Postman)

**Status:** Approved
**Date:** 2026-06-02
**Branch:** `opencode/lucky-otter`

## 1. Purpose

Add two Artisan commands that export the package's existing OpenAPI 3 spec to ready-to-use API client collections — one for [Bruno](https://www.usebruno.com/) (v3 JSON format) and one for [Postman](https://www.postman.com/) (v2.1 JSON format). This lets developers test the same API inside their preferred dev tool, the way the package's built-in Scalar UI does today.

## 2. Scope

**In scope**

- Two new commands: `sp-laravel-api:export-bruno` and `sp-laravel-api:export-postman`
- A new shared `ApiClientExportService` that handles the spec → collection transform
- Two new emitters: `BrunoEmitter` (v3 JSON) and `PostmanEmitter` (v2.1 JSON)
- A new DTO: `ExportResult`
- Feature tests for the service and both commands
- A new docs page under `docs/guide/modules/`

**Out of scope (YAGNI)**

- Per-tool config files (`config/records/bruno.php`, `config/records/postman.php`)
- Custom sample values for request bodies
- Per-request skip list / disable flags
- Insomnia / HTTPie emitters
- Per-table renames or folder overrides

## 3. Commands

### 3.1 `sp-laravel-api:export-bruno`

```bash
php artisan sp-laravel-api:export-bruno
php artisan sp-laravel-api:export-bruno --output=tests/bruno/users.bru
php artisan sp-laravel-api:export-bruno --regen=users
php artisan sp-laravel-api:export-bruno --regen=users,orders
php artisan sp-laravel-api:export-bruno --regen=all
php artisan sp-laravel-api:export-bruno --dry-run
```

### 3.2 `sp-laravel-api:export-postman`

Same flag set; default output is `api-clients/postman/collection.json`.

### 3.3 Flags

| Flag | Required | Default | Behavior |
|---|---|---|---|
| `--output=<path>` | no | `api-clients/bruno/collection.bru` (Bruno) or `api-clients/postman/collection.json` (Postman) | Output file path. Relative to project root or absolute. Auto-creates parent dirs. |
| `--regen=<list\|all>` | no | (none — skip existing) | Comma-separated table keys (e.g., `users,orders`) or `all`. Tables in this list are always regenerated; others are skipped if already present. |
| `--dry-run` | no | false | Print diff summary; do not write. |

### 3.4 Exit codes

- `0` — success
- `1` — OpenAPI generation, render, or write failure
- `2` — invalid `--regen` value (table not in registry)

## 4. Architecture

```
OpenApiService::generateInternal()
        ↓
ApiClientExportService  (new, shared)
   - loads existing collection (if any)
   - builds request name from OpenAPI `summary` (fallback `operationId`)
   - diffs existing-vs-new by name
   - applies --regen filter
   - injects `select` param for list/get requests
   - injects collection-level bearer auth + vars
   - returns ExportResult DTO
        ↓
┌──────────────────────┐    ┌──────────────────────┐
│ BrunoEmitter (v3)    │    │ PostmanEmitter (v2.1)│
└──────────────────────┘    └──────────────────────┘
        ↓                              ↓
ExportBrunoCommand         ExportPostmanCommand
```

### 4.1 New files

| File | Role |
|---|---|
| `src/Services/ApiClient/ApiClientEmitterInterface.php` | Contract for emitters |
| `src/Services/ApiClient/BrunoEmitter.php` | Bruno v3 JSON emitter |
| `src/Services/ApiClient/PostmanEmitter.php` | Postman v2.1 JSON emitter |
| `src/Services/ApiClient/ExportResult.php` | DTO: `added[]`, `regenerated[]`, `skipped[]`, `suggestions[]` |
| `src/Services/ApiClientExportService.php` | Shared merge/regen logic (sibling of `OpenApiService`) |
| `src/Console/ExportBrunoCommand.php` | Bruno command |
| `src/Console/ExportPostmanCommand.php` | Postman command |
| `tests/Feature/ApiClientExportServiceTest.php` | Service unit tests |
| `tests/Feature/ExportBrunoCommandTest.php` | Bruno end-to-end tests |
| `tests/Feature/ExportPostmanCommandTest.php` | Postman end-to-end tests |
| `docs/guide/modules/module-api-clients.md` | User-facing docs |

### 4.2 Modified files

- `src/CoreSpLaravelApiProvider.php` — register the two new commands in the `runningInConsole()` block (line ~85)
- `docs/ai/architecture.md` — add a short section for the new exporters
- `CHANGELOG.md` — note the new feature

## 5. Output folder layout

```
project-root/
└── api-clients/
    ├── bruno/
    │   └── collection.bru          # Bruno v3 JSON
    └── postman/
        └── collection.json         # Postman v2.1 JSON
```

Both folders are git-ignored by default convention (developers may opt in to commit the collection files).

## 6. Naming (follows OpenAPI; no renaming)

| Collection field | Source |
|---|---|
| Folder name | OpenAPI `tag` (one per `RecordTableType`, plus one `RPC` folder for global + table-scoped RPCs) |
| Request name | OpenAPI `summary` (fallback `operationId`) |
| Request description | OpenAPI `description` |
| Request URL | `{{baseUrl}}{{apiPrefix}}<openapi-path>` |

This guarantees the collection displays identically to the existing Scalar UI.

## 7. Collection variables (both tools)

| Var | Source | Secret? |
|---|---|---|
| `baseUrl` | First OpenAPI `servers[0].url` (i.e. `app.url`) | No |
| `apiPrefix` | `record.api_prefix` (e.g., `/api/v1`) | No |
| `bearerToken` | empty string, user fills in dev tool | Yes (Bruno: `secret: true`) |

Splitting `baseUrl` from `apiPrefix` lets users swap environments (local / staging / prod) by editing only `baseUrl`, while keeping `apiPrefix` stable across environments and editable only on API version bumps.

## 8. Relationship parameter (reusing OpenAPI `select`)

The package's existing relationship-inclusion syntax is `?select=...,customer:customers(id,name),items(*)`. The emitter surfaces this as a discoverable but off-by-default parameter on every list/get request:

- **Bruno v3:** inject a `select` query param on each list/get request with:
  - `enabled: false`
  - `value: ""`
  - `description: "Set to load relationships, e.g. *,customer:customers(id,name),items(*)"`
- **Postman v2.1:** omit `select` from `query[]`; append a hint line to `request.description`:
  - `"\n\nTip: append ?select=*,customer:customers(id,name),items(*) to test relationship loading."`

This applies to all GET (list) and GET (detail) requests.

## 9. RPC functions

Both global (`/api/v1/rpc/{fn}`) and table-scoped (`/api/v1/{table}/rpc/{fn}`) RPC endpoints are emitted into a dedicated `RPC` folder at the end of the collection.

## 10. Auth (collection-level bearer)

- Bruno: `auth.mode = "bearer"`, `auth.bearer.token = "{{bearerToken}}"`; `vars.bearerToken.secret = true`
- Postman: `auth.type = "bearer"`, `auth.bearer[0] = { key: "token", value: "{{bearerToken}}" }`; `variable[]` entry `bearerToken`

No hardcoded tokens anywhere.

## 11. Emitter contracts

### 11.1 Bruno v3 JSON (`collection.bru`)

```json
{
  "meta": {
    "name": "<APP_NAME> API",
    "type": "collection",
    "version": "v3"
  },
  "auth": {
    "mode": "bearer",
    "bearer": { "token": "{{bearerToken}}" }
  },
  "vars": {
    "baseUrl":    { "value": "<app.url>",        "enabled": true,  "secret": false },
    "apiPrefix":  { "value": "/api/v1",          "enabled": true,  "secret": false },
    "bearerToken":{ "value": "",                 "enabled": true,  "secret": true  }
  },
  "folders": [
    {
      "name": "Users",
      "requests": [
        {
          "name": "List Users",
          "type": "http",
          "method": "GET",
          "url": "{{baseUrl}}{{apiPrefix}}/users",
          "params": [
            { "name": "page",    "value": "1",  "enabled": true,  "type": "query" },
            { "name": "per_page","value": "25", "enabled": true,  "type": "query" },
            { "name": "select",  "value": "",   "enabled": false, "type": "query",
              "description": "Set to load relationships, e.g. *,customer:customers(id,name),items(*)" }
          ],
          "headers": [
            { "name": "Accept", "value": "application/json", "enabled": true }
          ],
          "docs": "Retrieve Users records with comprehensive query capabilities..."
        }
      ]
    }
  ]
}
```

**Emission rules**
- One `folders[]` entry per OpenAPI `tag` (in OpenAPI declaration order), plus one `RPC` folder last.
- `name` (request) = `summary` (fallback `operationId`).
- `url` = `{{baseUrl}}{{apiPrefix}}` + the full OpenAPI path (path parameters kept literal, e.g., `{{baseUrl}}{{apiPrefix}}/users/{id}`).
- For list/get: inject `select` param as described in §8.
- For POST/PUT/PATCH: include `body.mode = "json"`, `body.json = "<example derived from OpenAPI Write schema>"`.
- `vars.baseUrl` = first `servers[0].url`; `vars.apiPrefix` = `record.api_prefix` (with leading `/`).

### 11.2 Postman v2.1 JSON (`collection.json`)

```json
{
  "info": {
    "name": "<APP_NAME> API",
    "schema": "https://schema.getpostman.com/json/collection/v2.1.0/collection.json"
  },
  "auth": {
    "type": "bearer",
    "bearer": [{ "key": "token", "value": "{{bearerToken}}" }]
  },
  "variable": [
    { "key": "baseUrl",     "value": "<app.url>" },
    { "key": "apiPrefix",   "value": "/api/v1" },
    { "key": "bearerToken", "value": "" }
  ],
  "item": [
    {
      "name": "Users",
      "item": [
        {
          "name": "List Users",
          "request": {
            "method": "GET",
            "header": [{ "key": "Accept", "value": "application/json" }],
            "url": {
              "raw": "{{baseUrl}}{{apiPrefix}}/users",
              "host": ["{{baseUrl}}{{apiPrefix}}"],
              "path": ["users"],
              "query": [
                { "key": "page",     "value": "1",  "disabled": false },
                { "key": "per_page", "value": "25", "disabled": false }
              ]
            },
            "description": "Retrieve Users records with comprehensive query capabilities...\n\nTip: append ?select=*,customer:customers(id,name),items(*) to test relationship loading."
          }
        }
      ]
    }
  ]
}
```

**Emission rules**
- One `item[]` folder per OpenAPI `tag` (each containing its own `item[]` array of requests), plus one `RPC` folder last.
- Request `name` = `summary` (fallback `operationId`).
- `url.raw` = `{{baseUrl}}{{apiPrefix}}` + the full OpenAPI path. `host` is `["{{baseUrl}}{{apiPrefix}}"]`; `path` is the URL path segments (e.g., `["api","v1","users"]`).
- For list/get: omit `select` from `query[]`; append the relationship hint to `request.description` as described in §8.
- For POST/PUT/PATCH: include `body.mode = "raw"`, `body.raw = "<example JSON derived from OpenAPI Write schema>"`, `body.options.raw.language = "json"`.
- `variable[]` entries: `baseUrl` from `servers[0].url`, `apiPrefix` from `record.api_prefix`, `bearerToken` empty.

## 12. Behavior matrix

| Existing collection | `--regen` flag | Result |
|---|---|---|
| absent | absent | Generate all requests from OpenAPI. |
| present | absent | Add only new requests (by `summary` name). Skip existing. List ungenerated tables as suggestions. |
| present | `--regen=users` | Regenerate `users` requests. Skip others. List ungenerated tables as suggestions. |
| present | `--regen=users,orders` | Regenerate `users` and `orders`. Skip others. List ungenerated tables as suggestions. |
| present | `--regen=all` | Regenerate every request. List ungenerated tables as suggestions. |
| absent | `--regen=users` | Generate only `users` requests. List other tables as suggestions. |
| absent | `--regen=all` | Same as absent + absent (generate everything). |
| any | `--dry-run` | Print summary; do not write. |

A request is identified by the value of OpenAPI `summary` (with `operationId` fallback). If a request's path/method/schema changes in OpenAPI but its `summary` is unchanged, it is **regenerated** (overwritten). The key implication: hand-edits inside Bruno/Postman are preserved only if the OpenAPI `summary` stays stable AND the user relies on the dev tool's own env/vars for tweaks.

## 13. Console output (final)

```
$ php artisan sp-laravel-api:export-bruno

OpenAPI spec loaded: 42 paths, 8 tags
Collection: api-clients/bruno/collection.bru

+ Added      (3)  List Users, Create Users, Get Users by ID
~ Regenerated (1)  Update Users
- Skipped    (2)  Delete Users, Upsert Users
? Suggestions (4)  products, orders, invoices, customers

Done. Run with --regen=all to regenerate the full collection.
```

The same shape is used by `export-postman`.

## 14. Error handling

| Failure | Exit code | Behavior |
|---|---|---|
| `OpenApiService::generateInternal()` throws | 1 | `$this->error(...)` message, no write. |
| `--regen=foo` and `foo` is not a registered table | 2 | `$this->error(...)` listing valid tables, no write. |
| Output dir not writable | 1 | `$this->error(...)` message, no write. |
| Existing file is invalid JSON for the target tool | 1 | `$this->error(...)` message, no write. (Corruption is the user's problem; we do not try to recover.) |
| `--regen=all` with no OpenAPI spec | 0 | No-op with a clear "no paths to export" line. |

## 15. Testing

### 15.1 `ApiClientExportServiceTest`

Unit-test the shared service in isolation, asserting the `ExportResult` buckets for:

- absent collection → all requests land in `added[]`
- present collection + no `--regen` → new ones in `added[]`, existing in `skipped[]`
- present collection + `--regen=users` → `users` requests in `regenerated[]`, others in `skipped[]`
- present collection + `--regen=all` → all in `regenerated[]`
- `--dry-run` → all buckets populated but no file written

### 15.2 `ExportBrunoCommandTest`

End-to-end with a fixture spec. Asserts on the produced `collection.bru` JSON:

- `meta.version === "v3"`
- `vars.baseUrl`, `vars.apiPrefix`, `vars.bearerToken` all present with correct values
- `auth.mode === "bearer"`
- One `folders[]` entry per OpenAPI tag, plus `RPC`
- `select` param on every list/get request with `enabled: false`
- Request `name` matches OpenAPI `summary`

### 15.3 `ExportPostmanCommandTest`

End-to-end with a fixture spec. Asserts on the produced `collection.json` JSON:

- `info.schema` is the v2.1 URL
- `variable[]` contains `baseUrl`, `apiPrefix`, `bearerToken`
- `auth.type === "bearer"`
- One `item[]` folder per OpenAPI tag, plus `RPC`
- `select` is absent from `query[]` on list/get requests
- Request `description` ends with the relationship hint
- Request `name` matches OpenAPI `summary`

## 16. Constraints

- PHP 8.2+ (matches `composer.json`).
- Laravel 12+ / 13+.
- Backward compatibility: zero changes to existing public APIs. The two new commands are purely additive.
- No new runtime dependencies. Bruno v3 JSON and Postman v2.1 JSON are emitted with `json_encode` only.
- Strict typing (per `docs/ai/coding-standards.md`).
- All new public classes are in the `Sopheak\Core` PSR-4 root (`src/`) or the `Sopheak\Core\Services\ApiClient` subnamespace.

## 17. Next steps

1. Invoke the `writing-plans` skill to produce a step-by-step implementation plan.
2. Implement per the plan.
3. Run `composer test` and `composer analyse` before declaring done.
4. Update `docs/guide/modules/module-api-clients.md` and `CHANGELOG.md` as part of the implementation.
