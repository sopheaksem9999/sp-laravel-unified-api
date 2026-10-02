---
title: "MCP Support"
description: "MCP (Model Context Protocol) Support: expose schema discovery and CRUD tools to AI clients (Claude Code, Cursor, claude.ai, ChatGPT) with auth, tenancy, read-only mode and two drivers."
keywords:
  - mcp
  - model context protocol
  - laravel/mcp
  - claude
  - cursor
  - chatgpt
  - oauth
  - tools
  - resources
  - schema
---

# Module: MCP (Model Context Protocol) Support

The **Model Context Protocol (MCP)** integration lets AI assistants (Claude Code, Cursor, claude.ai, ChatGPT, …) understand, query and — when you allow it — change your `sp-laravel-api` data through a standard protocol.

Instead of giving an AI raw database access or a 50K-token OpenAPI file, MCP exposes your schema and CRUD operations as small tools. The AI stays inside your tenant boundaries, rate limits and permission checks.

## Table of Contents
- [Two MCP Endpoints](#two-mcp-endpoints)
- [Choosing a Driver](#choosing-a-driver)
- [Features](#features)
- [Configuration](#configuration)
- [Available Resources & Tools](#available-resources--tools)
- [What the Schema Tools Tell an Agent](#what-the-schema-tools-tell-an-agent)
- [Connecting Clients](#connecting-clients)
- [Use Cases](#use-cases)
- [Tenant Isolation](#tenant-isolation)
- [Security & Authentication](#security--authentication)
- [Upgrade Notes](#upgrade-notes)
- [Developing and Testing](#developing-and-testing)

## Two MCP Endpoints

The package provides two MCP endpoints with different security postures:

| | Data MCP | Schema MCP |
|---|---|---|
| **Route** | `POST /{api_prefix}/mcp/message` (and, on the `laravel` driver, `POST /{api_prefix}/mcp`) | `POST /{api_prefix}/mcp/schema` |
| **Tools** | CRUD (`list_*`, `read_*`, `create_*`, `update_*`, `delete_*`) + 4 schema tools | 4 schema tools **only** |
| **Data access** | Yes (reads/writes real data) | **None** (read-only schema) |
| **Auth** | User Bearer token (your app auth) or OAuth | `SP_API_MCP_TOKEN` (separate shared secret) |
| **Production-safe** | Only behind full auth | Yes — no data exposure even if the token leaks |
| **Config** | `config/sp-record.php` → `mcp.*` | `config/sp-api-mcp.php` |

The Schema MCP is made for **frontend AI coding agents** (Cursor, Claude Code, opencode, Copilot) that need to discover API routes, fields, filters and permissions without ever touching production data.

`GET /{api_prefix}/mcp/sse` answers `405 Allow: POST` on both drivers. The old SSE endpoint never worked (it advertised a URL that did not exist); clients use plain `POST`.

## Choosing a Driver

Set `record.mcp.driver` (`SP_MCP_DRIVER`):

| Driver | What it is |
|---|---|
| `legacy` (default) | The package's own JSON-RPC server. No extra dependency. **Deprecated: removed in 0.6.0.** |
| `laravel` | Servers built on the official [`laravel/mcp`](https://github.com/laravel/mcp) package: Streamable HTTP, `mcp:inspector`, stdio, OAuth 2.1 discovery. Needs `composer require laravel/mcp` |

The switch is explicit, not detected. `laravel/boost` pulls `laravel/mcp` into development installs only, so auto-detection would make development and production behave differently.

Both drivers share one tool catalog and one executor, so **tool names, schemas, results, tenant rules and error codes are identical**. What differs, deliberately:

| | `legacy` | `laravel` |
|---|---|---|
| Protocol version | answers `2024-11-05` to everyone | negotiates `2025-11-25` / `2025-06-18` (a client asking only for older versions is answered `2025-11-25`) |
| JSON-RPC errors | HTTP `200` | HTTP `404` (`-32601`), `500` (`-32603`), `400` (other codes, including `-32001`/`-32002`); the JSON body is identical |
| Notifications | `204` | `202` |
| `ping` | not supported | supported |
| `tools/list` | every tool | only the tools the caller may use; pages at 500 tools |
| `tools/call` errors | `-32001` unauthenticated or unknown table, `-32002` forbidden, `-32601` unknown tool | the same codes (the package registers its own `tools/call` so they survive) |
| Transports | JSON-RPC `POST`, stdio | JSON-RPC `POST`, Streamable HTTP `POST /mcp`, stdio, `mcp:inspector` |
| OAuth discovery | no | opt-in (`record.mcp.oauth`) |

Both drivers add a `title` and `annotations` (`readOnlyHint`, `destructiveHint`, `idempotentHint`, `openWorldHint`) to every tool in `tools/list`.

> **Laravel 12 note:** `laravel/mcp` needs Laravel 12.41.1+ (or 11.45.3+ / 13). Apps on older Laravel 12 releases stay on the `legacy` driver.

## Features

- **Schema auto-discovery**: tables, endpoints, fields, filters, sorts, relationships, validation rules, permissions and per-action request/response schemas as on-demand tools.
- **Dynamic CRUD tools**: `list_{table}`, `read_{table}`, `create_{table}`, `update_{table}`, `delete_{table}`.
- **Native security**: your `RecordTableType` auth flags (`isAuthRead`/`isAuthWrite`), custom authorizers, the built-in permission module and Laravel's Gate.
- **Tenancy**: the tenant comes from the request, never from tool arguments.
- **Sensitive column parity (`columnHiddens`)**: hidden columns are stripped from Data MCP responses and excluded from read schemas, filters and sorts.
- **Read-only mode**: a global switch that removes the create, update and delete tools.
- **Agent guidance**: the schema tools say exactly how to authenticate, filter, page, nest writes, handle errors and stay inside rate limits — see [What the Schema Tools Tell an Agent](#what-the-schema-tools-tell-an-agent).

## Configuration

### Data MCP (`record.mcp.*`)

```php
// config/sp-record.php
'mcp' => [
    'enabled' => env('SP_MCP_ENABLED', false),

    // true removes the create/update/delete tools (default: safe-by-default)
    'read_only' => env('SP_MCP_READ_ONLY', true),

    // legacy | laravel — see "Choosing a Driver"
    'driver' => env('SP_MCP_DRIVER', 'legacy'),

    // Opt-in OAuth 2.1 discovery for connector clients. Needs the `laravel`
    // driver and laravel/passport.
    'oauth' => env('SP_MCP_OAUTH', false),

    // Middleware applied to the Data MCP routes
    'middleware' => ['api', 'auth:sanctum'],

    // DEPRECATED, no effect. It never moved a route; advertised URLs now come
    // from the registered routes.
    'route_prefix' => env('SP_MCP_ROUTE_PREFIX', 'mcp'),
],
```

### Schema MCP (`sp-api-mcp.*`)

```php
// config/sp-api-mcp.php
return [
    // Enable/disable the POST /api/v1/mcp/schema route
    'enabled' => env('SP_API_MCP_ENABLED', false),

    // Bearer token. In local: null means open access. Elsewhere a token is REQUIRED.
    'token' => env('SP_API_MCP_TOKEN', null),
];
```

```bash
# Local dev — no auth needed
SP_API_MCP_ENABLED=true

# Production — locked behind a shared secret
SP_API_MCP_ENABLED=true
SP_API_MCP_TOKEN=YOUR_MCP_TOKEN
```

### Switching to the `laravel` driver

```bash
composer require laravel/mcp
```

```bash
# .env
SP_MCP_DRIVER=laravel
```

Booting with the `laravel` driver but without `laravel/mcp` fails with a clear `RuntimeException`. Existing clients keep their URLs, headers and tokens.

### OAuth for connector clients (opt-in)

claude.ai and ChatGPT custom connectors need OAuth discovery. With the `laravel` driver:

```bash
composer require laravel/passport
php artisan passport:install      # keys + clients, per the Passport docs
```

```bash
# .env
SP_MCP_DRIVER=laravel
SP_MCP_OAUTH=true
```

```php
// config/sp-record.php
'middleware' => ['api', 'auth:api'],   // Passport guard instead of Sanctum
```

This registers `/.well-known/oauth-protected-resource`, `/.well-known/oauth-authorization-server` and `/oauth/register` (dynamic client registration), and a `401` from the MCP routes carries the discovery hint. With `oauth` on and Passport missing, boot fails with `record.mcp.oauth requires laravel/passport`. With `oauth` off nothing is registered.

## Available Resources & Tools

### Resources
- `schema://{table}`: the `RecordTableType` configuration (columns, relations, primary key, flags).

### Data Tools (CRUD)

For every table with `canRead` (the default): `list_{table}` and `read_{table}`.
For every table (while `mcp.read_only` is false): `create_{table}` with `canCreate`, `update_{table}` with `canUpdate`, `delete_{table}` with `canDelete`. A table's `can*` flag set to `false` switches its HTTP route off, and the matching tool is not listed and is refused with `-32601` if called.

| Tool | `readOnlyHint` | `destructiveHint` | `idempotentHint` |
|---|---|---|---|
| `list_*`, `read_*`, `sp_api_*` | true | — | — |
| `create_*` | false | false | false |
| `update_*`, `delete_*` | false | true | true |

#### Filter syntax for `list_{table}` / `read_{table}`

`queryParams` filters are `{column: "operator.value"}` pairs — the same `{column}={operator}.{value}` syntax the HTTP API uses, as JSON:

```json
{ "queryParams": { "status": "eq.open", "total_amount": "gte.100", "items.qty": "gt.1" } }
```

Never nest filters under a `filter` key or use bracket syntax. `like` and `ilike` already match substrings — do not add `%`. Negate an operator with `not.` (`not.eq.5`) where it has a negated form; `contains`, `starts_with`, `ends_with` and `date_*` have none, and the guidance lists exactly which operators are `negatable` — a `not.` prefix on any other operator is ignored by the API, so the filter would not apply. Full reference: [Filter Operators](/guide/api-filter-operators). `sp_api_get_endpoint` lists, per field, exactly the operators that field accepts **on your database driver**.

#### Performance tip: `limit` instead of `per_page`

`{"limit": 5, "sortby": "created_at", "order": "desc"}` runs a plain `LIMIT 5` and skips the `COUNT(*)`. Use `page`/`per_page` only when a user interface is paging, add `skip_total=true` when you page but do not need the total, and switch to a cursor for deep reads. The guidance tool lists the exact limits (`limit_max`, `per_page_max`, `bulk_max`) for your app.

#### Writing related data in one `create_{table}` / `update_{table}` call

Check `includes[]` in `sp_api_get_endpoint`. A relationship with `"writable": true` can be nested in the parent's `payload`, written atomically in one transaction. `payloadHint` gives the shape — for example:

```json
{
  "payload": {
    "ref_number": "INV-1001",
    "customer_id": 10,
    "items": [{"description": "Line A", "quantity": 1}, {"id": 2, "description": "Line B"}, {"id": 5, "_delete": true}],
    "tags": [1, {"id": 2, "note": "primary"}, {"id": 5, "_delete": true}]
  }
}
```

- **hasMany / morphMany** (`items`): an item without `id` creates a child, with `id` updates it, `"_delete": true` (with `id`) deletes it. Children you leave out are **kept**. A bare id is a `422`.
- **belongsToMany / morphToMany / hasManyThrough** (`tags`): `{"id": N}` or a bare `N` attaches, extra pivot fields update the pivot, an item without `id` creates the related row, `{"id": N, "_delete": true}` detaches. Links you leave out are **kept**.
- **belongsTo** has no nested form: set the root field (`customer_id`).
- Every nested child needs the **child table's own** create/update/delete permission, exactly like a direct request; attaching an existing row needs only the parent's permission.

See [Nested & Bulk Operations](/guide/api-nested-and-bulk-operations).

### Schema Tools (Discovery)

Available on **both** endpoints:

| Tool | Description |
|---|---|
| `sp_api_list_endpoints` | All endpoints (tables + RPCs): name, method, URI, table, actions and a request/response summary. Optional `search`. |
| `sp_api_get_endpoint` | Everything about one endpoint: `actions` (CRUD, `upsert`, `restore`, `forceDelete`, bulk) with headers, request/response schemas and throttle group; `fields`, `filters`, `sorts`, `includes`, `rpcFunctions`, `permissions`, `validation`, `scopes`. Requires `endpoint`; optional `actions` (for example `["list","create"]`) returns only those actions. |
| `sp_api_list_permissions` | Every permission name: `{name, guard, table}`. |
| `sp_api_get_api_guidance` | The shared reference: headers, query syntax, operators, paging, errors, rate limits, nested-write rules, docs, realtime, enabled-module recipes and performance advice. Call it once after connecting. |

### MCP result format

Every tool advertises an `outputSchema` and returns a standard MCP result. Prefer `result.structuredContent`; the same JSON is in `result.content[0].text` for older clients — as **compact** JSON, not pretty-printed, which roughly halves the size of every response.

```json
{ "result": { "content": [ { "type": "text", "text": "{...same JSON, compact...}" } ], "structuredContent": { "endpoints": [] } } }
```

Schema list tools return `{ "endpoints": [...] }` / `{ "permissions": [...] }`; endpoint detail and guidance return their object directly. Data MCP returns `{ "response": { "data": ..., "meta": ... } }`.

**Repeated schemas are references.** In an `sp_api_get_endpoint` result the first copy of a repeated schema stays inline; later identical ones (the record schema in 12 actions, the operator list of same-typed fields) are `{"$ref": "#/actions/list/response/dataSchema/items"}`, a JSON pointer into the same result. With `actions` set, the first requested action keeps the schema inline, so a subset never points at an action you left out. Typical size for a 10-column table with three relationships: about 27 KB for everything, about 12 KB for `actions: ["list","create"]`.

For an agent calling the HTTP API:

1. Call `sp_api_get_api_guidance` once.
2. Call `sp_api_list_endpoints` to find a route.
3. Call `sp_api_get_endpoint` (with `actions` to keep it small) before any HTTP or Data MCP call.
4. Follow each action's `headers`, `request`, `response` and `guidance`. A body-less GET/DELETE has `payload: null`.

Custom RPC functions include their `querySchema`, `payloadSchema` and `responseSchema`; the guidance says `multipart/form-data` when the payload has a `format: binary` field and otherwise a JSON body described by `request.payload`. An RPC without a schema is marked generic — agents must not invent a body.

## What the Schema Tools Tell an Agent

Every number below is read from your config, routes and registry when the tool runs, so a renamed tenant header, rpc prefix or limit changes the guidance with it. Features you have not enabled are left out.

**Per endpoint (`sp_api_get_endpoint`)**

| Content | Where |
|---|---|
| `Authorization: Bearer <token>` and the tenant header, exactly when the action needs them (a public action has no `headers` block) | `actions.*.headers` |
| The throttle group and its limit (`api-reads`, `api-writes`, `api-functions`; `limit` per minute unless `perSeconds` is given) | `actions.*.rateLimit` |
| Create/update schemas that list what a client may send: writable columns, every writable relationship alias, **no** timestamps, `deleted_at`, tenant column or userstamps; `required` for create | `actions.create.request.payload`, `fields[].required` |
| Correct types: every Laravel integer type is `integer`; `date`, `date-time`, `uuid` formats | schemas |
| `search: {enabled, columns}` and the live `limit_max` / `per_page_max` | `actions.list` |
| `maxItems` (`bulk_max`) and, when a queue is configured, the `async` switches | bulk actions |
| Per field, exactly the operators the filter engine accepts for the column type **and the current database driver** | `filters[].operators` |
| Writable-include shape that really writes, plus the related table, `pivotTable`, `relatedPivotKey`, `pivotFields` for many-to-many | `includes[]` |
| Whether column-derived validation is on | `validation.defaults` |
| Resizing parameters of the attachment `view` RPC, when `attachments.read_resizing` is on | `rpcFunctions[].request.querySchema` |

**Shared (`sp_api_get_api_guidance`)**

| Block | Content |
|---|---|
| `headers` | Bearer token, the tenant header (when tenancy is on), content types |
| `querySyntax` | Filters, relationship filters (`items.qty=gt.1`), `not.`, `(any)`/`(all)` modifiers, grouped logic, `search`, `with_trashed`/`only_trashed`, `select` vs `with` |
| `operators` | The operator catalogue with syntax and an example, for your driver (the `fts` family and range/array operators appear on PostgreSQL only; `regex`/`match` on MySQL, MariaDB and PostgreSQL) |
| `pagination` | `limit`, `page`/`per_page`, cursors, `skip_total`/`add_total`, the live maximums |
| `errors` | HTTP status and `error_code` for 401, 403, 404, 422 (validation, with the `errors` shape), 422 (tenant header missing) and 429, plus MCP `-32001`, `-32002`, `-32601` |
| `rateLimits` | The three groups with their limits and the "wait `Retry-After`" rule |
| `nestedWrites` | `_delete` needs `id`; one transaction; child permissions; bare ids; no audit rows for nested changes |
| `docs` | The OpenAPI and `llms.txt` URLs (omitted when docs are private or not registered) |
| `realtime` | The private channel, event name and tables (only with `record.broadcast_events`) |
| `modules` | Present only when a built-in module is enabled: recipes for the enabled modules — **audit** (record history, field timeline/stats), **permissions** (`{action}:{pmsName}`, `viewOwn`, attaching permissions to roles, assigning roles when your users table declares a `roles` relationship), **attachments** (multipart upload, link by `attachment_ids` or `record_type`/`record_id`, view/download URLs) — built from the module's registered routes |
| `recommendations` | Performance advice with your real numbers; rules that do not apply to your database or queue are omitted |
| `references`, `validation` | How to read `$ref`, and what column-derived validation means |

## Connecting Clients

Replace the host and token. The Schema MCP needs only a token; the Data MCP needs a user credential.

**Claude Code**

```bash
# Schema MCP (frontend discovery)
claude mcp add --transport http sp-api-schema http://localhost:8000/api/v1/mcp/schema

# Data MCP over HTTP with a user token
claude mcp add --transport http sp-api https://api.example.com/api/v1/mcp \
  --header "Authorization: Bearer $USER_TOKEN" --header "X-Tenant-ID: 42"

# Data MCP over stdio (local backend)
claude mcp add sp-api -- php /path/to/artisan sp-laravel-api:mcp --tenant=42
```

(`POST /api/v1/mcp` is the Streamable HTTP endpoint of the `laravel` driver; on `legacy` use `/api/v1/mcp/message`.)

**Cursor** (`.cursor/mcp.json`)

```json
{
  "mcpServers": {
    "sp-api-schema": { "url": "http://localhost:8000/api/v1/mcp/schema" },
    "sp-api": {
      "url": "https://api.example.com/api/v1/mcp",
      "headers": { "Authorization": "Bearer YOUR_USER_TOKEN", "X-Tenant-ID": "42" }
    }
  }
}
```

**opencode** (`.opencode/opencode.json`)

```json
{ "mcp": { "sp-api-schema": { "type": "remote", "url": "https://api.example.com/api/v1/mcp/schema", "headers": { "Authorization": "Bearer YOUR_MCP_TOKEN" } } } }
```

**claude.ai connector / ChatGPT custom connector** — both need OAuth discovery, so use the `laravel` driver with `SP_MCP_OAUTH=true` and Passport (see [OAuth for connector clients](#oauth-for-connector-clients-opt-in)). In the connector dialog enter `https://api.example.com/api/v1/mcp` as the server URL; the client finds `/.well-known/oauth-protected-resource`, registers itself at `/oauth/register` and sends the user through your Passport login. Do not use the Schema MCP token for connectors.

**Inspect and debug** (`laravel` driver):

```bash
php artisan mcp:inspector sp-laravel-api          # the Data MCP over stdio
php artisan mcp:inspector sp-laravel-api-schema   # the Schema MCP over stdio
php artisan mcp:inspector api/v1/mcp              # the HTTP route (path without the leading slash)
```

## Use Cases

### Use Case 1: Local AI IDE integration (stdio)

You develop a frontend in Cursor or Claude Code and want the AI to read real data from your local backend. Add the stdio server (`php artisan sp-laravel-api:mcp --tenant=42`, see above) and ask: *"Check the `customers` schema and show me the latest 3 customers."* The AI reads `schema://customers`, then calls `list_customers` with `{"limit": 3, "order": "desc"}`. A console process has no tenant header, so pass `--tenant`; without it tenant-scoped tables are refused.

### Use Case 2: Remote web AI agents (HTTP)

An AI assistant inside your SaaS acts on a user's behalf. Point it at the Data MCP with the user's Bearer token (and tenant header):

```http
POST /api/v1/mcp/message
Authorization: Bearer {user_token}
X-Tenant-ID: 42

{ "jsonrpc": "2.0", "id": 1, "method": "tools/call",
  "params": { "name": "create_invoices", "arguments": { "payload": { "ref_number": "INV-1", "customer_id": 123 } } } }
```

Because the request carries the user's token, the tenant, the user's permissions, `viewOwn` scoping, your triggers and validators all apply.

### Use Case 3: Frontend AI agent — API schema discovery

The Schema MCP (`POST /api/v1/mcp/schema`) gives a frontend agent exactly what it needs on demand instead of a 50K-token OpenAPI file.

**`sp_api_get_endpoint` for `invoices` with `actions: ["create"]` (trimmed):**

```json
{
  "name": "invoices", "table": "invoices", "primaryKey": "id", "softDeletes": true, "hasTenantId": false,
  "actions": {
    "create": {
      "method": "POST", "uri": "/api/v1/invoices",
      "headers": { "Authorization": "Bearer <token>" },
      "rateLimit": { "group": "api-writes", "limit": 100 },
      "request": {
        "payload": {
          "type": "object",
          "properties": {
            "ref_number": { "type": "string" },
            "customer_id": { "type": "integer" },
            "status": { "type": "string" },
            "total_amount": { "type": "number" },
            "issued_at": { "type": "string", "format": "date" },
            "items": { "type": "array", "items": { "type": "object" } },
            "tags": { "type": "array", "items": { "type": ["object", "integer", "string"] } }
          },
          "additionalProperties": false,
          "required": ["ref_number", "customer_id"]
        }
      },
      "guidance": "Send only documented writeable fields in the JSON request body."
    }
  },
  "filters": [
    { "field": "ref_number", "operators": ["eq", "neq", "in", "not_in", "like", "not_like", "ilike", "contains", "starts_with", "ends_with", "is", "is_not", "empty", "not_empty"] },
    { "field": "status", "operators": { "$ref": "#/filters/0/operators" } }
  ],
  "includes": [
    { "name": "tags", "type": "belongsToMany", "table": "tags", "pivotTable": "invoice_tag", "relatedPivotKey": "tag_id", "pivotFields": ["note"], "writable": true,
      "payloadHint": "\"tags\": [{\"id\":1},{\"id\":2,\"note\":\"example\"},{\"name\":\"example\"},{\"id\":5,\"_delete\":true}] — in the parent's create/update payload. {\"id\": N} attaches (bare ids too); pivot fields update the pivot; no id creates a row; \"_delete\": true detaches. Omitted links are kept." }
  ]
}
```

**What the agent learns:**

| Knowledge | Source |
|---|---|
| Every route, method and URI (CRUD, upsert, restore, force-delete, bulk, RPCs) | `sp_api_list_endpoints`, `sp_api_get_endpoint` → `actions`, `rpcFunctions` |
| Which fields it may send, which are required | `actions.create.request.payload`, `fields[].required` |
| Filters and operators per field | `filters[]`, guidance `operators` |
| Relationship structure and nested-write shapes | `includes[]` |
| Required permissions | `permissions`, `sp_api_list_permissions` |

## Tenant Isolation

The tenant for every data tool call is resolved from the **request**, never from the tool arguments:

1. `resolved_tenant_id` on the request (set by your middleware), then
2. `record_context['tenant_id']`, then
3. the configured tenant header (`record.tenant_header`, default `X-Tenant-ID`).

Enforced by `ToolExecutor` on both drivers:

- **Tool arguments are not a source of tenant identity.** A `tenantId` argument is accepted only when it matches the resolved tenant, and refused otherwise.
- **A tenant-scoped table with no resolvable tenant refuses the call.** It never widens to every tenant.
- **Tables declaring `hasTenantId: false` need no tenant of their own**, but relationships from such a table into a tenant-scoped one are scoped by the request tenant, and nested writes under them are scoped and permission-checked per child table.

```text
tools/call list_invoices  +  X-Tenant-ID: 42   -> only tenant 42's rows
tools/call list_invoices  +  arguments.tenantId: 7  (request says 42)  -> refused
tools/call list_invoices  +  no tenant anywhere  -> refused
```

> **Deployment note:** because the tenant rides on the request, each company's MCP client configuration must carry that company's tenant context (header or a credential your middleware maps to one). A single shared static token with no tenant binding is **not** sufficient for multi-tenant use.

> **Stdio:** a console process has no request, so `php artisan sp-laravel-api:mcp` cannot see a tenant header. Pass `--tenant=<id>`; without it, tenant-scoped tables are refused.

## Security & Authentication

The MCP integration is not a backdoor. It uses the security layers already defined in `sp-laravel-api`.

### Data MCP

1. **`authorizeAction()` enforcement**: every tool call passes the same permission decision as the REST API (including `super_admin_callback`, a custom `record.authorization` handler, `viewOwn:*`, and Laravel's Gate with the built-in permission module). Without the permission the call fails with `-32002 Forbidden`; with the `laravel` driver a tool the user may not use is also left out of `tools/list` (and is still refused if called).
2. **Tenant scoping**: see [Tenant Isolation](#tenant-isolation).
3. **Nested writes** are authorized per child table; see [Nested & Bulk Operations](/guide/api-nested-and-bulk-operations).
4. **Triggers and validators**: `beforeCreate`, `afterUpdate` and your validators run exactly as over HTTP.
5. **Authentication**: `record.mcp.middleware` (default `['api', 'auth:sanctum']`), or Passport with `record.mcp.oauth`.

### Schema MCP (`POST /api/v1/mcp/schema`)

The Schema MCP exposes **no data** — only endpoint metadata. The `VerifySchemaMcpToken` middleware compares the token in constant time on both drivers.

| Environment | `SP_API_MCP_TOKEN` set? | Behavior |
|---|---|---|
| `local` | No | Open access |
| `local` | Yes | Requires `Authorization: Bearer <token>` |
| other | No | **401** — a token is mandatory |
| other | Yes | Requires `Authorization: Bearer <token>` |

```bash
php -r "echo bin2hex(random_bytes(32));"   # generate a strong token
SP_API_MCP_ENABLED=false                   # → the route is not registered (404)
```

## Upgrade Notes

- **Nothing to do to keep working.** The default driver is still `legacy`, URLs, headers, tokens, error codes and tool names are unchanged.
- **`GET …/mcp/sse` now answers `405`** (it never worked). Use `POST`.
- **`record.mcp.route_prefix` is deprecated and has no effect** (it never moved a route). Advertised URLs are generated from the real route names.
- **The `legacy` driver is deprecated** and will be removed in 0.6.0. Plan the switch to `laravel` (differences above).
- **Schema tool content changed** (all additive or corrections): integer columns are `integer`, create/update payload schemas list relationship aliases and `required` and no longer offer `id`, timestamps, tenant or userstamp columns, belongsToMany includes report the related table as `table` (the pivot is `pivotTable`), `payloadHint` shows shapes that write, `filters[].operators` lists the real operator set for the column type and driver, `queryParameters` starts with `{column}={operator}.{value}` instead of `filters`, and repeated schemas are `$ref`s. Clients should follow `$ref` (JSON pointer) when they read `response.dataSchema`, `request.payload` or `filters[].operators`.
- **`content[0].text` is compact JSON** (was pretty-printed). `structuredContent` is unchanged.
- **MCP data tools honour the table's `canRead` / `canCreate` / `canUpdate` / `canDelete` flags** (security fix): a tool for an action the table disallows is no longer listed and answers `-32601`. The built-in audit, permission and attachment tables lose the write tools their config never allowed.
- **Nested child writes need the child table's permission** (security fix): grant `create:`/`update:`/`delete:` + the child's `pmsName` to users who write children through a parent. Bare ids in a many-to-many array now attach; in a hasMany array they are a `422`.
- **Using `laravel/mcp`**: `composer require laravel/mcp`, set `SP_MCP_DRIVER=laravel`. For claude.ai/ChatGPT also `composer require laravel/passport`, `SP_MCP_OAUTH=true` and `auth:api` middleware.

## Developing and Testing

- **Driver matrix**: the MCP suites run on both drivers — `vendor/bin/phpunit --filter Mcp` (legacy) and `SP_MCP_DRIVER=laravel vendor/bin/phpunit --filter Mcp`. A few assertions pin details only the legacy driver produces and skip themselves under `laravel`, with the reason in the skip message.
- **Stdio**: the stdio tests start `vendor/bin/testbench sp-laravel-api:mcp` in a subprocess, configured by `testbench.yaml` in the package root.
- **OAuth**: the OAuth tests use a Passport stand-in. Before relying on it, check once against a real Passport install: with `SP_MCP_DRIVER=laravel`, `SP_MCP_OAUTH=true` and `auth:api`, `GET /.well-known/oauth-protected-resource` returns JSON, `POST /oauth/register` registers a client, and an unauthenticated `POST /api/v1/mcp` answers `401` with a `WWW-Authenticate` header that names the metadata URL.
- **Content tests**: `McpEndpointContentTest`, `McpEndpointContextTest`, `McpGuidanceReferenceTest`, `McpGuidanceErrorsTest` (checks the error table against real responses), `McpModuleRecipesTest`, `McpFollowTheHintTest` (sends every advertised nested-write hint to the real API), `McpAdvertisedOperatorsTest` (runs every advertised operator) and `McpResponseSizeTest` (the byte budgets).
