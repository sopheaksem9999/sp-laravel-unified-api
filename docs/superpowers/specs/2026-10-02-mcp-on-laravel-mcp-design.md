---
title: "MCP on Laravel MCP"
description: "Serve the package's Data and Schema MCP servers through the official laravel/mcp package, keep the package's tool catalog, tenant isolation and authorization, correct and complete the guidance agents receive, and close a nested-write authorization bypass."
keywords:
  - mcp
  - laravel/mcp
  - model context protocol
  - streamable http
  - oauth
  - tool annotations
  - agent guidance
  - nested writes
---

# MCP on Laravel MCP — Design

> **Status:** Approved — implemented (phases S and 0–5, 2026-10-02); awaiting the maintainer's commit, a final review and a manual check against a real Passport install
> **Date:** 2026-10-02
> **Package:** `sopheak/sp-laravel-api` (0.5.03)
> **Depends on:** `laravel/mcp` ^1.0.1 (optional)

## 1. Goal

Serve the package's two MCP servers — Data MCP (CRUD over configured tables)
and Schema MCP (API discovery, never data) — through the official
`laravel/mcp` package. The protocol, transports, OAuth, the Inspector and
test helpers then come from Laravel. The package keeps what only it can provide:
the generated tool catalog, tenant isolation, permission checks, `viewOwn`, and
hidden-column sanitisation.

Success means any current MCP client works against the package with no
package-specific workarounds:

- Claude Code, Cursor and other stdio clients;
- HTTP clients that send a bearer token;
- remote connectors that require OAuth discovery.

A second goal is that an agent which follows the Schema MCP's guidance
exactly produces a correct API call. Today several hints are wrong or missing
(§2.1), and one of the hints exposes an authorization bypass in nested writes
(§2.2).

**No existing client URL or configuration changes.** Every URL a client uses
today — for example `/api/v1/mcp/schema` and `/api/v1/mcp/message` — keeps its
path, authentication and response contract (§7.1). The new protocol features
arrive only when the app owner opts in.

## 2. Background — the module today

The module is a hand-written JSON-RPC server (`McpServerService`,
`McpHttpController`, `ApiSchemaMcpController`, `McpServerCommand`).
`laravel/mcp` is not a dependency. A probe against `/api/mcp/message` on
2026-10-02 showed:

| Area | Today | Effect on clients |
|---|---|---|
| Protocol version | `initialize` always answers `2024-11-05`, whatever the client asks for, yet tools advertise `outputSchema` / `structuredContent` from a later spec | Strict clients reject or downgrade |
| `ping` | `-32601 Method not found` | The spec requires a reply; some clients treat this as a dead server |
| HTTP transport | Plain JSON POST; no `Mcp-Session-Id`; notifications return 204, not 202 | Not Streamable HTTP |
| `GET /api/mcp/sse` | Advertises `/mcp/message`, but the route is `/api/mcp/message` (404); never sends responses on the stream; `while (true)` keep-alive holds a PHP worker for as long as the client stays connected | Unusable, and harmful to the server |
| Tool metadata | No `title`, no annotations — `delete_*` is not marked destructive | Clients cannot warn before a destructive call |
| Prompts | `prompts/list` → Method not found | — |
| Auth | Data: app middleware (`auth:sanctum`). Schema: shared token compared with `!==` (not constant-time) | No OAuth discovery, so claude.ai and ChatGPT connectors cannot connect |
| Testing | Raw HTTP tests only | No Inspector, no `Server::tool()` assertions |

What works and must not regress:

- tenant isolation (`resolveToolTenantId`, the stdio `--tenant` option);
- `authorizeAction` through `PermissionUtils`;
- `viewOwn` scoping;
- hidden-column stripping;
- `structuredContent` with `outputSchema`;
- the Schema tools (`sp_api_list_endpoints`, `sp_api_get_endpoint`,
  `sp_api_list_permissions`, `sp_api_get_api_guidance`);
- the `schema://{table}` resources;
- `record.mcp.read_only`.

### 2.1 Schema MCP content review (2026-10-02)

Every schema tool was called against a test app with relationships (belongsTo,
hasMany, belongsToMany) and every built-in module: audit, roles/permissions,
attachments and webhooks. Each hint was then sent to the real API. Content
that is already good: each endpoint lists its 13 actions, fields, per-field
filter operators, sorts, includes and permissions. The guidance explains
`select`, `limit`, page and cursor pagination, `or=()` and nested-write basics,
and the built-in modules appear with their RPC functions.

**Wrong guidance.** An agent that follows it fails, usually silently.

| # | Finding | Evidence |
|---|---|---|
| W1 | Many-to-many and hasMany `payloadHint`s say `"tags": [1, {"id": 2}, …]`, and the guidance example is `"roles": [1, 3, 5]`. The nested-write processors skip every item that is not an object | `PUT /notes/1 {tags: [1, 2]}` → 200 with 0 pivot rows. `[{id: 1}, {id: 2}]` → 2 rows |
| W2 | The guidance says many-to-many does "sync/attach". It never detaches links that are left out | `processBelongsToManyOperation()` |
| W3 | The `sp_attachments` `upload` RPC says "Send a JSON request body". An upload needs `multipart/form-data`. The guidance names a `payloadSchema` key, but the key is `request.payload` | `functionCallContext()` |
| W4 | Create and update payload schemas set `additionalProperties: false` and omit relationship aliases, so a schema-following agent won't nest `items`, which contradicts the include hints. They also advertise `id`, `tenant_id`, `created_at`, `updated_at` and `deleted_at` as writable, and mark no field as required | `endpoint_invoices` output |
| W5 | `bigInteger` and the other Laravel integer types map to `"string"`: `customer_id` is advertised as a string | `jsonSchemaType()` only matches `integer`, `bigint`, `smallint`, `tinyint`, `int` and `unsigned` |
| W6 | belongsToMany includes report the **pivot** table (`invoice_tag`, `sp_role_permissions`) as `table`. Looking it up returns "Endpoint not found" | `endpoint_invoices`, `endpoint_sp_roles` |

**Rules an agent needs but is never told.**

