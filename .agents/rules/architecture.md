---
title: "Architecture"
description: "Architecture overview: config-driven dynamic CRUD, MCP endpoints, and API client exporters."
keywords:
  - architecture
  - crud
  - record-table-type
  - mcp
  - exporters
  - bruno
  - postman
---

# Architecture

## Core: Config-Driven Dynamic CRUD

The package turns declarative table schemas into full CRUD APIs at runtime.

- **Table schemas** are `RecordTableType` objects defined in `config/records/tables/*.php`
- **Behaviour** is controlled by `config/record.php` (API prefix, auth, tenant, permissions, MCP)
- **Orchestration**: `RecordService` performs the CRUD operations
- **Response contract**: `RecordApiResponseService` normalizes every response to a single JSON shape
- **Wiring**: `HasControllerHelpers` handles auth/tenant/validation resolution for controllers
- **Registry**: `SchemaRegistryUtils` caches the table registry for fast lookup

### Key classes
- `RecordService` — CRUD orchestration (list/read/create/update/delete/upsert)
- `RecordApiResponseService` — standardized response contract
- `HasControllerHelpers` — auth/tenant/validation wiring for controllers
- `SchemaRegistryUtils` — cached table registry
- `RecordConfigService` — config reader
- `PermissionUtils` — permission resolution

### Auth & Permission flow
`HasControllerHelpers::authorizeAction()` order:
1. Table auth check (`isAuthRead` / `isAuthWrite`)
2. User resolution
3. Per-table permission map
4. Custom authorizer
5. `Gate::forUser()->allows()`

### Tenant resolution
Request attr `resolved_tenant_id` → `record_context.tenant_id` → `X-Tenant-ID` header.

### Database support
MySQL, PostgreSQL, SQLite — always write DB-agnostic SQL; never use DB-specific functions unless wrapped.

## MCP (Model Context Protocol)

Two MCP endpoints (Data and Schema) over one transport-free core, selectable between two drivers (`record.mcp.driver`: `legacy` default, `laravel` opt-in, needs `laravel/mcp`).

### Core (`src/Mcp`, shared by both drivers)
- `ToolCatalog` — which tools exist (`schema()`, `data()`, `resources()`); titles and annotations.
- `ToolExecutor` — runs one tool call: tenant from the request → authorize → execute; throws `ToolError` (-32001 unauthenticated/unknown table, -32002 forbidden, -32601 tool not found).
- `SchemaTools` — the four `sp_api_*` tools. Content generators live in `src/Mcp/Guidance` (`ColumnTypes`, `PayloadSchemaBuilder`, `IncludeGuide`, `EndpointContext`, `ApiReference`, `ModuleRecipes`, `SchemaDeduper`). Filter operators come from `Utilities\FilterOperatorCatalog`, the same map the filter engine uses.
- `ToolDefinition`, `ToolResult` (compact JSON text copy), `ToolError`, `McpDriver`.

### Schema MCP — `POST /api/v1/mcp/schema` (route: `api_schema_mcp`)
- **Handler**: `ApiSchemaMcpController` → `McpServerService(schemaOnly: true)` (`legacy`) or `Mcp\Servers\SchemaServer` (`laravel`)
- **Auth**: `VerifySchemaMcpToken` middleware — `SP_API_MCP_TOKEN` Bearer token (config: `sp-api-mcp.*`), optional in local, required elsewhere
- **Tools**: the four `sp_api_*` tools only — no data access

### Data MCP — `POST /api/v1/mcp/message` (route: `mcp.message`), `POST /api/v1/mcp` on the `laravel` driver (route: `mcp.http`)
- **Handler**: `McpHttpController` → `McpServerService` (`legacy`) or `Mcp\Servers\DataServer` (`laravel`)
- **Auth**: user Bearer token via `record.mcp.middleware`; `record.mcp.oauth` adds Passport/OAuth discovery (`laravel` driver)
- **Tools**: the four schema tools + CRUD per table (`list_{table}`, `read_{table}`, `create_{table}`, …)

