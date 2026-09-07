---
title: "API Client Export Preservation and Auth Header"
description: "Design for non-destructive Bruno and Postman exports, explicit force replacement, and request-level Authorization headers."
keywords:
  - bruno
  - postman
  - export
  - force
  - authToken
  - authorization header
---

# Design: API Client Export Preservation and Auth Header

**Status:** Approved for review
**Date:** 2026-09-07
**Branch:** `develop`

## Purpose

Make repeated Bruno and Postman exports safe for real development collections.
An ordinary export must add endpoints discovered from OpenAPI without replacing
the user's request bodies, test data, headers, scripts, examples, or custom
requests. Replacement is explicit through `--regen=<tag>` or `--force`.

Generated protected requests must expose the authentication requirement plainly
with `Authorization: Bearer {{authToken}}`. Public and login requests must not
send that header.

## Current problem

`ApiClientExportService` classifies existing requests as `Skipped`, but the
Bruno emitter renders every request in the result and the shared command writes
every rendered file. The existing `.bru` request is therefore overwritten even
when the command summary says it was skipped. Postman serializes a fresh
collection JSON document, so it also replaces user edits on every export.

Both emitters currently use collection-level bearer authentication with a
`bearerToken` variable. This hides the concrete Authorization header and risks
duplicate authentication if a request-level header is added without removing
the inherited collection auth.

## Public command contract

Both commands support the following modes:

| Command mode | Generated endpoints | Existing request edits | Custom requests |
|---|---|---|---|
| no flag | Add missing endpoints | Preserve | Preserve |
| `--regen=users,orders` | Replace matching generated endpoints in the named tags; add missing endpoints | Preserve outside named tags | Preserve |
| `--regen=all` | Replace all matching generated endpoints | Preserve custom requests | Preserve |
| `--force` | Same generated-request replacement scope as `--regen=all`, plus generated collection support metadata | Overwrite generated request data | Preserve custom requests |

`--force` and `--regen` are mutually exclusive. The command returns exit code
`2` when both are supplied, before writing any output. `--regen=all` remains
supported for backwards compatibility; it does not replace unrelated custom
collection metadata.

## Request identity and preservation

The exporter identifies a generated request by its OpenAPI tag (folder) plus
its generated request name (OpenAPI `summary`, falling back to `operationId`).

### Bruno

- The generated request path (`<tag>/<request name>.bru`) is the primary
  identity.
- Default mode never rewrites an existing path; the original `.bru` content is
  retained byte-for-byte.
- `--regen=<tag>` may replace matching generated request paths only in those
  tag folders.
- `--force` replaces all current generated request paths and generated support
  files (`bruno.json`, `collection.bru`, and `environments/Local.bru`).
- Files not emitted from the current OpenAPI document are never deleted. This
  preserves user-created folders, requests, and retired endpoint examples.

### Postman

- The command structurally merges the existing collection rather than replacing
  the JSON document.
- In default mode, a matching existing item remains unchanged, including its
  request body, test/event scripts, headers, examples, and descriptions.
- New generated items are appended to their matching OpenAPI-tag folder. A
  user-created item in a generated folder remains in place.
- Regeneration replaces only matching generated items in the named tag(s).
  Force replaces all matching generated items. Items that cannot be matched to
  a current OpenAPI endpoint remain untouched.
- Collection variables are merged by key. Existing user values and custom
  variables remain unchanged; package-managed `baseUrl`, `apiPrefix`, and
  `authToken` are added when absent. `--force` refreshes package-managed values
  from the current spec while retaining unrelated variables.

This identity is intentionally conservative. A user item that collides with a
generated tag/name is treated as the generated request only when they explicitly
regenerate that scope or force the export.

## Authentication output

Newly generated output uses the `authToken` variable.

| Request type | Bruno | Postman |
|---|---|---|
| Protected | `Authorization: Bearer {{authToken}}` header and `auth: none` | `Authorization: Bearer {{authToken}}` request header and `noauth` request auth |
| Public or login | No Authorization header and `auth: none` | No Authorization header and `noauth` request auth |

The generated collection-level bearer configuration is removed so the header is
the sole authentication mechanism. `authToken` is a secret environment
variable in Bruno and a collection variable in Postman. Newly generated login
capture scripts write the received token to `authToken`.

Existing preserved login requests keep their current scripts until the user
regenerates their RPC tag or uses `--force`; this is necessary to avoid silently
rewriting user changes. When an existing collection is updated normally, the
exporter adds a missing `authToken` variable without replacing the user's value.

## Components

| Component | Responsibility |
|---|---|
| `AbstractExportCommand` | Parse/validate `--force`, select the overwrite mode, and safely write Bruno files or merged Postman JSON. |
| `ApiClientExportService` | Retain request/tag identity and report added, regenerated, and preserved items. |
| `BrunoEmitter` | Render request-level headers, `authToken`, and file-path-aware generated requests. |
| `PostmanEmitter` | Render request-level headers and provide a structural collection merge by folder/request identity. |
| `ExportResult` / emitter contract | Carry any additional generated identities needed for safe writes without inferring ownership from user content. |

## Error handling

- Invalid `--regen` tags continue to return exit code `2`.
- `--force` combined with `--regen` returns exit code `2` and explains the
  conflict.
- Invalid existing Postman JSON or Bruno request files continue to fail rather
  than risking data loss.
- A failed write leaves unrelated existing collection files untouched; no
  exporter cleanup or deletion step is introduced.

## Tests

- Bruno default re-export preserves an edited JSON body, custom header, and
  test/script block in an existing generated request while adding a new endpoint.
- Bruno `--regen=<tag>` replaces only that tag's generated request, and
  `--force` replaces all generated requests and support files without deleting
  a custom `.bru` file.
- Postman default re-export preserves edited matching items and custom items,
  while appending a new generated item.
- Postman selective regeneration and force replacement have the same scope as
  Bruno.
- Protected generated requests have exactly one Authorization header with
  `Bearer {{authToken}}`; public/login requests have none.
- Bruno and Postman login capture output writes `authToken`, and the generated
  collection does not retain collection-level bearer authentication.
- Option-conflict and dry-run behavior are covered for both commands.

## Documentation

Update `docs/guide/modules/module-api-clients.md` with the preservation table,
`--force` semantics, explicit request-level authentication, the `authToken`
variable, and the migration note for existing collections.

## Out of scope

- Deleting stale generated endpoints.
- Inferring whether an arbitrary user-created endpoint should be considered
  package-owned.
- Overwriting user-created requests that do not match an emitted OpenAPI
  tag/name.
- Adding credentials or actual token values to exported collections.
