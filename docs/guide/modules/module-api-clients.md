---
title: "API Client Exporters (Bruno / Postman)"
description: "Export the OpenAPI spec to a Bruno v3 or Postman v2.1 collection file with diff-aware updates and per-table regeneration."
keywords:
  - bruno
  - postman
  - openapi
  - api client
  - collection
  - export
---

# Module: API Client Exporters (Bruno / Postman)

The package ships two Artisan commands that turn the auto-generated OpenAPI spec into ready-to-use API client collections: **Bruno v3** and **Postman v2.1**. Both commands are diff-aware: they preserve requests you hand-edited and only add or regenerate what the spec changed.

## Table of Contents
- [Module: API Client Exporters (Bruno / Postman)](#module-api-client-exporters-bruno--postman)
  - [Table of Contents](#table-of-contents)
  - [Quick start](#quick-start)
  - [Commands](#commands)
  - [Flags](#flags)
  - [Exit codes](#exit-codes)
  - [How the diff works](#how-the-diff-works)
  - [Output layout](#output-layout)
  - [Collection variables](#collection-variables)
  - [Format-specific behavior](#format-specific-behavior)
  - [Examples](#examples)

## Quick start

```bash
# Export to Bruno (writes api-clients/bruno/collection.bru)
php artisan sp-laravel-api:export-bruno

# Export to Postman (writes api-clients/postman/collection.json)
php artisan sp-laravel-api:export-postman

# Preview the diff without writing
php artisan sp-laravel-api:export-bruno --dry-run

# Regenerate only the `users` and `orders` folders
php artisan sp-laravel-api:export-bruno --regen=users,orders

# Regenerate the entire collection
php artisan sp-laravel-api:export-bruno --regen=all
```

The first run creates the collection with every endpoint in the spec. Subsequent runs add only what's new and skip requests that already exist (and weren't requested for regeneration).

## Commands

| Command | Default output | Format |
|---|---|---|
| `sp-laravel-api:export-bruno`   | `api-clients/bruno/collection.bru`     | Bruno v3 (JSON in a `.bru` file) |
| `sp-laravel-api:export-postman` | `api-clients/postman/collection.json`  | Postman v2.1 |

## Flags

| Flag | Default | Description |
|---|---|---|
| `--output=<path>` | (format-specific default) | Output file path. Relative paths are resolved from `base_path()`. The parent directory is created if it doesn't exist. |
| `--regen=<list>`  | (none)             | Comma-separated table keys to regenerate, or `all`. Matching is case-insensitive against OpenAPI tags. Tables not listed are skipped if they already exist in the collection. |
| `--dry-run`       | `false`            | Print the diff summary to stdout; do not write the file. |

## Exit codes

| Code | Meaning |
|---|---|
| `0` | Success (including `--dry-run`) |
| `1` | OpenAPI generation failed, existing file is invalid JSON, or write failed |
| `2` | One or more `--regen` values do not match any tag in the spec |

## How the diff works

On every run the command asks the emitter to enumerate the request names already on disk. Each new request from the spec lands in one of four buckets:

| Bucket | Meaning |
|---|---|
| **Added** (`+`)       | New in the spec, not present on disk — gets written. |
| **Regenerated** (`~`) | On disk **and** in the `--regen` set — gets re-rendered (overwrites your hand edits). |
| **Skipped** (`-`)     | On disk and **not** in the `--regen` set — preserved as-is. |
| **Suggestions** (`?`) | A non-RPC table tag in the spec that ended up with zero requests (e.g. endpoint hidden by auth); surfaced for review. |

Default behavior (no `--regen` flag): skip existing, add new. To update a request you hand-edited, list its tag in `--regen=`.

## Output layout

The default paths are relative to `base_path()` (your Laravel app root):

```
your-app/
└── api-clients/
    ├── bruno/
    │   └── collection.bru        # Bruno v3 (JSON)
    └── postman/
        └── collection.json       # Postman v2.1
```

Override with `--output=`. The path can be absolute or relative.

## Collection variables

Both formats get three collection-level variables:

| Name          | Value                  | Notes |
|---------------|------------------------|-------|
| `baseUrl`     | `config('app.url')`    | e.g. `http://localhost` |
| `apiPrefix`   | `config('record.api_prefix')` | Leading `/` is prepended automatically (e.g. `/api/v1`) |
| `bearerToken` | (empty, secret)        | Treated as a secret by both clients |

The request URL is built as `{{baseUrl}}{{apiPrefix}}{{path}}`, so the same collection works across local, staging, and production by changing the `baseUrl` var.

## Format-specific behavior

| Behavior | Bruno | Postman |
|---|---|---|
| Auth at collection level | `bearer` mode | `bearer` with `token: {{bearerToken}}` |
| `select` query param on list endpoints | Injected as a **disabled** param with description | Omitted, but description includes a `Tip:` line |
| Folder naming | First `tag` from the OpenAPI operation (pluralized) | Same as Bruno |
| RPC endpoints | Single `RPC` folder appended last | Same as Bruno |
| Request naming | OpenAPI `summary` (e.g. `List Users`) | Same as Bruno |

In Bruno, the disabled `select` param keeps the field visible in the UI but prevents accidental sends. In Postman, omitting the param is the cleaner choice since Postman has no `enabled:false` concept; the description tells the user what to do.

## Examples

```bash
# First-time export of the full collection
php artisan sp-laravel-api:export-bruno

# A new table was added — just add it, leave the rest alone
php artisan sp-laravel-api:export-bruno
# Output:
#   + Added       (3)  List Orders, Create Orders, Get Orders by ID
#   ~ Regenerated (0)
#   - Skipped     (12) List Users, Create Users, ...
#   ? Suggestions (0)

# You hand-edited a request and want to refresh it from the spec
php artisan sp-laravel-api:export-bruno --regen=users

# Catch a typo in a regen value before it runs
php artisan sp-laravel-api:export-bruno --regen=usr
# Output: Invalid --regen value(s): usr
#         Available tables: users, orders
# Exit code: 2

# See the diff without touching disk
php artisan sp-laravel-api:export-bruno --dry-run
```

The export pipeline is shared:

```
OpenApiService::generateInternal()
  -> ApiClientExportService::build()
    -> BrunoEmitter::render()  (or PostmanEmitter)
      -> json_encode
        -> file_put_contents
```

To support a new client format (e.g. Insomnia, Hoppscotch), implement `Sopheak\Core\Services\ApiClient\ApiClientEmitterInterface` and a thin `AbstractExportCommand` subclass — the diff logic, regen validation, and file writing are already covered by the shared base.