### Key design points
- `McpServerService` is a thin JSON-RPC adapter over `ToolCatalog`/`ToolExecutor`; the `laravel` driver wraps each `ToolDefinition` in `Mcp\Tools\CatalogTool` and registers its own `tools/call` (`CatalogCallTool`) so `-32001`/`-32002` survive.
- Tenant comes from the request, never from tool arguments; nested writes are authorized per child table (`NestedWriteAuthorizer`).
- Schema data is read live from `SchemaRegistryUtils::get()`, `RecordConfigService` and `PermissionUtils`; guidance numbers (limits, headers, prefixes) are read from config at request time.
- Tests: `SP_MCP_DRIVER=laravel vendor/bin/phpunit --filter Mcp` runs the MCP suites on the `laravel` driver; stdio tests use `vendor/bin/testbench` via `testbench.yaml`.

## AI SDK record tools (`src/Ai`, needs `laravel/ai`)

`Sopheak\Core\Ai\RecordTools::for()/readOnly()/schema()` return a `RecordToolSet` of `RecordTool`s (one per `ToolDefinition`) for a Laravel AI SDK agent's `tools()`. They are a third adapter over the MCP core: `RecordTool::handle()` always ends in `ToolExecutor::call()`, optionally with a `Mcp\ToolContext` (user + tenant for one call, always restored; serialises the user's key) for queued agents. Writes request approval by default (`InteractsWithApprovals`). Free-form `payload`/`queryParams` are declared as JSON-text parameters (`SchemaConverter`) because providers cannot express an open object; `RecordTool::handle()` decodes them, answers wrong shapes with an `{"error"}` string, and reports + rethrows unexpected failures with a sanitised message. `record.mcp.read_only` does not apply (`ToolExecutor(honourReadOnly: false)`, `ToolCatalog::data(readOnly: false)`). Nothing in `src/Ai` loads without `laravel/ai`; AI tests use `tests/Concerns/UsesLaravelAi` and skip themselves when it is missing. See `docs/guide/modules/module-ai-sdk.md`.

## API Client Exporters (Bruno / Postman)

Two Artisan commands turn the OpenAPI spec into ready-to-use API client collections with diff-aware updates.

### Commands
- `sp-laravel-api:export-bruno` → `api-client/bruno` (Bruno collection folder with sub-folders for each table)
- `sp-laravel-api:export-postman` → `api-client/postman/collection.json` (Postman v2.1)

Both commands are thin shells over `AbstractExportCommand` (the only differences are the emitter, default output path, and format name).

### Pipeline
1. `SchemaRegistryUtils::refresh()` — ensure the cached table list is current
2. `OpenApiService::generateInternal()` — produces the live OpenAPI spec
3. `ApiClientExportService::build(spec, existing, regenKeys, emitter)` — produces an `ExportResult` (4 buckets: `added`, `regenerated`, `skipped`, `suggestions`)
4. `ApiClientEmitterInterface::render(ExportResult)` — converts the result to the target format's on-disk representation (multi-file map for Bruno, single JSON array for Postman)
5. Write files to output folder/path on disk

### Key design points
- **Diff-aware**: the service asks each emitter to enumerate the request names already on disk via `extractRequestNames(?array)`. This keeps the diff logic in the service and lets each emitter parse its own disk format.
- **Two concerns, separated**: "process this request?" (in regen set OR no `--regen` OR new) vs "regenerate?" (in regen set AND already on disk).
- **Regen key matching is case-insensitive**: `strtolower($tableKey)` is matched against the lowercased OpenAPI tag set; `'all'` is a magic value that means regenerate everything.
- **RPC tags**: anything starting with `RPC` (e.g. `RPC`, `RPC - Restore`) is normalized to a single `RPC` folder appended last in the rendered collection.
- **`select` param**: a list-endpoint relationship selector that lives in the existing OpenAPI `select` parameter. Bruno gets `enabled:false` + description; Postman omits it + adds a `Tip:` line to the description.
- **Collection vars**: `baseUrl` (from `app.url`), `apiPrefix` (from `record.api_prefix` with leading `/`), `bearerToken` (secret). Request URLs are built as `{{baseUrl}}{{apiPrefix}}{{path}}`.
- **Extensibility**: adding a new client format (Insomnia, Hoppscotch, …) is one new `ApiClientEmitterInterface` implementation + one `AbstractExportCommand` subclass. The diff logic, regen validation, and file writing are already covered by the shared base.