| # | Missing |
|---|---|
| M1 | Headers. There is no mention of `Authorization: Bearer` or of the tenant header (`X-Tenant-ID`), although `hasTenantId` tables refuse requests without it |
| M2 | Query features: relationship filters (`items.qty=gt.1`, `customer.name=ilike.x`), the `not.` prefix, the `(any)` / `(all)` modifiers, `search`, and `with_trashed` / `only_trashed`. It also isn't said that `like` already matches substrings, so the `like.%acme%` example is redundant. `filters` is listed as a query parameter name, though filters are one query key per column. `with` and `select` are not distinguished |
| M3 | The error catalogue: HTTP status and `error_code` for 401, 403, 404 (including by-id rows outside the caller's scope), 422 validation (with the `errors` shape), 422 for a missing tenant header, and 429. Also the MCP JSON-RPC codes `-32001` and `-32002` |
| M4 | Nested-write rules: `_delete` needs the related `id`; attaching a record the caller can't read → 422; the write is atomic; nested child changes are not recorded in the audit log |
| M5 | Task recipes for the built-in modules. **Audit:** query one record's history (`entity_type=eq.invoices&entity_id=eq.1&order=desc`); `field-timeline` / `field-stats` take the table name; the limits of searching the JSON columns. **Roles/permissions:** the permission name format `{action}:{pmsName}`, what `viewOwn:` does, how permissions are attached to a role, and how roles are assigned to users (the package exposes no endpoint for that). `sp_api_list_permissions` returns bare names only. **Attachments:** the upload-then-link flow (`attachment_ids`), or upload with `record_type` / `record_id`; `record_type` is the table name; visibility values; view and download URLs |

**Capabilities the Schema MCP doesn't expose at all**, so an agent can't use
them. Found by comparing every client-facing feature in `docs/guide` with the
tool output.

| # | Capability | Today |
|---|---|---|
| C1 | Filter operators | `filterOperatorsForType()` advertises 4–7 operators per field. The filter engine accepts about 35: `eq`, `neq`, `gt`, `gte`, `lt`, `lte`, `in`, `not_in`, `between`, `not_between`, `like`, `ilike`, `not_like`, `contains`, `starts_with`, `ends_with`, `is` / `is_not`; `date_eq`, `date_gt`, `date_gte`, `date_lt`, `date_lte`; `fts`, `plfts`, `phfts`, `wfts`; `match`, `imatch`, `regex`; arrays `cs`, `cd`, `ov`; ranges `sl`, `sr`, `nxl`, `nxr`, `adj`. The guidance's own examples (`like.%acme%`, `is.null`) use operators missing from every per-field list, and W5 leaves `bigInteger` columns with only `eq`, `neq`, `in` and `not_in` |
| C2 | Pagination options | `skip_total=true` / `total=false`, `add_total`, `boundary_cursors`, `cursor_column`, `direction`, and the limits `record.per_page_max` / `record.limit_max` (default 10000) are never mentioned |
| C3 | Search per table | Each table's `searchable` columns (including `relationship.column`) are not exposed, so an agent can't tell whether `?search=` does anything on a table |
| C4 | Async bulk | `?async=true` or the `X-Async-Process` header queues a bulk create, update or delete and returns 202. `record.bulk_max` (1000) appears only in the `bulkMixed` note |
| C5 | Rate limits | The throttle groups `api-reads`, `api-writes` and `api-functions`, the 429 response and `Retry-After` are never mentioned |
| C6 | Attachment image resizing | The view RPC's `w`, `h`, `fit`, `format` and `size_name` parameters (when `attachments.read_resizing` is on) are not in its `querySchema` |
| C7 | Docs fallback | `GET /{api}/docs/openapi.json` and `/{api}/docs/llms.txt` exist, but nothing points an agent to them |
| C8 | Realtime broadcast | With `record.broadcast_events` on, mutations broadcast on the private channel `tenant.{tenantId}` (or `tenant.global` when there is no tenant) as `{table}.{action}` (`RecordMutated`), for the tables in `record.broadcast_tables`. None of this is exposed |
| C9 | Default validation | The `validation` list shows only custom validators. The schema-derived rules (column type and nullability) that apply without any validator are not stated, so an agent discovers them only through 422s |

**Performance guidance.** The only recommendation is "prefer `limit` over
`per_page`". Nothing tells an agent:
- to select only the columns it needs;
- what includes cost;
- when to skip totals;
- to use bulk, `async` or `upsert` instead of many single calls;
- to prefer `fts` / `search` over `like` on large text columns;
- how to respect rate limits.

**Size.** Every tool result carries the same data twice: `structuredContent`,
plus a pretty-printed JSON copy in `content[0].text`. `sp_api_get_endpoint
invoices` is about 62 KB (17 KB structured, 45 KB text) — roughly 15k tokens
for one table. `sp_api_list_endpoints` is 93 KB for 13 tables.

### 2.2 Security finding: nested writes bypass child-table authorization

This was found while checking W1, and is not specific to MCP: the same code
serves HTTP. `RelationshipResolverUtils` checks only the relationship's
`allowCreate` / `allowUpdate` / `allowDelete` flags. It never checks the child
table's permissions or its `canCreate` / `canUpdate` / `canDelete` settings;
the per-item permission check in `processRelatedData()` is commented out.

Probe: the user may update `invoices` but holds no `invoice_item` permission,
and `invoice_items` is configured `canCreate: false, canDelete: false`.

| Request | Result |
|---|---|
| `POST /invoice_items` | 404 (correctly refused) |
| `PUT /invoices/1` with `items: [{product: "NEW"}, {id: 1, product: "EDIT"}]` | 200 — item created, item 1 edited |
| `PUT /invoices/1` with `items: [{id: 1, _delete: true}]` | 200 — item deleted |

`sp_roles.permissions` goes through the same code, so a user with
`update:role` can presumably create `sp_permissions` rows although that table
is `canCreate: false`. That case was not probed separately.

> **Fixed in phase S** — see docs/superpowers/plans/2026-10-02-nested-write-authorization.md.

## 3. Facts about `laravel/mcp` this design relies on

Each fact below was checked in the v1.0.1 source, not only the docs.

- **Platform requirements:** PHP `^8.2` and `illuminate/*`
  `^11.45.3|^12.41.1|^13.0`. These are compatible with this package
  (`php ^8.2`, `laravel/framework ^12.0|^13.0`), but they raise the effective
  Laravel 12 floor to 12.41.1 for apps that install it.
- **Protocol versions:** `initialize` answers `2025-11-25` or `2025-06-18`
  (`Enums\ProtocolVersion::initializeSupported()`); `serverSupported()` is
  only `2026-07-28`. A client that asks for `2024-11-05` or `2025-03-26` is
  answered `2025-11-25` and decides whether to continue (verified in phase 0).
  The `legacy` driver answers `2024-11-05` to everyone.
- **Tool errors:** the stock `tools/call` handler (`CallTool`, an `Errable`)
  catches anything a tool throws and returns an `isError` tool result; outside
  `app.debug` the message becomes "An internal server error occurred." A thrown
  `JsonRpcException` therefore never reaches the client as a JSON-RPC error
  (verified in phase 0). A custom method registered with `Server::addMethod()`
  can keep the original codes: `CallTool` and `ToolInvoker` are not `final`.
- **HTTP status of errors:** `HttpTransport` answers a JSON-RPC error with an
  HTTP error status — `-32601` → 404, `-32603` → 500, anything else
  (including `-32001` and `-32002`) → 400 — where the `legacy` driver answers
  every JSON-RPC error with HTTP 200. The response body (`error.code`,
  `error.message`, `id`) is identical; only the status differs (found in phase
  2). The package keeps `laravel/mcp`'s behaviour.
- **Pagination:** `tools/list` returns 15 tools per page (maximum 50) by
  default, while the `legacy` driver returns the whole catalog at once. A client
  that ignores `nextCursor` would silently miss tools, so the package's servers
  raise the page size to 500 (found in phase 2).
- **Provider:** the tool `Request` is populated from the JSON-RPC arguments by a
  container callback in `Laravel\Mcp\Server\McpServiceProvider`. Without that
  provider the arguments are empty. Apps get it by package auto-discovery;
  Testbench and apps that disable discovery do not.
- **Tool instances:** `ServerContext::tools()` accepts `Tool|string`, so a
  server can register tool *instances* built at runtime, such as five per
  configured table.
- **Per-request server:** `Registrar::startServer()` makes a new server for
  each HTTP request and calls `boot()`, so the tool list can be built from
  config on each request.
- **Overridable tool shape:** `Tool::toArray()` and `Primitive::name()`,
  `title()` and `description()` are public and overridable.
  `eligibleForRegistration()` calls an optional `shouldRegister()` through the
  container.
- **Annotations:** tool annotations (`IsReadOnly`, `IsDestructive`,
  `IsIdempotent`, `IsOpenWorld`) are read from class attributes
  (`HasAnnotations::annotations()`).
- **Legacy clients:** `ValidateMcpHeaders` lets a request through untouched
  when it carries no protocol `_meta` (`JsonRpcRequest::isLegacy()`). Today's
  plain JSON-RPC clients are therefore not rejected for missing headers.
- **HTTP methods:** `Mcp::web()` registers `POST` and answers `GET` and
  `DELETE` with `405 Allow: POST`.
- **OAuth:** `Mcp::oauthRoutes()` needs Laravel Passport and publishes
  `/.well-known/oauth-protected-resource` and authorization-server metadata.

## 4. Scope

**In scope**

1. The tool catalog and tool execution move out of `McpServerService` into
   transport-free classes.
2. New `laravel/mcp` servers serve the Data and Schema MCP from that catalog
   over Streamable HTTP and stdio.
3. Tool titles and annotations.
4. Optionally, hiding tools the caller cannot use (see §12).
5. Schema-MCP token checking in a middleware, compared in constant time.
6. Opt-in OAuth 2.1 through Passport.
7. Replacing the broken SSE endpoint.
8. Docs, changelog and client configuration examples.
9. **Nested-write authorization (§6.7).** A security fix, shipped first and on
   its own release, independent of MCP.
10. **Agent guidance content (§6.6).** Corrections W1–W6, additions M1–M5,
    capability coverage C1–C9, performance recommendations (P), and output
    size. These live in `ToolCatalog`, so both drivers get them.

**Out of scope (YAGNI for this cycle)**

- Prompts.
- MCP Apps.
- The MCP *client*.
- `ToolSearch` catalogs (see §12).
- Resource templates.
- Icons and cache hints.
- Removing the legacy driver, which is a later minor release.

## 5. Architecture

```
            ┌──────────────── transport-free core (always loaded) ───────────────┐
            │  Mcp\ToolCatalog   — which tools exist + their full definition       │
            │  Mcp\ToolExecutor  — run one tool call: tenant → authorize → execute │
            └───────────────▲───────────────────────────────▲──────────────────────┘
                            │                               │
   legacy driver (today's URLs, unchanged JSON-RPC)   laravel driver (needs laravel/mcp)
   McpServerService → thin JSON-RPC adapter            Mcp\Servers\DataServer / SchemaServer
   McpHttpController, ApiSchemaMcpController           Mcp\Tools\CatalogTool (one per definition)
   McpServerCommand (stdio)                            Mcp::web(...) / Mcp::local(...)
```

### 5.1 Units

| Unit | Responsibility | Depends on |
|---|---|---|
| `Sopheak\Core\Mcp\ToolDefinition` | Value object describing one tool. Fields: `name`, `title`, `description`, `inputSchema` and `outputSchema` (raw JSON Schema arrays), `annotations` (`readOnly`, `destructive`, `idempotent`, `openWorld`), `table` (`?string`), and `action` (`list`, `read`, `create`, `update`, `delete`, or `schema`) | — |
| `Sopheak\Core\Mcp\ToolCatalog` | `data(): list<ToolDefinition>` and `schema(): list<ToolDefinition>`, built from `RecordConfigService`. Honours `record.mcp.read_only`. Owns every tool name, description and schema that `handleToolsList()` builds today, moved byte-for-byte. Also `resources(): list<array>` for `schema://{table}` | `RecordConfigService`, `SchemaRegistryUtils` |
| `Sopheak\Core\Mcp\ToolExecutor` | `call(string $name, array $args, ?ToolContext $context = null): ToolResult`. Moved from `handleToolsCall()`, `resolveToolTenantId()` and `authorizeAction()`. Also `readResource(string $uri)`. Both MCP drivers pass `null`. The context exists for the [AI SDK tools](/superpowers/specs/2026-10-02-ai-sdk-record-tools-design), whose queued agents have no request | `RecordService`, `PermissionUtils`, `RecordUtils` |
| `Sopheak\Core\Mcp\ToolResult` | Value object with `structuredContent` (`array`), `legacyContent` (`mixed`) and `isError` (`bool`). The same shape `toolResult()` / `dataToolResult()` produce today | — |
| `Sopheak\Core\Mcp\ToolError` | Exception carrying the JSON-RPC code (`-32001`, `-32002`, …) the legacy adapter maps today | — |
| `McpServerService` (kept) | Legacy JSON-RPC adapter: routes `initialize`, `tools/*` and `resources/*` to the catalog and executor. Its public surface and wire output are unchanged | `ToolCatalog`, `ToolExecutor` |
| `Sopheak\Core\Mcp\Servers\DataServer` (`extends Laravel\Mcp\Server`) | In `boot()`, wraps each `ToolCatalog::data()` definition in a `CatalogTool`, and each resource in a `CatalogResource` | `laravel/mcp` |
| `Sopheak\Core\Mcp\Servers\SchemaServer` | The same, for `ToolCatalog::schema()` | `laravel/mcp` |
| `Sopheak\Core\Mcp\Tools\CatalogTool` (`extends Laravel\Mcp\Server\Tool`) | Constructed with a `ToolDefinition`. Overrides `name()`, `title()`, `description()` and `toArray()`, emitting the definition's raw schemas and annotations. This is needed because the catalog's JSON Schema uses union types such as `["string","integer"]`, and annotations differ per instance rather than per class. `handle(Request)` calls `ToolExecutor` and returns `Response::make(Response::text(json))->withStructuredContent(...)`, or `Response::error(...)`. `shouldRegister()` applies the visibility rule (§6.3) | `ToolExecutor` |
| `Sopheak\Core\Mcp\Resources\CatalogResource` (`extends Laravel\Mcp\Server\Resource`) | One per `schema://{table}` | `ToolExecutor` |
| `Sopheak\Core\Http\Middleware\VerifySchemaMcpToken` | The token check moved out of `ApiSchemaMcpController`, using `hash_equals`. Used by both drivers | — |

`Sopheak\Core\Mcp\CatalogCallTool` (`extends Laravel\Mcp\Server\Methods\CallTool`)
and `CatalogToolInvoker` (`extends Laravel\Mcp\Server\ToolInvoker`) preserve
the error codes (§6.4). The package provider registers
`Laravel\Mcp\Server\McpServiceProvider` when the `laravel` driver is selected
(registering it twice is harmless), so tool arguments are populated even when
package auto-discovery is off.

The `Sopheak\Core\Mcp\Servers|Tools|Resources` classes are only referenced when
`class_exists(\Laravel\Mcp\Server::class)`. Without `laravel/mcp` they are never
autoloaded, so the package keeps working when it isn't installed.

### 5.2 Driver selection

New config key `record.mcp.driver` (`SP_MCP_DRIVER`):

| Value | Behaviour |
|---|---|
| `legacy` (default) | Today's implementation, over the shared core. Deprecated once `laravel` is stable |
| `laravel` | `laravel/mcp` servers. Boot throws a clear `RuntimeException` if the package is missing |

The opt-in is explicit, not detected from whether `laravel/mcp` is installed.
`laravel/boost` (v2.10.1) requires `laravel/mcp`, and apps usually install
Boost as a dev dependency. Auto-detection would therefore switch drivers in
development but not in production (`composer install --no-dev`): the same
client would see different behaviour in each environment.

### 5.3 Endpoints and transports

All routes are registered inside the existing
`Route::prefix(apiPrefix())->middleware(['api', 'request.id'])` group. The
route constraint already reserves the `mcp` segment from the dynamic `{table}`
routes (routes/api.php, the `mcp` exclusion).

| Endpoint | `legacy` driver | `laravel` driver |
|---|---|---|
| Data, Streamable HTTP | — | **New:** `POST /{api}/mcp`, with `record.mcp.middleware` (`GET`/`DELETE` → 405) |
| Data, JSON-RPC `POST /{api}/mcp/message` | Served (today) | **Served by `DataServer`** at the same path, so existing clients keep working. Deprecated in the docs in favour of the path above |
| `GET /{api}/mcp/sse` | **Replaced:** `405 Allow: POST` | `405 Allow: POST` |
| Schema `POST /{api}/mcp/schema` | Served (today) | **Served by `SchemaServer`** at the same path, with `VerifySchemaMcpToken` and `throttle:api-reads` |
| stdio | `php artisan sp-laravel-api:mcp [--tenant=]` | Same command. It binds `--tenant` onto the console request (unchanged), then starts `DataServer` over `laravel/mcp`'s stdio transport. `Mcp::local('sp-laravel-api', DataServer::class)` is also registered, for `mcp:start` / `mcp:inspector` |

`record.mcp.route_prefix` has never moved a route: routes/api.php hard-codes
`mcp`, and only `McpHttpController` (the SSE endpoint URL) and
`handleSchemaGetApiGuidance()` read the key. So an app that set
`SP_MCP_ROUTE_PREFIX=ai` has clients on `/api/v1/mcp/...`, while the guidance
tool advertises `/api/v1/ai/...`, which doesn't exist.

Making the routes follow the key would move those clients' URLs, so routes stay
at `mcp`. Instead, every advertised URL is generated from the real route names
(`route('mcp.message')` and so on), and `route_prefix` is marked deprecated with
no effect, the same treatment `restrict_to_own_records` got.

## 6. Behaviour

### 6.1 Tools

- **Names are unchanged:** `list_{table}`, `read_{table}`, `create_{table}`,
  `update_{table}`, `delete_{table}`, and the four `sp_api_*` schema tools.
  Phase 1 moves descriptions and input and output schemas byte-for-byte, so the
  extraction is provably behaviour-neutral. Phase 3 then corrects their content
  (§6.6).
- **New `title`:** for example `List invoices` or `Delete invoices`, and
  `List API endpoints` for the schema tools.
- **New annotations**, emitted by both drivers. `openWorldHint` is `false` for
  every tool.

| Action | `readOnlyHint` | `destructiveHint` | `idempotentHint` |
|---|---|---|---|
| `list`, `read`, `sp_api_*` | true | — | — |
| `create` | false | false | false |
| `update` | false | true | true |
| `delete` | false | true | true |

`destructiveHint` and `idempotentHint` are only meaningful when `readOnlyHint`
is false. This matches the `laravel/mcp` attribute semantics.

### 6.2 Tenancy and authorization — invariants

These are unchanged and must hold on both drivers. Each is pinned by tests that
exist today.

1. **Tenant source:** the tenant comes from the request
   (`RecordUtils::resolveTenantIdFromRequest`), never from tool arguments. A
   `tenantId` argument that disagrees with it is refused. A tenant-scoped
   table with no resolvable tenant is refused, never widened.
2. **Authorization:** every data call goes through
   `PermissionUtils::isSuperAdmin()` / `userHasAnyPermission()`, and so
   through Gate (0.5.03+).
3. **Scoping and sanitising:** `OwnRecordsScope` and hidden-column stripping
   apply through `RecordService`, as they do for HTTP.
4. **Read-only mode:** `record.mcp.read_only` removes the create, update and
   delete tools from the list, and refuses them if they are called anyway.

### 6.3 Tool visibility (`laravel` driver only)

`CatalogTool::shouldRegister()` hides a data tool whose action the caller is
not authorised for. It uses the same `authorizeAction` decision, evaluated
without throwing.

- `tools/list` then shows each user only what they can do, which saves tokens
  and avoids calls doomed to fail.
- A hidden tool that is called anyway still gets `Forbidden`.
- The `legacy` driver keeps listing every tool.
- This is open question Q2 (§12).

### 6.4 Errors

Errors keep today's contract on both drivers, so clients and scripts that match
on error codes keep working:

| Failure | Response (both drivers) |
|---|---|
| Unknown table, tenant refused or mismatched (`ToolError`) | JSON-RPC error, code `-32001`, today's message |
| Forbidden | JSON-RPC error, code `-32002`, `Forbidden` |
| Validation or record errors that today return a tool result with `isError: true` | Unchanged: tool result with `isError: true` |
| Unknown method or tool | JSON-RPC error `-32601` |

On the `laravel` driver the stock `tools/call` handler cannot do this (§3), so
the package registers its own, `CatalogCallTool`, with `addMethod()`. Its
invoker rethrows `ToolError` as `JsonRpcException` with the original code,
returns `Response::error()` with the real message for other tool failures (the
same `isError` text the `legacy` driver returns), and answers an unknown tool
with `-32601 Tool not found: <name>` like the `legacy` driver. Phase 0 verified
`-32001`/`-32002` reach the client unchanged this way. Moving these errors to
`isError` tool results, which the MCP spec prefers, would change what clients
match on, so it is out of scope.

### 6.5 Authentication

- **Data MCP:** `record.mcp.middleware`, unchanged (default
  `['api', 'auth:sanctum']`).
- **OAuth (opt-in):** new `record.mcp.oauth` (`SP_MCP_OAUTH`, default `false`).
  When it is `true` and Passport is installed, the package calls
  `Mcp::oauthRoutes()` and the docs show `auth:api` middleware. This lets
  remote connectors that require OAuth discovery connect. When it is `true`
  but Passport is missing, boot throws a clear exception.
- **Schema MCP:** `VerifySchemaMcpToken` keeps today's rules, compared with
  `hash_equals`. The rules are: a token is required outside `local`; with no
  token in `local`, access is open.

### 6.6 Agent guidance content

All of this is produced by `ToolCatalog`, so both drivers emit it.

**Corrections**

| # | Change |
|---|---|
| W1 | Hints show the shapes that actually write. **hasMany / morphMany:** `[{...fields to create}, {"id": 2, ...fields to update}, {"id": 5, "_delete": true}]`. **belongsToMany / morphToMany / hasManyThrough:** `[{"id": 1}, {"id": 2, ...pivot fields}, {...fields to create}, {"id": 5, "_delete": true}]`. Fix the guidance example the same way. The processors stop dropping bare items silently: a scalar in a many-to-many or hasManyThrough array is treated as `{"id": <scalar>}` (attach). A scalar in a hasMany or morphMany array is refused with 422 naming the relationship (Q7) |
| W2 | Many-to-many wording: "attach, update pivot fields, or detach with `_delete`. Links you leave out are kept." Drop "sync" |
| W3 | An RPC whose payload has any property with `format: binary` gets the guidance "Send `multipart/form-data`; file fields are binary parts, every other field a form field". All RPC guidance refers to `request.payload` |
| W4 | Create and update payloads list each writable relationship alias (an array of objects, with a description pointing at the include's `payloadHint`). They exclude timestamps, `deleted_at`, the tenant column (stamped from the request), userstamp columns and `columnWriteDisabled`. They keep the primary key only when client-supplied ids are accepted (uuid ids). `required` lists, for create, the non-nullable columns that have no default and aren't auto-generated |
| W5 | `jsonSchemaType()` maps every Laravel integer type (`bigInteger`, `unsignedBigInteger`, `mediumInteger`, `smallInteger`, `tinyInteger`, `unsignedInteger`, increments) to `integer`. `date`, `datetime` / `timestamp` and `uuid` gain `format: date`, `date-time` and `uuid` |
| W6 | belongsToMany includes report the related table as `table`, plus `pivotTable`, `relatedPivotKey` and `pivotFields` (from `withPivot`) |

**Additions**

| # | Where | Content |
|---|---|---|
| M1 | Every action in `sp_api_get_endpoint`, plus the guidance | A `headers` block: `Authorization: Bearer <token>` when the action is not public, and `<tenantHeader>: <tenant id>` (`RecordConfigService::tenantHeader()`) when tenancy is on and the table has `hasTenantId` |
| M2 | Guidance `querySyntax`, and `list` / `read` actions | Relationship filters; `not.`; the `(any)` / `(all)` modifiers; `search`; `with_trashed` / `only_trashed` (soft-delete tables only); `like` / `ilike` match substrings; `in.(a,b)`; `between`; `is.null`. Replace the literal `filters` entry in `queryParameters` with "one query key per column: `{column}={operator}.{value}`". Say `select` chooses columns and includes, and `with` adds includes to the default columns |
| M3 | Guidance `errors` | The HTTP status / `error_code` table for 401, 403, 404, 422 (validation, with the `errors` object shape), 422 (tenant header missing) and 429. The MCP codes `-32001` / `-32002` / `-32601` |
| M4 | Guidance `nestedWrites`, and each writable include | `_delete` needs the related `id`. Attaching a record the caller can't read → 422. The parent and every child item are written in one transaction. Each child item needs the child table's own permission (§6.7). Nested child changes are not recorded in the audit log |
| M5 | Guidance `modules`: one block per **enabled** module, omitted otherwise | **audit:** history recipe; `field-timeline` / `field-stats` path parameters; JSON-column search limits. **permissions:** the name format `{action}:{pmsName}` with the action list; `viewOwn:` narrows access; attach permissions to a role with `permissions: [{"id": …}]`; assigning roles to users is only possible when the app exposes a `roles` include on its users table — otherwise the guidance says so instead of inventing an endpoint. **attachments:** upload (multipart, fields), link (`attachment_ids`) or upload with `record_type` / `record_id` (the table name), visibility values, view and download URLs |

Each module recipe is generated from the module's live config (route
prefixes, RPC names, enabled features), never hard-coded, so it cannot drift
from the routes.

**Capability coverage**

| # | Where | Content |
|---|---|---|
| C1 | Guidance `operators`, emitted once, and each field's `filters[].operators` | The guidance holds the full catalogue, each operator with its syntax and one example. Each field lists exactly the operators the filter engine accepts for its column type **and the current database driver**. The shared operator map records which drivers each operator supports — for example the `fts` family and the range operators on PostgreSQL — and the implementation confirms each driver dependency against `QueryBuilderFiltersUtils` instead of assuming it. `filterOperatorsForType()` is derived from that same map, so the two can't drift. The type normalisation from W5 applies here too |
| C2 | Guidance `pagination`, and the `list` action | `skip_total=true` / `total=false`, `add_total`, `boundary_cursors`, `cursor_column`, `direction`. The actual `per_page_max` and `limit_max` values |
| C3 | The `list` action of each endpoint | `search: { enabled, columns }` from the table's `searchable`, including `relationship.column` entries. Omitted when the table has no searchable columns |
| C4 | Bulk actions | `async`: `?async=true` or the `X-Async-Process: 1` header → 202 with `status: queued`. `maxItems` set to the actual `record.bulk_max` |
| C5 | Guidance `rateLimits`, and each action | The throttle group each action is under (`api-reads`, `api-writes`, `api-functions`) with its configured limit, and the rule "on 429, wait `Retry-After` seconds" |
| C6 | The attachments view RPC `querySchema` | `w`, `h`, `fit`, `format`, `size_name`, with the configured bounds and formats. Present only when `attachments.read_resizing` is on |
| C7 | Guidance `docs` | The real URLs of `docs/openapi.json` and `docs/llms.txt`, as a fallback for anything the tools don't cover |
| C8 | Guidance `realtime`, only when `record.broadcast_events` is on | The private channel `tenant.{tenantId}` (for example `Echo.private('tenant.42')`), the event name `{table}.{action}`, the list of broadcasting tables, and that the client needs the app's broadcasting auth |
| C9 | Each field | The derived rules: type, `nullable`, `maxLength` and `enum` where the column config defines them. Each endpoint's `validation` states that these apply even with no custom validator |

**Performance recommendations (P)**

The guidance gains a `recommendations` list. Each entry is a rule and a reason,
and every number is read from config at request time:

1. **Columns:** select only the columns you need; avoid `select=*` on wide
   tables.
2. **Includes:** each include adds queries, and a nested include switches the
   loader to batched queries. Keep nesting to two levels or fewer. Filter with
   relationship filters (`items.qty=gt.1`) instead of including and filtering
   yourself.
3. **Top-N:** use `limit` (no count query) for top-N and previews.
4. **Totals:** use `skip_total=true` when paginating without needing totals.
5. **Deep reads:** use a cursor for deep or sequential traversal; page offsets
   slow down as they grow.
6. **Several writes:** use the bulk endpoints for more than one write — up to
   `bulk_max` items in one transaction — and `async` for large batches.
7. **Create-or-update:** use `upsert` with `match_on` instead of reading and
   then writing.
8. **Large text columns:** prefer `fts` (PostgreSQL) or the table's `search`
   over `like` / `contains`.
9. **Rate limits:** stay within the read, write and function limits (C5).
   Batch instead of looping, and back off on 429.
10. **Caching:** reads may be served from the cache, and writes clear it
    automatically. Never add cache-busting parameters.

Entries that don't apply are omitted, for example rule 8 on non-PostgreSQL
drivers and rule 6's `async` when no queue is configured.

**Size**

- `content[0].text` carries compact JSON (no pretty-printing), identical in
  value to `structuredContent`. For the review fixture this cuts the text copy
  from about 45 KB to about 17 KB, and the response from about 62 KB to about
  34 KB.
- Shared reference material — the operator catalogue (C1), pagination,
  errors, rate limits and recommendations — lives once in
  `sp_api_get_api_guidance`. Each endpoint carries only what is specific to
  it.
- `sp_api_get_endpoint` gains an optional `actions` argument (a list of
  action names) to return a subset. With it omitted, all actions are returned,
  as today.

### 6.7 Nested-write authorization

Every nested child operation is authorised as if it were a direct request on
the child table. This applies to HTTP, the Data MCP and bulk endpoints, since
they all reach `RelationshipResolverUtils`.

| Child operation | Required |
|---|---|
| Create a child row (hasMany, morphMany, or a new related row in belongsToMany / hasManyThrough) | Child table `canCreate`, and the child's create permission (`PermissionUtils::mapPermissions($child, 'create')`) |
| Update a child row | Child `canUpdate`, and its update permission |
| Delete a child row | Child `canDelete`, and its delete permission |
| Attach or detach an existing related row; update pivot fields | Already covered by the parent's update permission. The attach visibility check (0.5.03) still applies |

- Super admins (`super_admin_callback`) and public child actions
  (`isPublicAction`) pass, exactly as on a direct request.
- A refused item fails the whole request with **403 `Forbidden`**, and the
  transaction rolls back. For a disabled `can*` flag the response is **422**,
  naming the relationship and the operation, matching the existing
  `allow*` refusal message.
- The check sits in one helper, `NestedWriteAuthorizer::authorizeChild()`,
  called from `processRelatedData()`, `processBelongsToManyOperation()` and
  `processHasManyThroughOperation()`. The commented-out block is removed.
- It is enforced only inside `NestedWriteAuthorizer::enforce()`, which the
  untrusted entry points open: the controllers' `withinTransaction()`, mixed
  bulk, `ProcessBulkOperationJob` and the Data MCP. Trusted app code — direct
  `RecordService` calls, triggers, post-write hooks and record event listeners
  (run through `NestedWriteAuthorizer::trusted()`) — keeps today's behaviour,
  just as it never authorises the parent. A new untrusted entry point must
  open the scope.
- The async bulk job starts from no user before it restores its own, so it
  never authorises children as the previous job's user.

This is a behaviour change for apps whose users held only the parent's
permission and wrote children through it: those writes now fail. That's the
intent of the fix. Whether to offer a temporary opt-out is Q6.

### 6.8 Configuration summary

| Key | Default | New? |
|---|---|---|
| `record.mcp.enabled` | `false` | — |
| `record.mcp.read_only` | `true` | — |
| `record.mcp.route_prefix` | `mcp` | **Deprecated, no effect** (it never moved the routes; see §5.3) |
| `record.mcp.middleware` | `['api', 'auth:sanctum']` | — |
| `record.mcp.driver` | `legacy` | **new** |
| `record.mcp.oauth` | `false` | **new** |
| `sp-api-mcp.enabled` / `sp-api-mcp.token` | `false` / `null` | — |

`composer.json`: add `laravel/mcp` to `suggest` and to `require-dev` (so the
test suite covers both drivers). It is not added to `require`.

## 7. Compatibility

### 7.1 Client URL contract

Paths below use the default `api/v1` prefix.

| URL | Today | After, `legacy` (default) | After, `laravel` (opt-in) |
|---|---|---|---|
| `POST /api/v1/mcp/schema` | Schema MCP | **Unchanged** | Same path, token and 401 body. Served by `SchemaServer` |
| `POST /api/v1/mcp/message` | Data MCP | **Unchanged** | Same path, middleware and error codes. Served by `DataServer` |
| `POST /api/v1/mcp` | — (405, reserved segment) | — | **New** Streamable HTTP endpoint |
| `GET /api/v1/mcp/sse` | Hangs and advertises a URL that 404s (`/mcp/message`, missing `/api/v1`); never worked | `405 Allow: POST` | `405 Allow: POST` |
| `GET /api/v1/mcp/schema` or `/message` | 405 | 405 | 405 |
| `php artisan sp-laravel-api:mcp --tenant=` | stdio | **Unchanged** | Same command and options |

No client has to change its URL, headers or token on either driver. The only
change on an existing URL is the SSE endpoint, which had no working client.

### 7.2 Behaviour per audience

| Who | What changes |
|---|---|
| Apps that don't opt in (`legacy`) | The SSE endpoint returns 405 instead of hanging. `tools/list` gains `title` and `annotations` keys, which are additive. The Schema MCP's guidance and payload schemas are corrected and extended (§6.6) |
| Every app (HTTP and MCP) | Nested child writes need the child table's own permission and `can*` flag (§6.7). Bare IDs in a many-to-many nested array now attach; in a hasMany array they return 422 (W1). Both are fixes for writes that were silently wrong |
| Apps that opt in (`laravel`) | Same URLs, error codes and result fields. `initialize` negotiates the protocol version (a client asking only for `2024-11-05` or `2025-03-26` is answered `2025-11-25`), `ping` works, notifications return 202 instead of 204, a JSON-RPC error is sent with an HTTP 4xx/5xx status instead of 200 (body unchanged), `tools/list` may hide tools the user can't use (§6.3), and a catalog of more than 500 tools pages. Tool names, schemas and results are unchanged |
| Apps on Laravel 12.0–12.41.0 | Cannot install `laravel/mcp`, so they stay on `legacy`. Documented |
| Custom code calling `McpServerService` | Public methods and wire output are unchanged. It is now a thin adapter |

## 8. Delivery phases

Each phase ships green on its own.

S. **Security fix, shipped first as its own patch release (§6.7).** It touches
   no MCP code, so it doesn't wait for the spike.
0. **Spike (throwaway).** In a scratch Testbench app with `laravel/mcp`,
   confirm:
   - `Mcp::web()` registered inside the package's prefixed route group;
   - a `CatalogTool` instance with overridden `toArray()` listed and called;
   - an existing `McpTenantIsolationTest` request (plain `postJson`, no MCP
     headers) answered with JSON, not SSE;
   - stdio through the existing command;
   - `shouldRegister()` resolving the authenticated user;
   - a `JsonRpcException` thrown from a tool reaching the client with its
     original code (`-32001` / `-32002`), as §6.4 requires;
   - `POST /api/v1/mcp/schema` and `/api/v1/mcp/message` producing the same
     results on both drivers for the existing test requests.

   If any of these fails, stop and revise this spec before phase 1.

   **Phase 0 result (2026-10-02, laravel/mcp v1.0.1, Laravel 13.13, PHP 8.4).**
   All criteria pass, with three corrections now folded into §3, §5.1 and §6.4:
   - `Mcp::web()` inside the package's `prefix('api')` + `['api','request.id']`
     group answers plain JSON at `/api/mcp`, `/api/mcp/message` and
     `/api/mcp/schema`; `GET`/`DELETE` → `405 Allow: POST`; a notification →
     `202`; `ping` answers.
   - A `Tool` subclass overriding `toArray()` is listed with its union-type
     `inputSchema`, `outputSchema`, `annotations` and `title`;
     `shouldRegister()` sees the authenticated user.
   - Over a non-HTTP transport the tool reads the request's
     `resolved_tenant_id` attribute and the guard user, as stdio needs.
   - **Correction 1:** the stock `tools/call` turns thrown errors into
     `isError` results; `-32001`/`-32002` only survive through a custom
     `tools/call` method (verified).
   - **Correction 2:** `initialize` negotiates only `2025-11-25` and
     `2025-06-18`.
   - **Correction 3:** `McpServiceProvider` must be registered or the tool's
     arguments are empty.
1. **Extract the core.** `ToolCatalog`, `ToolExecutor`, `ToolDefinition`,
   `ToolResult` and `ToolError`, with `McpServerService` reduced to an adapter.
   Add titles and annotations, the SSE 405, the `route_prefix` fix and
   `VerifySchemaMcpToken`. The existing MCP suites pass unchanged, apart from
   the additive keys.
2. **`laravel` driver.** `DataServer`, `SchemaServer`, `CatalogTool`,
   `CatalogResource`, driver selection, routes and the stdio switch-over.
3. **Agent guidance content (§6.6):** W1–W6, M1–M5, compact text and the
   `actions` argument. Both drivers get it through `ToolCatalog`.

   **Phase 3 result (2026-10-02).** All of §6.6 is implemented; where the
   measured size forced a choice the implementation differs from the text above
   in these ways:
   - The endpoint is read through JSON references: the first copy of a
     schema that repeats across actions (and an operator list shared by
     same-typed fields) stays inline, later copies are `{"$ref": "#/…"}`; with
     `actions` set the first requested action keeps it inline. Without this the
     `invoices` fixture was 46 KB, and 17 KB for `["list","create"]`; with it,
     27 KB and 12 KB (budgets: 40 KB / 12 KB).
   - `includes[]` carries `payloadHint` (the example JSON is inside it) but no
     separate `payloadExample`, and no `childPermissions` list (the guidance
     rule plus the child endpoint's own `permissions` say it).
   - `headers` is left out of an action that needs none; `rateLimit` omits
     `perSeconds` for a one-minute window; `list.pagination` is
     `{limit_max, per_page_max}` and the parameter names live in the guidance;
     `validation.defaults` is `{enabled, note}` with the explanation once in the
     guidance.
   - A missing tenant header is answered `422` with `error_code` 10004 and
     `errors: {"X-Tenant-ID": […]}` (the code table was checked against real
     responses; nothing emits the documented 10001).
   - Response record schemas no longer carry `additionalProperties: false`
     (a response includes selected relationships).
   - `Utilities\FilterOperatorCatalog` is the operator map; the engine's
     driver gate reads it instead of per-call lists.
4. **OAuth opt-in and tool visibility (§6.3).**
5. **Docs and changelog.** Rewrite `docs/guide/modules/module-mcp.md`: the
   drivers, URLs, client configuration for Claude Code, Cursor, a claude.ai
   connector and ChatGPT, `mcp:inspector` usage, and the upgrade notes.

## 9. Verification

- **Both drivers:** run every existing MCP suite on both drivers through a
  driver data provider: `McpTenantIsolationTest`, `McpStructuredOutputTest`,
  `McpHttpControllerTest`, `McpServerCommandTest`, `McpStdioTenantOptionTest`,
  `McpSchemaEndpointCoverageTest`, `McpEndpointRelationshipSchemaTest`, plus
  the MCP cases in `OwnRecords*`.
- **Protocol conformance (`laravel` driver):**
  - `initialize` with `2025-06-18` echoes `2025-06-18`, and a request for
    `2024-11-05` is answered `2025-11-25` (documented caveat);
  - `ping` → a success result (`laravel/mcp` adds `resultType` and `_meta`);
  - a notification → 202;
  - `GET` → 405;
  - `tools/list` carries `title` and `annotations`, returns a typical catalog
    in one page and pages past 500 tools.
- **Unit tests:** use `laravel/mcp` helpers, for example
  `DataServer::actingAs($user)->tool(CatalogTool …)->assertOk()`, and
  `assertHasErrors()` for Forbidden and for a tenant refused.
- **Tool visibility:** user without `delete:widget` → `delete_widgets` is
  absent from `tools/list`, and is still Forbidden if called.
- **Schema token:** the wrong token → 401 on both drivers; no token outside
  `local` → 401.
- **Follow the hint:** for every writable include type, parse the advertised
  `payloadHint` example, send it to the real API and assert the database
  changed as described (W1). The same for the guidance's nested-write
  examples.
- **Content assertions:**
  - the upload RPC's guidance says multipart (W3);
  - `bigInteger` columns are `integer` (W5);
  - belongsToMany `table` is the related table and resolves through
    `sp_api_get_endpoint` (W6);
  - create payloads include writable aliases, exclude system columns, and
    list `required` (W4);
  - `headers` names the tenant header exactly when the table is tenant-scoped
    (M1);
  - module blocks appear only for enabled modules (M5).
- **Size budget:** for the review fixture (`invoices`, 10 columns, 3
  includes), the full `sp_api_get_endpoint` response stays under 40 KB, and
  under 12 KB with `actions: ["list", "create"]`. `content[0].text` equals the
  compact JSON of `structuredContent`.
- **Operators (C1):**
  - for every column type in the fixture, each operator advertised for that
    field is used in a real `list` request, and the request returns 200 with
    the documented semantics;
  - any operator not advertised is rejected or ignored as it is today;
  - PostgreSQL-only operators appear only under the pgsql driver.
- **Driven by config (C2–C8, P):**
  - changing `per_page_max`, `bulk_max`, a throttle limit, the tenant header
    or the rpc prefix changes the guidance accordingly;
  - `search`, resizing, `realtime` and `async` appear only when their feature
    is configured.
- **Nested-write authorization (§6.7):** with no child permission, nested
  create, update and delete each return 403 and change nothing. With
  `can*: false`, 422. A super admin passes. With the child permission
  granted, all three succeed. Attaching an existing row needs only the
  parent's permission.
- **Gate:** `composer test`, `phpstan`, `rector --dry-run`,
  `php bin/validate-docs.php`.

## 10. Review focus

1. A plain JSON-RPC client with no MCP headers must get the same results and
   error codes on `/api/v1/mcp/message` and `/api/v1/mcp/schema` from both
   drivers (§7.1).
2. Tenant refusal must stay a refusal on the `laravel` driver: no path through
   `CatalogTool` may skip `ToolExecutor`'s tenant check.
3. `shouldRegister()` runs per tool on every `tools/list`, so permission
   lookups must hit the cached per-user permission set, with no N database
   queries.
4. `toArray()` overrides must keep emitting `outputSchema`, or
   `structuredContent` loses its contract.
5. With stdio there is no HTTP request: `--tenant` and the console user must
   reach `ToolExecutor`.
6. §6.7 must cover every path into a child write: hasMany, morphMany,
   belongsToMany new rows, hasManyThrough targets, bulk endpoints, and the
   Data MCP.
7. Recipes and headers in §6.6 come from live config. A renamed route prefix,
   tenant header or rpc prefix must change the guidance with it.
8. Advertised operators must equal what the filter engine accepts for that
   column type and driver — no more and no fewer (C1). One shared map, never
   two lists.
9. Recommendations (P) must be true for this app: no `fts` advice on MySQL, no
   `async` advice without a queue, and real limits rather than defaults.

## 11. Risks

| Risk | Mitigation |
|---|---|
| `laravel/mcp` 1.x internals that `CatalogTool` relies on (`toArray`, `ServerContext::tools()` accepting instances) change in a later 1.x | Pin `^1.0.1`. Phase 0 spike plus conformance tests in CI catch changes |
| Two drivers to maintain | The core is shared and only the adapters differ. `legacy` is removed in the next minor release (Q4) |
| A large table count gives a long `tools/list` (5 per table) | One page up to 500 tools, then `nextCursor` paging on the `laravel` driver. `ToolSearch` stays a follow-up (Q5) |
| §6.7 breaks apps that relied on writing children through the parent's permission | Release notes name the change and the permissions to grant. Q6 decides on an opt-out |
| The new content (C1–C9, P) grows the responses again | Shared reference material is emitted once, in the guidance tool. Per-endpoint additions are short. The size-budget tests fail the build if the budget is exceeded |
| Corrected payload schemas change what strict agents send (W4) | The changes only tighten the schema toward what the API already accepts. Covered by the follow-the-hint tests |

## 12. Open questions

| # | Question | Proposed answer |
|---|---|---|
| Q1 | ~~Default driver~~ | **Resolved:** `legacy` by default, with `laravel` as an explicit opt-in. `laravel/boost` pulls in `laravel/mcp` in dev only, so auto-detection would make dev and prod behave differently (§5.2) |
| Q2 | Hide tools the caller cannot use (§6.3)? | Yes, on the `laravel` driver only |
| Q3 | Include OAuth (Passport) in this cycle? | Yes, opt-in, phase 3. It's what makes claude.ai and ChatGPT connectors work |
| Q4 | When is `legacy` removed? | 0.6.0, with a deprecation notice from this release |
| Q5 | Adopt `ToolSearch` for apps with many tables? | Not now. Revisit if `tools/list` size becomes a problem |
| Q6 | Offer a temporary opt-out for §6.7 (for example `record.nested_writes.authorize_children`, default `true`)? | No. It's a security fix; opting out would reopen the bypass. Name the change in the release notes instead |
| Q7 | Bare scalars in a nested array: attach in many-to-many / hasManyThrough, 422 in hasMany (W1)? | Yes. This keeps the old hint shape working where it is meaningful, and turns the silent no-op into an error where it isn't |
