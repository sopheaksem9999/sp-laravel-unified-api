# Architecture

## MCP (Model Context Protocol)

The package provides two MCP endpoints via a single `McpServerService`:

### Schema MCP — `POST /api/v1/mcp/schema` (route: `api_schema_mcp`)
- **Controller**: `ApiSchemaMcpController` → `McpServerService(schemaOnly: true)`
- **Auth**: `SP_API_MCP_TOKEN` Bearer token (config: `sp-api-mcp.*`)
- **Tools**: 3 schema discovery tools only — no data access
  - `sp_api_list_endpoints` — list all API routes
  - `sp_api_get_endpoint` — full schema for one endpoint
  - `sp_api_list_permissions` — all permissions
- **Security**: Token optional in local, required in production

### Data MCP — `POST /mcp/message` (route: `mcp.message`)
- **Controller**: `McpHttpController` → `McpServerService(schemaOnly: false)`
- **Auth**: User Bearer token via middleware (config: `record.mcp.*`)
- **Tools**: 3 schema tools + CRUD per table (`list_{table}`, `read_{table}`, `create_{table}`, etc.)

### Key design points
- `McpServerService::handleToolsList()` always includes 3 schema tools; CRUD tools only when `!$this->schemaOnly`
- `McpServerService::handleToolsCall()` routes schema tool names first, rejects data tools when `$this->schemaOnly`
- Schema data is read live from `SchemaRegistryUtils::get()`, `RecordConfigService`, and `PermissionUtils` — always fresh

## API Client Exporters (Bruno / Postman)

Two Artisan commands turn the OpenAPI spec into ready-to-use API client collections with diff-aware updates.

### Commands
- `sp-laravel-api:export-bruno` → `api-clients/bruno/collection.bru` (Bruno v3)
- `sp-laravel-api:export-postman` → `api-clients/postman/collection.json` (Postman v2.1)

Both commands are thin shells over `AbstractExportCommand` (the only differences are the emitter, default output path, and format name).

### Pipeline
1. `SchemaRegistryUtils::refresh()` — ensure the cached table list is current
2. `OpenApiService::generateInternal()` — produces the live OpenAPI spec
3. `ApiClientExportService::build(spec, existing, regenKeys, emitter)` — produces an `ExportResult` (4 buckets: `added`, `regenerated`, `skipped`, `suggestions`)
4. `ApiClientEmitterInterface::render(ExportResult)` — converts the result to the target format's on-disk representation
5. `json_encode(...)` → `file_put_contents(...)`

### Key design points
- **Diff-aware**: the service asks each emitter to enumerate the request names already on disk via `extractRequestNames(?array)`. This keeps the diff logic in the service and lets each emitter parse its own disk format.
- **Two concerns, separated**: "process this request?" (in regen set OR no `--regen` OR new) vs "regenerate?" (in regen set AND already on disk).
- **Regen key matching is case-insensitive**: `strtolower($tableKey)` is matched against the lowercased OpenAPI tag set; `'all'` is a magic value that means regenerate everything.
- **RPC tags**: anything starting with `RPC` (e.g. `RPC`, `RPC - Restore`) is normalized to a single `RPC` folder appended last in the rendered collection.
- **`select` param**: a list-endpoint relationship selector that lives in the existing OpenAPI `select` parameter. Bruno gets `enabled:false` + description; Postman omits it + adds a `Tip:` line to the description.
- **Collection vars**: `baseUrl` (from `app.url`), `apiPrefix` (from `record.api_prefix` with leading `/`), `bearerToken` (secret). Request URLs are built as `{{baseUrl}}{{apiPrefix}}{{path}}`.
- **Extensibility**: adding a new client format (Insomnia, Hoppscotch, …) is one new `ApiClientEmitterInterface` implementation + one `AbstractExportCommand` subclass. The diff logic, regen validation, and file writing are already covered by the shared base.
